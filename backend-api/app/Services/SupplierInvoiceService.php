<?php

namespace App\Services;

use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SupplierInvoiceService
{
    // ========== SUPPLIER INVOICES ==========
    
    public function getInvoices($filters = [])
    {
        $query = SupplierInvoice::with(['supplier', 'supplierPO', 'items', 'payments', 'createdByUser']);

        // Search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('supplier', function ($q2) use ($search) {
                      $q2->where('supplier_name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by payment status
        if (!empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        // Filter by supplier
        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        // Filter by overdue
        if (!empty($filters['overdue']) && $filters['overdue'] === 'true') {
            $query->where('due_date', '<', now())
                  ->whereIn('payment_status', ['unpaid', 'partial']);
        }

        // Date range
        if (!empty($filters['date_from'])) {
            $query->where('invoice_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('invoice_date', '<=', $filters['date_to']);
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'invoice_date';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $filters['per_page'] ?? 15;
        return $query->paginate($perPage);
    }

    public function createInvoice($data, $userId)
    {
        DB::beginTransaction();
        try {
            // Generate invoice number if not provided
            if (empty($data['invoice_number'])) {
                $data['invoice_number'] = $this->generateInvoiceNumber();
            }

            // Calculate total amount
            $totalAmount = 0;
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $totalAmount += ($item['quantity'] * $item['unit_price']);
                }
            }

            // ✅ Create invoice dengan snake_case
            $invoice = SupplierInvoice::create([
                'supplier_po_id' => $data['supplier_po_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'total_amount' => $totalAmount,
                'paid_amount' => 0,
                'payment_status' => 'unpaid',
                'due_date' => $data['due_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? 'net30',
                'invoice_file_path' => $data['invoice_file_path'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Create invoice items
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    SupplierInvoiceItem::create([
                        'supplier_invoice_id' => $invoice->supplier_invoice_id,
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'unit_price' => $item['unit_price'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }

            DB::commit();
            return $invoice->load(['supplier', 'items', 'supplierPO']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating supplier invoice: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateInvoice($id, $data)
    {
        DB::beginTransaction();
        try {
            $invoice = SupplierInvoice::findOrFail($id);

            // Check if already has payments
            if ($invoice->paid_amount > 0) {
                throw new \Exception('Invoice tidak dapat diubah karena sudah ada pembayaran');
            }

            // Calculate new total amount
            $totalAmount = $invoice->total_amount;
            if (isset($data['items'])) {
                $totalAmount = 0;
                foreach ($data['items'] as $item) {
                    $totalAmount += ($item['quantity'] * $item['unit_price']);
                }
            }

            // ✅ Update invoice dengan snake_case
            $invoice->update([
                'supplier_po_id' => $data['supplier_po_id'] ?? $invoice->supplier_po_id,
                'supplier_id' => $data['supplier_id'],
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'total_amount' => $totalAmount,
                'due_date' => $data['due_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? 'net30',
                'notes' => $data['notes'] ?? null,
            ]);

            // Update items if provided
            if (isset($data['items'])) {
                // Delete existing items
                $invoice->items()->delete();

                // Create new items
                foreach ($data['items'] as $item) {
                    SupplierInvoiceItem::create([
                        'supplier_invoice_id' => $invoice->supplier_invoice_id,
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'unit_price' => $item['unit_price'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }

            DB::commit();
            return $invoice->load(['supplier', 'items', 'supplierPO']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating supplier invoice: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteInvoice($id)
    {
        DB::beginTransaction();
        try {
            $invoice = SupplierInvoice::findOrFail($id);

            // Check if has successful payments
            if ($invoice->paid_amount > 0) {
                throw new \Exception('Tidak dapat menghapus invoice yang sudah ada pembayaran');
            }

            // Delete invoice file if exists
            if ($invoice->invoice_file_path) {
                Storage::disk('public')->delete($invoice->invoice_file_path);
            }

            // Delete items first
            $invoice->items()->delete();

            // Delete invoice
            $invoice->delete();

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting supplier invoice: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getInvoice($id)
    {
        return SupplierInvoice::with([
            'supplier',
            'supplierPO',
            'items.product',
            'payments.createdByUser',
            'createdByUser'
        ])->findOrFail($id);
    }

    // ========== SUPPLIER PAYMENTS ==========

    public function getPayments($filters = [])
    {
        $query = SupplierPayment::with(['invoice.supplier', 'createdByUser', 'approvedByUser']);

        // Search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhereHas('invoice', function ($q2) use ($search) {
                      $q2->where('invoice_number', 'like', "%{$search}%")
                         ->orWhereHas('supplier', function ($q3) use ($search) {
                             $q3->where('supplier_name', 'like', "%{$search}%");
                         });
                  });
            });
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by payment type
        if (!empty($filters['payment_type'])) {
            $query->where('payment_type', $filters['payment_type']);
        }

        // Date range
        if (!empty($filters['date_from'])) {
            $query->where('payment_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('payment_date', '<=', $filters['date_to']);
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'payment_date';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $filters['per_page'] ?? 15;
        return $query->paginate($perPage);
    }

    public function createPayment($data, $userId)
    {
        DB::beginTransaction();
        try {
            // Validate invoice
            $invoice = SupplierInvoice::findOrFail($data['supplier_invoice_id']);

            // Calculate remaining amount
            $remainingAmount = $invoice->total_amount - $invoice->paid_amount;

            // Validate amount
            if ($data['amount'] > $remainingAmount) {
                throw new \Exception('Jumlah pembayaran melebihi sisa tagihan');
            }

            // Generate payment number
            $paymentNumber = $this->generatePaymentNumber();

            // Handle file upload
            $proofFilePath = null;
            if (!empty($data['proof_file'])) {
                $proofFilePath = $data['proof_file']->store('supplier_payments', 'public');
            }

            // ✅ Create payment dengan snake_case
            $payment = SupplierPayment::create([
                'supplier_invoice_id' => $data['supplier_invoice_id'],
                'payment_number' => $paymentNumber,
                'payment_type' => $data['payment_type'] ?? 'full',
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'status' => $data['status'] ?? 'pending',
                'payment_method' => $data['payment_method'] ?? 'transfer',
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'proof_file_path' => $proofFilePath,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Update invoice payment status if payment is success
            if ($payment->status === 'success') {
                $this->updateInvoicePaymentStatus($invoice, $payment->amount);
            }

            DB::commit();
            return $payment->load(['invoice.supplier', 'createdByUser']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating supplier payment: ' . $e->getMessage());
            throw $e;
        }
    }

    public function approvePayment($id, $userId)
    {
        DB::beginTransaction();
        try {
            $payment = SupplierPayment::with('invoice')->findOrFail($id);

            if ($payment->status !== 'pending') {
                throw new \Exception('Payment sudah diproses');
            }

            // ✅ Update payment dengan snake_case
            $payment->update([
                'status' => 'success',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            // Update invoice payment status
            $this->updateInvoicePaymentStatus($payment->invoice, $payment->amount);

            DB::commit();
            return $payment->load(['invoice.supplier', 'approvedByUser']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error approving payment: ' . $e->getMessage());
            throw $e;
        }
    }

    public function cancelPayment($id, $reason, $userId)
    {
        DB::beginTransaction();
        try {
            $payment = SupplierPayment::with('invoice')->findOrFail($id);

            if ($payment->status === 'cancelled') {
                throw new \Exception('Payment sudah dibatalkan');
            }

            // If payment was success, revert invoice payment status
            if ($payment->status === 'success') {
                $invoice = $payment->invoice;
                $newPaidAmount = $invoice->paid_amount - $payment->amount;
                
                $paymentStatus = 'unpaid';
                if ($newPaidAmount > 0) {
                    $paymentStatus = 'partial';
                }

                $invoice->update([
                    'paid_amount' => $newPaidAmount,
                    'payment_status' => $paymentStatus,
                ]);
            }

            // ✅ Update payment dengan snake_case
            $payment->update([
                'status' => 'cancelled',
                'notes' => ($payment->notes ?? '') . "\n[CANCELLED] " . $reason,
            ]);

            DB::commit();
            return $payment;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error cancelling payment: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deletePayment($id)
    {
        DB::beginTransaction();
        try {
            $payment = SupplierPayment::findOrFail($id);

            if ($payment->status === 'success') {
                throw new \Exception('Tidak dapat menghapus pembayaran yang sudah approved');
            }

            // Delete proof file
            if ($payment->proof_file_path) {
                Storage::disk('public')->delete($payment->proof_file_path);
            }

            $payment->delete();

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting payment: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getPayment($id)
    {
        return SupplierPayment::with([
            'invoice.supplier',
            'invoice.items',
            'createdByUser',
            'approvedByUser'
        ])->findOrFail($id);
    }

    // ========== HELPER METHODS ==========

    /**
     * Update invoice payment status
     */
    private function updateInvoicePaymentStatus($invoice, $paymentAmount)
    {
        $newPaidAmount = $invoice->paid_amount + $paymentAmount;
        $newRemainingAmount = $invoice->total_amount - $newPaidAmount;

        // Tentukan status pembayaran
        $paymentStatus = 'unpaid';
        if ($newRemainingAmount <= 0) {
            $paymentStatus = 'paid';
            $newPaidAmount = $invoice->total_amount;
        } elseif ($newPaidAmount > 0) {
            $paymentStatus = 'partial';
        }

        // ✅ Update invoice dengan snake_case
        $invoice->update([
            'paid_amount' => $newPaidAmount,
            'payment_status' => $paymentStatus,
        ]);

        return $invoice;
    }

    private function generateInvoiceNumber()
    {
        $prefix = 'SINV-' . date('Ym') . '-';
        
        $lastInvoice = SupplierInvoice::where('invoice_number', 'like', $prefix . '%')
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $newNumber;
    }

    private function generatePaymentNumber()
    {
        $prefix = 'SPAY-' . date('Ym') . '-';
        
        $lastPayment = SupplierPayment::where('payment_number', 'like', $prefix . '%')
            ->orderBy('payment_number', 'desc')
            ->first();

        if ($lastPayment) {
            $lastNumber = (int) substr($lastPayment->payment_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $newNumber;
    }

    public function getDashboardStats()
    {
        // ✅ Query dengan snake_case
        return [
            'total_unpaid' => SupplierInvoice::where('payment_status', 'unpaid')
                ->sum('total_amount'),
            'total_partial' => SupplierInvoice::where('payment_status', 'partial')
                ->sum(DB::raw('total_amount - paid_amount')),
            'overdue_count' => SupplierInvoice::where('due_date', '<', now())
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->count(),
            'pending_payments' => SupplierPayment::where('status', 'pending')
                ->sum('amount'),
            'total_paid_this_month' => SupplierPayment::where('status', 'success')
                ->whereMonth('payment_date', now()->month)
                ->whereYear('payment_date', now()->year)
                ->sum('amount'),
        ];
    }
}
