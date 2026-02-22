<?php
// app/Services/AgentPaymentService.php

namespace App\Services;

use App\Models\AgentPayment;
use App\Models\SupplierPurchaseOrder;
use App\Models\ActivityLog;
use App\Models\PaymentReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AgentPaymentService
{
    // ─────────────────────────────────────────
    // READ
    // ─────────────────────────────────────────

    public function getAll(array $filters = [])
    {
        $query = AgentPayment::with([
            'company:company_id,company_name,company_code',
            'supplier:supplier_id,supplier_name,email,phone',
            'supplierPurchaseOrder:supplier_po_id,po_number,total_amount',
            'createdBy:user_id,full_name',
'approvedBy:user_id,full_name',
        ]);

        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['start_due_date'])) {
            $query->where('due_date', '>=', $filters['start_due_date']);
        }
        if (!empty($filters['end_due_date'])) {
            $query->where('due_date', '<=', $filters['end_due_date']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                  ->orWhereHas('supplier', fn($q2) => $q2->where('supplier_name', 'like', "%{$search}%"))
                  ->orWhereHas('supplierPurchaseOrder', fn($q3) => $q3->where('po_number', 'like', "%{$search}%"));
            });
        }

        $query->orderBy('due_date', 'asc');

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function getById(int $id): AgentPayment
    {
        return AgentPayment::with([
            'company',
            'supplier',
            'supplierPurchaseOrder',
            'createdBy',
            'approvedBy',  // ✅ FIX
        ])->findOrFail($id);
    }

    // ─────────────────────────────────────────
    // CREATE
    // ─────────────────────────────────────────

    /**
     * Create dari Supplier PO (recommended flow)
     */
    public function createFromSupplierPO(int $supplierPoId, array $data): AgentPayment
    {
        return DB::transaction(function () use ($supplierPoId, $data) {
            $spo = SupplierPurchaseOrder::with('supplier', 'company')->findOrFail($supplierPoId);

            // ✅ FIX: Amount dihitung dari contract_value × commission_percentage
            $contractValue        = $data['contract_value'] ?? $spo->total_amount;
            $commissionPercentage = $data['commission_percentage'];
            $amount               = round($contractValue * $commissionPercentage / 100, 2);

            $dueDate       = $data['due_date'] ?? Carbon::parse($spo->order_date)->addDays(30)->toDateString();
            $paymentNumber = $this->generatePaymentNumber($spo->company_id);

            $agentPayment = AgentPayment::create([
                'company_id'            => $spo->company_id,
                'supplier_id'           => $spo->supplier_id,
                'supplier_po_id'        => $spo->supplier_po_id,
                'payment_number'        => $paymentNumber,
                'due_date'              => $dueDate,
                'contract_value'        => $contractValue,
                'commission_percentage' => $commissionPercentage,
                'amount'                => $amount,   // ✅ auto-calculated
                'paid_amount'           => 0,
                'status'                => 'pending',
                'agent_invoice_number'  => $data['agent_invoice_number'] ?? null,
                'notes'                 => $data['notes'] ?? null,
                'created_by'            => Auth::id(),
            ]);

            $this->log('create', $agentPayment->agent_payment_id,
                "Agent payment created from SPO {$spo->po_number}, amount: {$amount}");

            return $agentPayment;
        });
    }

    /**
     * Create manual (tanpa SPO)
     */
    public function createManual(array $data): AgentPayment
    {
        return DB::transaction(function () use ($data) {
            // ✅ FIX: Amount dihitung dari contract_value × commission_percentage
            $contractValue        = (float) $data['contract_value'];
            $commissionPercentage = (float) $data['commission_percentage'];
            $amount               = round($contractValue * $commissionPercentage / 100, 2);

            $paymentNumber = $this->generatePaymentNumber($data['company_id']);

            $agentPayment = AgentPayment::create([
                'company_id'            => $data['company_id'],
                'supplier_id'           => $data['supplier_id'],
                'supplier_po_id'        => $data['supplier_po_id'] ?? null,
                'payment_number'        => $paymentNumber,
                'due_date'              => $data['due_date'],
                'contract_value'        => $contractValue,
                'commission_percentage' => $commissionPercentage,
                'amount'                => $amount,   // ✅ auto-calculated
                'paid_amount'           => 0,
                'status'                => 'pending',
                'agent_invoice_number'  => $data['agent_invoice_number'] ?? null,
                'notes'                 => $data['notes'] ?? null,
                'created_by'            => Auth::id(),
            ]);

            $this->log('create', $agentPayment->agent_payment_id,
                "Manual agent payment created: {$agentPayment->payment_number}, amount: {$amount}");

            return $agentPayment;
        });
    }

    // ─────────────────────────────────────────
    // UPDATE
    // ─────────────────────────────────────────

    public function update(int $id, array $data): AgentPayment
    {
        return DB::transaction(function () use ($id, $data) {
            $agentPayment = AgentPayment::findOrFail($id);

            if ($agentPayment->status === 'paid') {
                throw new \Exception('Tidak bisa mengubah pembayaran yang sudah lunas');
            }

            // ✅ FIX: Jika contract_value atau commission_percentage berubah, recalculate amount
            $contractValue        = $data['contract_value'] ?? $agentPayment->contract_value;
            $commissionPercentage = $data['commission_percentage'] ?? $agentPayment->commission_percentage;
            $amount               = round((float) $contractValue * (float) $commissionPercentage / 100, 2);

            $agentPayment->update([
                'due_date'              => $data['due_date']              ?? $agentPayment->due_date,
                'contract_value'        => $contractValue,
                'commission_percentage' => $commissionPercentage,
                'amount'                => $amount,
                'agent_invoice_number'  => $data['agent_invoice_number']  ?? $agentPayment->agent_invoice_number,
                'notes'                 => $data['notes']                 ?? $agentPayment->notes,
            ]);

            $this->log('update', $agentPayment->agent_payment_id,
                "Agent payment updated: {$agentPayment->payment_number}");

            return $agentPayment->fresh();
        });
    }

    // ─────────────────────────────────────────
    // APPROVE (Step 26 — Manager)
    // ─────────────────────────────────────────

    /**
     * ✅ FIX: Tambah method approve sesuai alur step 26
     * Manager approve → status: approved
     */
    public function approve(int $id): AgentPayment
    {
        return DB::transaction(function () use ($id) {
            $agentPayment = AgentPayment::findOrFail($id);

            if (!$agentPayment->canBeApproved()) {
                throw new \Exception('Payment tidak bisa di-approve. Status saat ini: ' . $agentPayment->status);
            }

            $agentPayment->approve(Auth::id());

            $this->log('approve', $agentPayment->agent_payment_id,
                "Agent payment approved: {$agentPayment->payment_number}");

            return $agentPayment->fresh(['approvedBy']);
        });
    }

    // ─────────────────────────────────────────
    // RECORD PAYMENT (Step 26 — Finance transfer)
    // ─────────────────────────────────────────

    /**
     * ✅ FIX: Hanya bisa bayar jika status approved atau partial
     * Finance transfer → status: paid/partial
     */
    public function recordPayment(int $id, array $data): AgentPayment
    {
        return DB::transaction(function () use ($id, $data) {
            $agentPayment = AgentPayment::findOrFail($id);

            // ✅ FIX: Cek harus approved dulu sebelum bisa bayar
            if (!$agentPayment->canBePaid()) {
                throw new \Exception(
                    'Pembayaran harus di-approve Manager terlebih dahulu. Status saat ini: ' . $agentPayment->status
                );
            }

            $payAmount = (float) $data['pay_amount'];

            if ($payAmount <= 0) {
                throw new \Exception('Nominal pembayaran harus lebih besar dari 0');
            }

            if ($payAmount > $agentPayment->outstanding_amount) {
                throw new \Exception(
                    'Nominal pembayaran (Rp ' . number_format($payAmount) . ') ' .
                    'melebihi sisa tagihan (Rp ' . number_format($agentPayment->outstanding_amount) . ')'
                );
            }

            // ✅ FIX: Kalkulasi status SEBELUM update (bug lama ada di markAsPaid)
            $newPaidAmount  = (float) $agentPayment->paid_amount + $payAmount;
            $newOutstanding = (float) $agentPayment->amount - $newPaidAmount;
            $newStatus      = $newOutstanding <= 0.01 ? 'paid' : 'partial';

            $updateData = [
                'paid_amount'    => $newPaidAmount,
                'payment_date'   => $data['payment_date'] ?? now()->toDateString(),
                'payment_method' => $data['payment_method'],
                'bank_name'      => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'transfer_date'  => $data['transfer_date'] ?? $data['payment_date'] ?? now()->toDateString(),
                'status'         => $newStatus,
            ];

            // Handle upload bukti transfer
            if (!empty($data['transfer_proof_file'])
                && $data['transfer_proof_file'] instanceof \Illuminate\Http\UploadedFile
            ) {
                if ($agentPayment->transfer_proof_path) {
                    Storage::disk('public')->delete($agentPayment->transfer_proof_path);
                }

                $file     = $data['transfer_proof_file'];
                $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
                $path     = $file->storeAs('agent_payments', $fileName, 'public');

                $updateData['transfer_proof_path'] = $path;
            }

            $agentPayment->update($updateData);

            $this->log('pay', $agentPayment->agent_payment_id,
                "Agent payment paid: {$agentPayment->payment_number}, amount: {$payAmount}, status: {$newStatus}");

            return $agentPayment->fresh();
        });
    }

    // ─────────────────────────────────────────
    // UPLOAD FILE
    // ─────────────────────────────────────────

    public function uploadAgentInvoiceFile(int $id, \Illuminate\Http\UploadedFile $file): AgentPayment
    {
        return DB::transaction(function () use ($id, $file) {
            $agentPayment = AgentPayment::findOrFail($id);

            if ($agentPayment->agent_invoice_file_path) {
                Storage::disk('public')->delete($agentPayment->agent_invoice_file_path);
            }

            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $path     = $file->storeAs('agent_invoices', $fileName, 'public');

            $agentPayment->update(['agent_invoice_file_path' => $path]);

            $this->log('upload', $agentPayment->agent_payment_id,
                "Agent invoice file uploaded: {$agentPayment->payment_number}");

            return $agentPayment->fresh();
        });
    }

    // ─────────────────────────────────────────
    // DELETE
    // ─────────────────────────────────────────

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $agentPayment = AgentPayment::findOrFail($id);

            if ($agentPayment->paid_amount > 0) {
                throw new \Exception('Tidak bisa menghapus pembayaran yang sudah ada transaksi');
            }

            if ($agentPayment->status === 'approved') {
                throw new \Exception('Tidak bisa menghapus pembayaran yang sudah di-approve. Batalkan approve terlebih dahulu');
            }

            if ($agentPayment->transfer_proof_path) {
                Storage::disk('public')->delete($agentPayment->transfer_proof_path);
            }
            if ($agentPayment->agent_invoice_file_path) {
                Storage::disk('public')->delete($agentPayment->agent_invoice_file_path);
            }

            $paymentNumber = $agentPayment->payment_number;
            $agentPayment->delete();

            $this->log('delete', $id, "Agent payment deleted: {$paymentNumber}");

            return true;
        });
    }

    // ─────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────

    /**
     * ✅ FIX: Format AGPAY-{COMPANYCODE}-{YEAR}{MONTH}-{XXXXX}
     * Sebelumnya: AP/{companyCode}/{year}/{month}/{number}
     */
    private function generatePaymentNumber(int $companyId): string
    {
        $company     = \App\Models\Company::find($companyId);
        $companyCode = $company->company_code ?? 'DXM';
        $year        = date('Y');
        $month       = date('m');

        $last = AgentPayment::where('company_id', $companyId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('agent_payment_id', 'desc')
            ->first();

        $lastNumber = $last ? (int) substr($last->payment_number, -5) : 0;
        $newNumber  = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);

        // ✅ Format: AGPAY-JPM-202602-00001
        return "AGPAY-{$companyCode}-{$year}{$month}-{$newNumber}";
    }

    private function log(string $action, int $recordId, string $description): void
    {
        ActivityLog::create([
            'user_id'     => Auth::id(),
            'action'      => $action,
            'module'      => 'agent_payments',
            'record_id'   => $recordId,
            'description' => $description,
            'ip_address'  => request()->ip(),
        ]);
    }

    // ─────────────────────────────────────────
    // REMINDERS (Scheduler)
    // ─────────────────────────────────────────

    public function generateReminders(): int
    {
        $today    = Carbon::today();
        $soonDate = $today->copy()->addDays(7);
        $count    = 0;

        // Due soon reminders
        $payments = AgentPayment::whereIn('status', ['pending', 'approved', 'partial'])
            ->whereBetween('due_date', [$today, $soonDate])
            ->get();

        foreach ($payments as $payment) {
            $exists = PaymentReminder::where('reference_type', 'agent_payment')
                ->where('reference_id', $payment->agent_payment_id)
                ->where('reminder_type', 'due_soon')
                ->whereDate('reminder_date', $today)
                ->exists();

            if ($exists) continue;

            PaymentReminder::create([
                'reference_type' => 'agent_payment',
                'reference_id'   => $payment->agent_payment_id,
                'reminder_type'  => 'due_soon',
                'due_date'       => $payment->due_date,
                'reminder_date'  => $today,
                'status'         => 'pending',
                'message'        => "Pembayaran ke agent {$payment->supplier->supplier_name} jatuh tempo pada {$payment->due_date}, jumlah: " . number_format($payment->amount),
            ]);

            $count++;
        }

        // Overdue reminders
        $overduePayments = AgentPayment::whereIn('status', ['pending', 'approved', 'partial'])
            ->where('due_date', '<', $today)
            ->get();

        foreach ($overduePayments as $payment) {
            $exists = PaymentReminder::where('reference_type', 'agent_payment')
                ->where('reference_id', $payment->agent_payment_id)
                ->where('reminder_type', 'overdue')
                ->whereDate('reminder_date', $today)
                ->exists();

            if ($exists) continue;

            PaymentReminder::create([
                'reference_type' => 'agent_payment',
                'reference_id'   => $payment->agent_payment_id,
                'reminder_type'  => 'overdue',
                'due_date'       => $payment->due_date,
                'reminder_date'  => $today,
                'status'         => 'pending',
                'message'        => "PEMBAYARAN TERLAMBAT ke agent {$payment->supplier->supplier_name}, jatuh tempo: {$payment->due_date}, jumlah: " . number_format($payment->amount) . ", sisa: " . number_format($payment->outstanding_amount),
            ]);

            $payment->update(['status' => 'overdue']);
            $count++;
        }

        return $count;
    }

    public function triggerReminders(): int
    {
        $today     = Carbon::today();
        $reminders = PaymentReminder::where('status', 'pending')
            ->whereDate('reminder_date', '<=', $today)
            ->where('reference_type', 'agent_payment')
            ->with('reference')
            ->get();

        $count = 0;

        foreach ($reminders as $reminder) {
            $payment = $reminder->reference;

            if (!$payment) {
                $reminder->update(['status' => 'dismissed']);
                continue;
            }

            $reminder->markAsSent([]);

            $this->log('reminder_sent', $payment->agent_payment_id,
                "Reminder sent for agent payment: {$payment->payment_number}");

            $count++;
        }

        return $count;
    }

    // ─────────────────────────────────────────
    // STATISTICS
    // ─────────────────────────────────────────

    public function getStatistics(int $companyId): array
    {
        return [
            'total'             => AgentPayment::where('company_id', $companyId)->count(),
            'pending'           => AgentPayment::where('company_id', $companyId)->where('status', 'pending')->count(),
            'approved'          => AgentPayment::where('company_id', $companyId)->where('status', 'approved')->count(), // ✅ FIX
            'overdue'           => AgentPayment::where('company_id', $companyId)->where('status', 'overdue')->count(),
            'partial'           => AgentPayment::where('company_id', $companyId)->where('status', 'partial')->count(),
            'paid'              => AgentPayment::where('company_id', $companyId)->where('status', 'paid')->count(),
            'total_outstanding' => AgentPayment::where('company_id', $companyId)
                ->whereIn('status', ['pending', 'approved', 'overdue', 'partial'])
                ->sum(DB::raw('amount - paid_amount')),
        ];
    }
}