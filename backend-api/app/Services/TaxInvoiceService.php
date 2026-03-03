<?php
// app/Services/TaxInvoiceService.php

namespace App\Services;

use App\Models\TaxInvoice;
use App\Models\Invoice;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class TaxInvoiceService
{
    /**
     * ✅ FIXED: Get all tax invoices with filters
     */
    public function getAll(array $filters = [])
    {
        $query = TaxInvoice::with([
            'company:company_id,company_name,company_code',
            'invoice:invoice_id,invoice_number,total_amount,customer_id',
            'invoice.customer:customer_id,customer_name',
            // ✅ FIX: Use 'full_name' and 'username' instead of 'name'
            'submittedBy:user_id,full_name,username',
            'approvedBy:user_id,full_name,username',
        ]);

        // Filter by company
        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by date range
        if (!empty($filters['start_date'])) {
            $query->where('tax_invoice_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('tax_invoice_date', '<=', $filters['end_date']);
        }

        // Search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('tax_invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('invoice', function($q2) use ($search) {
                      $q2->where('invoice_number', 'like', "%{$search}%");
                  });
            });
        }

        // Order
        $query->orderBy('tax_invoice_date', 'desc');

        $result = $query->paginate($filters['per_page'] ?? 15);

        // ✅ Transform: Add 'name' attribute to user relations for compatibility
        $result->getCollection()->transform(function ($item) {
            if ($item->submittedBy) {
                $item->submittedBy->name = $item->submittedBy->full_name ?: $item->submittedBy->username;
            }
            if ($item->approvedBy) {
                $item->approvedBy->name = $item->approvedBy->full_name ?: $item->approvedBy->username;
            }
            return $item;
        });

        return $result;
    }


    /**
     * ✅ FIXED: Get single tax invoice by ID
     */
    public function getById(int $taxInvoiceId)
    {
        $taxInvoice = TaxInvoice::with([
            'company',
            'invoice.customer',
            'invoice.items.product',
            // ✅ FIX: Use 'full_name' and 'username'
            'submittedBy:user_id,full_name,username',
            'approvedBy:user_id,full_name,username',
            'createdBy:user_id,full_name,username',
        ])->findOrFail($taxInvoiceId);

        // ✅ Add 'name' attribute for compatibility
        if ($taxInvoice->submittedBy) {
            $taxInvoice->submittedBy->name = $taxInvoice->submittedBy->full_name ?: $taxInvoice->submittedBy->username;
        }
        if ($taxInvoice->approvedBy) {
            $taxInvoice->approvedBy->name = $taxInvoice->approvedBy->full_name ?: $taxInvoice->approvedBy->username;
        }
        if ($taxInvoice->createdBy) {
            $taxInvoice->createdBy->name = $taxInvoice->createdBy->full_name ?: $taxInvoice->createdBy->username;
        }

        return $taxInvoice;
    }

    /**
     * Create new tax invoice
     */
    public function create(array $data, ?UploadedFile $file = null): TaxInvoice
    {
        return DB::transaction(function () use ($data, $file) {
            // Get invoice to calculate tax
            $invoice = Invoice::findOrFail($data['invoice_id']);

            // Calculate DPP and Tax Amount
            $dppAmount = $data['dpp_amount'] ?? $invoice->subtotal;
            $taxRate = $data['tax_rate'] ?? 11.00;
            $taxAmount = $dppAmount * ($taxRate / 100);

            // Generate tax invoice number
            $taxInvoiceNumber = $data['tax_invoice_number'] ?? $this->generateTaxInvoiceNumber($data['company_id']);

            // Upload file if exists
            $filePath = null;
            if ($file) {
                $filePath = $this->uploadFile($file, 'tax_invoices');
            }

            // Create tax invoice
            $taxInvoice = TaxInvoice::create([
                'company_id' => $data['company_id'],
                'invoice_id' => $data['invoice_id'],
                'tax_invoice_number' => $taxInvoiceNumber,
                'tax_invoice_date' => $data['tax_invoice_date'],
                'tax_type' => $data['tax_type'] ?? 'ppn',
                'dpp_amount' => $dppAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'status' => 'draft',
                'file_path' => $filePath,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'tax_invoices',
                'record_id' => $taxInvoice->tax_invoice_id,
                'description' => "Tax Invoice created: {$taxInvoice->tax_invoice_number}",
                'ip_address' => request()->ip(),
            ]);

            return $taxInvoice;
        });
    }

    /**
     * Update tax invoice
     */
    public function update(int $taxInvoiceId, array $data, ?UploadedFile $file = null): TaxInvoice
    {
        return DB::transaction(function () use ($taxInvoiceId, $data, $file) {
            $taxInvoice = TaxInvoice::findOrFail($taxInvoiceId);

            // Check if can edit
            if (!$taxInvoice->canEdit()) {
                throw new \Exception('Cannot edit tax invoice with status: ' . $taxInvoice->status);
            }

            // Recalculate if DPP or rate changed
            if (isset($data['dpp_amount']) || isset($data['tax_rate'])) {
                $dppAmount = $data['dpp_amount'] ?? $taxInvoice->dpp_amount;
                $taxRate = $data['tax_rate'] ?? $taxInvoice->tax_rate;
                $data['tax_amount'] = $dppAmount * ($taxRate / 100);
            }

            // Upload new file if exists
            if ($file) {
                // Delete old file
                if ($taxInvoice->file_path) {
                    Storage::disk('public')->delete($taxInvoice->file_path);
                }
                $data['file_path'] = $this->uploadFile($file, 'tax_invoices');
            }

            // Update
            $taxInvoice->update($data);

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'update',
                'module' => 'tax_invoices',
                'record_id' => $taxInvoice->tax_invoice_id,
                'description' => "Tax Invoice updated: {$taxInvoice->tax_invoice_number}",
                'ip_address' => request()->ip(),
            ]);

            return $taxInvoice->fresh();
        });
    }

    /**
     * Submit tax invoice
     */
    public function submit(int $taxInvoiceId): TaxInvoice
    {
        return DB::transaction(function () use ($taxInvoiceId) {
            $taxInvoice = TaxInvoice::findOrFail($taxInvoiceId);

            if (!$taxInvoice->canSubmit()) {
                throw new \Exception('Cannot submit tax invoice with status: ' . $taxInvoice->status);
            }

            $taxInvoice->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => Auth::id(),
            ]);

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'submit',
                'module' => 'tax_invoices',
                'record_id' => $taxInvoice->tax_invoice_id,
                'description' => "Tax Invoice submitted: {$taxInvoice->tax_invoice_number}",
                'ip_address' => request()->ip(),
            ]);

            return $taxInvoice->fresh();
        });
    }

    /**
     * Approve tax invoice
     */
  // Di TaxInvoiceService::approve — tambah setelah update status
public function approve(int $taxInvoiceId): TaxInvoice
{
    return DB::transaction(function () use ($taxInvoiceId) {
        $taxInvoice = TaxInvoice::with('invoice')->findOrFail($taxInvoiceId);

        if (!$taxInvoice->canApprove()) {
            throw new \Exception('Cannot approve tax invoice with status: ' . $taxInvoice->status);
        }

        $taxInvoice->update([
            'status'      => 'approved',
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ]);

        // ✅ TAMBAH: Update invoice updated_at agar FE bisa detect perubahan
        if ($taxInvoice->invoice) {
            $taxInvoice->invoice->touch();
        }

        ActivityLog::create([
            'user_id'     => Auth::id(),
            'action'      => 'approve',
            'module'      => 'tax_invoices',
            'record_id'   => $taxInvoice->tax_invoice_id,
            'description' => "Tax Invoice approved: {$taxInvoice->tax_invoice_number}",
            'ip_address'  => request()->ip(),
        ]);

        return $taxInvoice->fresh(['invoice']);
    });
}

    /**
     * Reject tax invoice
     */
    public function reject(int $taxInvoiceId, string $reason): TaxInvoice
    {
        return DB::transaction(function () use ($taxInvoiceId, $reason) {
            $taxInvoice = TaxInvoice::findOrFail($taxInvoiceId);

            $taxInvoice->update([
                'status' => 'rejected',
                'notes' => ($taxInvoice->notes ? $taxInvoice->notes . "\n\n" : '') . "Rejected: {$reason}",
            ]);

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'reject',
                'module' => 'tax_invoices',
                'record_id' => $taxInvoice->tax_invoice_id,
                'description' => "Tax Invoice rejected: {$taxInvoice->tax_invoice_number}. Reason: {$reason}",
                'ip_address' => request()->ip(),
            ]);

            return $taxInvoice->fresh();
        });
    }

    /**
     * Delete tax invoice
     */
    public function delete(int $taxInvoiceId): bool
    {
        return DB::transaction(function () use ($taxInvoiceId) {
            $taxInvoice = TaxInvoice::findOrFail($taxInvoiceId);

            // Only draft can be deleted
            if ($taxInvoice->status !== 'draft') {
                throw new \Exception('Only draft tax invoice can be deleted');
            }

            // Delete file
            if ($taxInvoice->file_path) {
                Storage::disk('public')->delete($taxInvoice->file_path);
            }

            $taxInvoiceNumber = $taxInvoice->tax_invoice_number;
            $taxInvoice->delete();

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'delete',
                'module' => 'tax_invoices',
                'record_id' => $taxInvoiceId,
                'description' => "Tax Invoice deleted: {$taxInvoiceNumber}",
                'ip_address' => request()->ip(),
            ]);

            return true;
        });
    }

    /**
     * Generate tax invoice number
     */
    private function generateTaxInvoiceNumber(int $companyId): string
    {
        $company = \App\Models\Company::find($companyId);
        $companyCode = $company->company_code ?? 'DXM';

        $year = date('Y');
        $month = date('m');

        // Get last number
        $lastTaxInvoice = TaxInvoice::where('company_id', $companyId)
            ->whereYear('tax_invoice_date', $year)
            ->whereMonth('tax_invoice_date', $month)
            ->orderBy('tax_invoice_id', 'desc')
            ->first();

        $lastNumber = $lastTaxInvoice
            ? (int) substr($lastTaxInvoice->tax_invoice_number, -5)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);

        return "FP/{$companyCode}/{$year}/{$month}/{$newNumber}";
    }

    /**
     * Upload file
     */
  public function uploadFile(int $taxInvoiceId, UploadedFile $file): TaxInvoice
{
    $taxInvoice = TaxInvoice::findOrFail($taxInvoiceId);

    // Hapus file lama jika ada
    if ($taxInvoice->file_path && Storage::disk('public')->exists($taxInvoice->file_path)) {
        Storage::disk('public')->delete($taxInvoice->file_path);
    }

    // Simpan file baru
    $filename  = 'faktur_pajak_' . $taxInvoice->tax_invoice_id . '_' . time() . '.' . $file->getClientOriginalExtension();
    $path      = $file->storeAs('tax_invoices', $filename, 'public');

    $taxInvoice->update([
        'file_path' => $path,
        'file_name' => $file->getClientOriginalName(),
    ]);

    return $taxInvoice->fresh();
}
    /**
     * Get statistics
     */
    public function getStatistics(int $companyId, ?string $year = null)
    {
        $year = $year ?? date('Y');

        return [
            'total' => TaxInvoice::where('company_id', $companyId)
                ->whereYear('tax_invoice_date', $year)
                ->count(),
            'draft' => TaxInvoice::where('company_id', $companyId)
                ->whereYear('tax_invoice_date', $year)
                ->where('status', 'draft')
                ->count(),
            'submitted' => TaxInvoice::where('company_id', $companyId)
                ->whereYear('tax_invoice_date', $year)
                ->where('status', 'submitted')
                ->count(),
            'approved' => TaxInvoice::where('company_id', $companyId)
                ->whereYear('tax_invoice_date', $year)
                ->where('status', 'approved')
                ->count(),
            'total_dpp' => TaxInvoice::where('company_id', $companyId)
                ->whereYear('tax_invoice_date', $year)
                ->where('status', 'approved')
                ->sum('dpp_amount'),
            'total_tax' => TaxInvoice::where('company_id', $companyId)
                ->whereYear('tax_invoice_date', $year)
                ->where('status', 'approved')
                ->sum('tax_amount'),
        ];
    }
}
