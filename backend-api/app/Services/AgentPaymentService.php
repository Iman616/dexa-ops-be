<?php
// app/Services/AgentPaymentService.php

namespace App\Services;

use App\Models\AgentPayment;
use App\Models\SupplierPurchaseOrder;
use App\Models\Supplier;
use App\Models\ActivityLog;
use App\Models\PaymentReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;


class AgentPaymentService
{
    /**
     * Get all agent payments with filters
     */
    public function getAll(array $filters = [])
    {
        $query = AgentPayment::with([
            'company:company_id,company_name,company_code',
            'supplier:supplier_id,supplier_name,email,phone',
            'supplierPurchaseOrder:supplier_po_id,po_number,total_amount',
            'createdBy:user_id,name',
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
                  ->orWhereHas('supplier', function ($q2) use ($search) {
                      $q2->where('supplier_name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('supplierPurchaseOrder', function ($q3) use ($search) {
                      $q3->where('po_number', 'like', "%{$search}%");
                  });
            });
        }

        $query->orderBy('due_date', 'asc');

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get single agent payment
     */
    public function getById(int $id): AgentPayment
    {
        return AgentPayment::with([
            'company',
            'supplier',
            'supplierPurchaseOrder',
            'createdBy',
        ])->findOrFail($id);
    }

    /**
     * Create from Supplier PO (recommended flow)
     */
    public function createFromSupplierPO(int $supplierPoId, array $data): AgentPayment
    {
        return DB::transaction(function () use ($supplierPoId, $data) {
            $spo = SupplierPurchaseOrder::with('supplier', 'company')->findOrFail($supplierPoId);

            $amount = $data['amount'] ?? $spo->total_amount;
            $dueDate = $data['due_date'] ?? Carbon::parse($spo->order_date)->addDays(30)->toDateString();

            $paymentNumber = $this->generatePaymentNumber($spo->company_id);

            $agentPayment = AgentPayment::create([
                'company_id' => $spo->company_id,
                'supplier_id' => $spo->supplier_id,
                'supplier_po_id' => $spo->supplier_po_id,
                'payment_number' => $paymentNumber,
                'due_date' => $dueDate,
                'amount' => $amount,
                'paid_amount' => 0,
                'status' => 'pending',
                'agent_invoice_number' => $data['agent_invoice_number'] ?? null,
                'agent_invoice_file_path' => $data['agent_invoice_file_path'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' =>  Auth::id(),
            ]);

            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'create',
                'module' => 'agent_payments',
                'record_id' => $agentPayment->agent_payment_id,
                'description' => "Agent payment created from SPO {$spo->po_number}",
                'ip_address' => request()->ip(),
            ]);

            return $agentPayment;
        });
    }

    /**
     * Create manual agent payment (tanpa SPO)
     */
    public function createManual(array $data): AgentPayment
    {
        return DB::transaction(function () use ($data) {
            $paymentNumber = $this->generatePaymentNumber($data['company_id']);

            $agentPayment = AgentPayment::create([
                'company_id' => $data['company_id'],
                'supplier_id' => $data['supplier_id'],
                'supplier_po_id' => $data['supplier_po_id'] ?? null,
                'payment_number' => $paymentNumber,
                'due_date' => $data['due_date'],
                'amount' => $data['amount'],
                'paid_amount' => 0,
                'status' => 'pending',
                'agent_invoice_number' => $data['agent_invoice_number'] ?? null,
                'agent_invoice_file_path' => $data['agent_invoice_file_path'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' =>  Auth::id(),
            ]);

            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'create',
                'module' => 'agent_payments',
                'record_id' => $agentPayment->agent_payment_id,
                'description' => "Manual agent payment created: {$agentPayment->payment_number}",
                'ip_address' => request()->ip(),
            ]);

            return $agentPayment;
        });
    }

    /**
     * Update agent payment (non-payment fields)
     */
    public function update(int $id, array $data): AgentPayment
    {
        return DB::transaction(function () use ($id, $data) {
            $agentPayment = AgentPayment::findOrFail($id);

            // Hanya boleh update info umum kalau belum paid full
            if ($agentPayment->status === 'paid') {
                throw new \Exception('Tidak bisa mengubah pembayaran yang sudah lunas');
            }

            $agentPayment->update([
                'due_date' => $data['due_date'] ?? $agentPayment->due_date,
                'amount' => $data['amount'] ?? $agentPayment->amount,
                'agent_invoice_number' => $data['agent_invoice_number'] ?? $agentPayment->agent_invoice_number,
                'notes' => $data['notes'] ?? $agentPayment->notes,
            ]);

            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'update',
                'module' => 'agent_payments',
                'record_id' => $agentPayment->agent_payment_id,
                'description' => "Agent payment updated: {$agentPayment->payment_number}",
                'ip_address' => request()->ip(),
            ]);

            return $agentPayment->fresh();
        });
    }

    /**
     * Upload / update agent invoice file
     */
    public function uploadAgentInvoiceFile(int $id, \Illuminate\Http\UploadedFile $file): AgentPayment
    {
        return DB::transaction(function () use ($id, $file) {
            $agentPayment = AgentPayment::findOrFail($id);

            if ($agentPayment->agent_invoice_file_path) {
                Storage::disk('public')->delete($agentPayment->agent_invoice_file_path);
            }

            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $path = $file->storeAs('agent_invoices', $fileName, 'public');

            $agentPayment->update([
                'agent_invoice_file_path' => $path,
            ]);

            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'upload',
                'module' => 'agent_payments',
                'record_id' => $agentPayment->agent_payment_id,
                'description' => "Agent invoice file uploaded: {$agentPayment->payment_number}",
                'ip_address' => request()->ip(),
            ]);

            return $agentPayment->fresh();
        });
    }

    /**
     * Record payment to agent (transfer)
     */
    public function recordPayment(int $id, array $data): AgentPayment
    {
        return DB::transaction(function () use ($id, $data) {
            $agentPayment = AgentPayment::findOrFail($id);

            $payAmount = $data['pay_amount'];

            if ($payAmount <= 0) {
                throw new \Exception('Nominal pembayaran harus lebih besar dari 0');
            }

            if ($payAmount > $agentPayment->outstanding_amount) {
                throw new \Exception('Nominal pembayaran melebihi sisa tagihan');
            }

            $newPaidAmount = $agentPayment->paid_amount + $payAmount;
            $status = $newPaidAmount >= $agentPayment->amount ? 'paid' : 'partial';

            $updateData = [
                'paid_amount' => $newPaidAmount,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'payment_method' => $data['payment_method'] ?? 'transfer',
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'transfer_date' => $data['transfer_date'] ?? $data['payment_date'] ?? now()->toDateString(),
                'status' => $status,
            ];

            if (!empty($data['transfer_proof_file']) && $data['transfer_proof_file'] instanceof \Illuminate\Http\UploadedFile) {
                if ($agentPayment->transfer_proof_path) {
                    Storage::disk('public')->delete($agentPayment->transfer_proof_path);
                }

                $file = $data['transfer_proof_file'];
                $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
                $path = $file->storeAs('agent_payments', $fileName, 'public');

                $updateData['transfer_proof_path'] = $path;
            }

            $agentPayment->update($updateData);

            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'pay',
                'module' => 'agent_payments',
                'record_id' => $agentPayment->agent_payment_id,
                'description' => "Agent payment paid: {$agentPayment->payment_number}, amount: {$payAmount}",
                'ip_address' => request()->ip(),
            ]);

            return $agentPayment->fresh();
        });
    }

    /**
     * Delete agent payment (only pending and no payment)
     */
    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $agentPayment = AgentPayment::findOrFail($id);

            if ($agentPayment->paid_amount > 0) {
                throw new \Exception('Tidak bisa menghapus pembayaran yang sudah ada transaksi');
            }

            if ($agentPayment->transfer_proof_path) {
                Storage::disk('public')->delete($agentPayment->transfer_proof_path);
            }

            if ($agentPayment->agent_invoice_file_path) {
                Storage::disk('public')->delete($agentPayment->agent_invoice_file_path);
            }

            $paymentNumber = $agentPayment->payment_number;
            $agentPayment->delete();

            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'delete',
                'module' => 'agent_payments',
                'record_id' => $id,
                'description' => "Agent payment deleted: {$paymentNumber}",
                'ip_address' => request()->ip(),
            ]);

            return true;
        });
    }

    /**
     * Generate payment number
     */
    private function generatePaymentNumber(int $companyId): string
    {
        $company = \App\Models\Company::find($companyId);
        $companyCode = $company->company_code ?? 'DXM';
        $year = date('Y');
        $month = date('m');

        $last = AgentPayment::where('company_id', $companyId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('agent_payment_id', 'desc')
            ->first();

        $lastNumber = $last ? (int) substr($last->payment_number, -5) : 0;
        $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);

        return "AP/{$companyCode}/{$year}/{$month}/{$newNumber}";
    }

    /**
     * Generate reminders (run daily by scheduler)
     */
    public function generateReminders(): int
    {
        $today = Carbon::today();
        $soonDate = $today->copy()->addDays(7);

        $payments = AgentPayment::whereIn('status', ['pending', 'partial'])
            ->whereBetween('due_date', [$today, $soonDate])
            ->get();

        $count = 0;

        foreach ($payments as $payment) {
            $exists = PaymentReminder::where('reference_type', 'agent_payment')
                ->where('reference_id', $payment->agent_payment_id)
                ->where('reminder_type', 'due_soon')
                ->whereDate('reminder_date', $today)
                ->exists();

            if ($exists) {
                continue;
            }

            PaymentReminder::create([
                'reference_type' => 'agent_payment',
                'reference_id' => $payment->agent_payment_id,
                'reminder_type' => 'due_soon',
                'due_date' => $payment->due_date,
                'reminder_date' => $today,
                'status' => 'pending',
                'message' => "Pembayaran ke agent {$payment->supplier->supplier_name} jatuh tempo pada {$payment->due_date}, jumlah: {$payment->amount}",
            ]);

            $count++;
        }

        // Overdue reminders
        $overduePayments = AgentPayment::whereIn('status', ['pending', 'partial'])
            ->where('due_date', '<', $today)
            ->get();

        foreach ($overduePayments as $payment) {
            $exists = PaymentReminder::where('reference_type', 'agent_payment')
                ->where('reference_id', $payment->agent_payment_id)
                ->where('reminder_type', 'overdue')
                ->whereDate('reminder_date', $today)
                ->exists();

            if ($exists) {
                continue;
            }

            PaymentReminder::create([
                'reference_type' => 'agent_payment',
                'reference_id' => $payment->agent_payment_id,
                'reminder_type' => 'overdue',
                'due_date' => $payment->due_date,
                'reminder_date' => $today,
                'status' => 'pending',
                'message' => "PEMBAYARAN TERLAMBAT ke agent {$payment->supplier->supplier_name}, jatuh tempo: {$payment->due_date}, jumlah: {$payment->amount}, sisa: {$payment->outstanding_amount}",
            ]);

            // Update status to overdue
            $payment->update(['status' => 'overdue']);
            $count++;
        }

        return $count;
    }

    /**
     * Trigger sending reminders (email/notification)
     * Di sini kita simple: tandai sent, nanti bisa dihubungkan ke Notifikasi UI / Email
     */
    public function triggerReminders(): int
    {
        $today = Carbon::today();

        $reminders = PaymentReminder::where('status', 'pending')
            ->whereDate('reminder_date', '<=', $today)
            ->where('reference_type', 'agent_payment')
            ->with('reference')
            ->get();

        $count = 0;

        foreach ($reminders as $reminder) {
            /** @var AgentPayment $payment */
            $payment = $reminder->reference;

            if (!$payment) {
                $reminder->update(['status' => 'dismissed']);
                continue;
            }

            // TODO: kirim email ke Finance Manager (bisa ambil dari setting)
            // For now, cuma tandai sent
            $reminder->markAsSent([]);

            ActivityLog::create([
                'user_id' => null,
                'action' => 'reminder_sent',
                'module' => 'agent_payments',
                'record_id' => $payment->agent_payment_id,
                'description' => "Reminder sent for agent payment: {$payment->payment_number}",
                'ip_address' => null,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Simple stats for dashboard
     */
    public function getStatistics(int $companyId): array
    {
        return [
            'total' => AgentPayment::where('company_id', $companyId)->count(),
            'pending' => AgentPayment::where('company_id', $companyId)->where('status', 'pending')->count(),
            'overdue' => AgentPayment::where('company_id', $companyId)->where('status', 'overdue')->count(),
            'partial' => AgentPayment::where('company_id', $companyId)->where('status', 'partial')->count(),
            'paid' => AgentPayment::where('company_id', $companyId)->where('status', 'paid')->count(),
            'total_outstanding' => AgentPayment::where('company_id', $companyId)
                ->whereIn('status', ['pending', 'overdue', 'partial'])
                ->sum('outstanding_amount'),
        ];
    }
}
