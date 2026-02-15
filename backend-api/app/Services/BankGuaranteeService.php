<?php
// app/Services/BankGuaranteeService.php

namespace App\Services;

use App\Models\BankGuarantee;
use App\Models\TenderProjectDetail;
use App\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BankGuaranteeService
{
    public function list(array $filters = [])
    {
        $query = BankGuarantee::with([
            'purchaseOrder:po_id,po_number,customer_id,company_id',
            'purchaseOrder.customer:customer_id,customer_name',
            'company:company_id,company_name,company_code',
            'createdBy:user_id,full_name,username,email',
        ]);

        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (!empty($filters['po_id'])) {
            $query->where('po_id', $filters['po_id']);
        }

        if (!empty($filters['guarantee_type'])) {
            $query->where('guarantee_type', $filters['guarantee_type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('guarantee_number', 'like', "%{$s}%")
                  ->orWhere('bank_name', 'like', "%{$s}%")
                  ->orWhereHas('purchaseOrder', function ($q2) use ($s) {
                      $q2->where('po_number', 'like', "%{$s}%");
                  });
            });
        }

        $query->orderByDesc('issue_date')->orderByDesc('guarantee_id');

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function getById(int $id): BankGuarantee
    {
        return BankGuarantee::with([
            'purchaseOrder.customer',
            'company',
            'createdBy',
        ])->findOrFail($id);
    }

    public function create(array $data, ?UploadedFile $file = null): BankGuarantee
    {
        return DB::transaction(function () use ($data, $file) {
            $po = PurchaseOrder::findOrFail($data['po_id']);

            $payload = [
                'po_id'                => $po->po_id,
                'company_id'           => $data['company_id'] ?? $po->company_id,
                'guarantee_type'       => $data['guarantee_type'],
                'bank_name'            => $data['bank_name'],
                'bank_branch'          => $data['bank_branch'] ?? null,
                'guarantee_number'     => $data['guarantee_number'],
                'guarantee_amount'     => $data['guarantee_amount'],
                'guarantee_percentage' => $data['guarantee_percentage'] ?? null,
                'issue_date'           => $data['issue_date'],
                'expiry_date'          => $data['expiry_date'],
                'return_date'          => $data['return_date'] ?? null,
                'admin_fee'            => $data['admin_fee'] ?? 0,
                'collateral_fee'       => $data['collateral_fee'] ?? 0,
                'status'               => $data['status'] ?? 'active',
                'notes'                => $data['notes'] ?? null,
                'created_by'           => Auth::id(),
            ];

            if ($file) {
                $uploaded = $this->uploadFile($file, 'bank_guarantees/' . $po->po_number);
                $payload['file_path'] = $uploaded['path'];
            }

            $bg = BankGuarantee::create($payload);

            $this->syncTenderProjectHasBG($po->po_id);

            return $bg->fresh(['purchaseOrder', 'company', 'createdBy']);
        });
    }

    public function update(int $id, array $data, ?UploadedFile $file = null): BankGuarantee
    {
        return DB::transaction(function () use ($id, $data, $file) {
            $bg = BankGuarantee::findOrFail($id);

            $update = [
                'guarantee_type'       => $data['guarantee_type']       ?? $bg->guarantee_type,
                'bank_name'            => $data['bank_name']            ?? $bg->bank_name,
                'bank_branch'          => $data['bank_branch']          ?? $bg->bank_branch,
                'guarantee_number'     => $data['guarantee_number']     ?? $bg->guarantee_number,
                'guarantee_amount'     => $data['guarantee_amount']     ?? $bg->guarantee_amount,
                'guarantee_percentage' => $data['guarantee_percentage'] ?? $bg->guarantee_percentage,
                'issue_date'           => $data['issue_date']           ?? $bg->issue_date,
                'expiry_date'          => $data['expiry_date']          ?? $bg->expiry_date,
                'return_date'          => $data['return_date']          ?? $bg->return_date,
                'admin_fee'            => $data['admin_fee']            ?? $bg->admin_fee,
                'collateral_fee'       => $data['collateral_fee']       ?? $bg->collateral_fee,
                'status'               => $data['status']               ?? $bg->status,
                'notes'                => $data['notes']                ?? $bg->notes,
            ];

            if ($file) {
                if ($bg->file_path) {
                    Storage::disk('public')->delete($bg->file_path);
                }
                $uploaded = $this->uploadFile($file, 'bank_guarantees/' . $bg->purchaseOrder->po_number);
                $update['file_path'] = $uploaded['path'];
            }

            $bg->update($update);

            $this->syncTenderProjectHasBG($bg->po_id);

            return $bg->fresh(['purchaseOrder', 'company', 'createdBy']);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $bg = BankGuarantee::findOrFail($id);
            $poId = $bg->po_id;

            if ($bg->file_path) {
                Storage::disk('public')->delete($bg->file_path);
            }

            $bg->delete();

            $this->syncTenderProjectHasBG($poId);

            return true;
        });
    }

    public function markReturned(int $id, ?string $returnDate = null): BankGuarantee
    {
        $bg = BankGuarantee::findOrFail($id);

        $bg->update([
            'status'      => 'returned',
            'return_date' => $returnDate ?? now(),
        ]);

        $this->syncTenderProjectHasBG($bg->po_id);

        return $bg->fresh();
    }

    public function download(int $id)
    {
        $bg = BankGuarantee::findOrFail($id);

        if (!$bg->file_path || !Storage::disk('public')->exists($bg->file_path)) {
            abort(404, 'File not found');
        }

        return response()->download(
            storage_path('app/public/' . $bg->file_path)
        );
    }
    protected function uploadFile(UploadedFile $file, string $folder): array
    {
        $name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $file->storeAs($folder, $name, 'public');

        return [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ];
    }

    /**
     * Sinkronisasi flag has_bank_guarantee di TenderProjectDetail
     */
    protected function syncTenderProjectHasBG(int $poId): void
    {
        $detail = TenderProjectDetail::where('po_id', $poId)->first();

        if (!$detail) {
            return;
        }

        $hasBG = BankGuarantee::where('po_id', $poId)->exists();

        $detail->update([
            'has_bank_guarantee' => $hasBG,
        ]);
    }
}
