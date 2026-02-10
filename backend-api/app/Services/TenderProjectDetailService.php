<?php
// app/Services/TenderProjectDetailService.php

namespace App\Services;

use App\Models\TenderProjectDetail;
use App\Models\PurchaseOrder;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TenderProjectDetailService
{
    public function getAll(array $filters = [])
    {
        $query = TenderProjectDetail::with([
            'purchaseOrder:po_id,po_number,customer_id,activity_type_id',
            'purchaseOrder.customer:customer_id,customer_name',
            'bankGuarantees',   // relasi ke BankGuarantee
        ]);

        if (!empty($filters['company_id'])) {
            $query->whereHas('purchaseOrder', function ($q) use ($filters) {
                $q->where('company_id', $filters['company_id']);
            });
        }

        if (!empty($filters['project_status'])) {
            $query->where('project_status', $filters['project_status']);
        }

        if (!empty($filters['has_bank_guarantee'])) {
            $has = $filters['has_bank_guarantee'] === '1';
            $query->whereHas('bankGuarantees', function ($q) use ($has) {
                if (!$has) {
                    $q->whereRaw('1 = 0');
                }
            }, $has ? '>' : '=', $has ? 0 : 0);
            if (!$has) {
                $query->doesntHave('bankGuarantees');
            }
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('contract_number', 'like', "%{$search}%")
                    ->orWhereHas('purchaseOrder', function ($q2) use ($search) {
                        $q2->where('po_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('purchaseOrder.customer', function ($q3) use ($search) {
                        $q3->where('customer_name', 'like', "%{$search}%");
                    });
            });
        }

        if (!empty($filters['start_date'])) {
            $query->where('contract_start_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('contract_end_date', '<=', $filters['end_date']);
        }

        $query->orderBy('created_at', 'desc');

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function getById(int $id): TenderProjectDetail
    {
        return TenderProjectDetail::with([
            'purchaseOrder.customer',
            'bankGuarantees',
            'documents',
        ])->findOrFail($id);
    }

    public function getByPO(int $poId): ?TenderProjectDetail
    {
        return TenderProjectDetail::with([
            'purchaseOrder.customer',
            'bankGuarantees',
            'documents',
        ])->where('po_id', $poId)->first();
    }

    public function createFromPO(int $poId, array $data = []): TenderProjectDetail
    {
        return DB::transaction(function () use ($poId, $data) {
            $po = PurchaseOrder::with('customer')->findOrFail($poId);

            $existing = TenderProjectDetail::where('po_id', $poId)->first();
            if ($existing) {
                return $existing;
            }

            $detail = TenderProjectDetail::create([
                'po_id'              => $po->po_id,
                'contract_number'    => $data['contract_number'] ?? $po->po_number,
                'contract_start_date'=> $data['contract_start_date'] ?? null,
                'contract_end_date'  => $data['contract_end_date'] ?? null,
                'contract_file_path' => $data['contract_file_path'] ?? null,
                'project_status'     => 'ongoing',
                'notes'              => $data['notes'] ?? null,
                'created_by'         =>  Auth::id(),
            ]);

            ActivityLog::create([
                'user_id'    =>  Auth::id(),
                'action'     => 'create',
                'module'     => 'tender_project_details',
                'record_id'  => $detail->detail_id,
                'description'=> "Tender project detail created from PO {$po->po_number}",
                'ip_address' => request()->ip(),
            ]);

            return $detail;
        });
    }

    public function update(int $id, array $data): TenderProjectDetail
    {
        return DB::transaction(function () use ($id, $data) {
            $detail = TenderProjectDetail::findOrFail($id);

            $detail->update([
                'contract_number'     => $data['contract_number'] ?? $detail->contract_number,
                'contract_start_date' => $data['contract_start_date'] ?? $detail->contract_start_date,
                'contract_end_date'   => $data['contract_end_date'] ?? $detail->contract_end_date,
                'notes'               => $data['notes'] ?? $detail->notes,
            ]);

            ActivityLog::create([
                'user_id'    =>  Auth::id(),
                'action'     => 'update',
                'module'     => 'tender_project_details',
                'record_id'  => $detail->detail_id,
                'description'=> "Tender project detail updated",
                'ip_address' => request()->ip(),
            ]);

            return $detail->fresh();
        });
    }

    public function getStatistics(int $companyId): array
    {
        $base = TenderProjectDetail::whereHas('purchaseOrder', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        });

        return [
            'total'     => $base->count(),
            'ongoing'   => (clone $base)->where('project_status', 'ongoing')->count(),
            'ba_done'   => (clone $base)->where('project_status', 'ba_done')->count(),
            'bahp_done' => (clone $base)->where('project_status', 'bahp_done')->count(),
            'bast_done' => (clone $base)->where('project_status', 'bast_done')->count(),
            'sp2d_done' => (clone $base)->where('project_status', 'sp2d_done')->count(),
        ];
    }
}
