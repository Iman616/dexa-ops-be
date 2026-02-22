<?php
// app/Models/AgentPayment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPayment extends Model
{
    protected $table = 'agent_payments';
    protected $primaryKey = 'agent_payment_id';

    protected $fillable = [
        'company_id',
        'supplier_id',
        'supplier_po_id',
        'payment_number',
        'due_date',
        'payment_date',
        // ✅ FIX: Tambah field contract & commission sesuai alur step 25
        'contract_value',
        'commission_percentage',
        'amount',           // auto-calculated = contract_value × commission_percentage / 100
        'paid_amount',
        'status',
        'payment_method',
        'bank_name',
        'account_number',
        'transfer_date',
        'transfer_proof_path',
        'agent_invoice_number',
        'agent_invoice_file_path',
        // ✅ FIX: Tambah approve fields sesuai alur step 26
        'approved_by',
        'approved_at',
        'reminder_sent_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'due_date'              => 'date',
        'payment_date'          => 'date',
        'transfer_date'         => 'date',
        'approved_at'           => 'datetime',
        'reminder_sent_at'      => 'datetime',
        'contract_value'        => 'decimal:2',
        'commission_percentage' => 'decimal:2',
        'amount'                => 'decimal:2',
        'paid_amount'           => 'decimal:2',
    ];

    protected $appends = ['outstanding_amount', 'status_badge', 'days_until_due', 'days_overdue'];

    // ─────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function supplierPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_po_id', 'supplier_po_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    // ✅ FIX: Tambah relasi approvedBy sesuai alur step 26
    public function approvedBy(): BelongsTo
    {
 return $this->belongsTo(User::class, 'approved_by', 'user_id')
        ->select(['user_id', 'full_name']);    }

    // ─────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    // ✅ FIX: Tambah scope approved untuk filter yang sudah di-approve
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeDueSoon($query, int $days = 7)
    {
        return $query->whereIn('status', ['pending', 'approved'])
                     ->whereBetween('due_date', [now(), now()->addDays($days)]);
    }

    // ─────────────────────────────────────────
    // Accessors
    // ─────────────────────────────────────────

    public function getOutstandingAmountAttribute(): float
    {
        return max(0, (float) $this->amount - (float) $this->paid_amount);
    }

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            'pending'  => ['text' => 'Pending',  'color' => 'yellow'],
            'approved' => ['text' => 'Approved', 'color' => 'blue'],  // ✅ FIX: Tambah status approved
            'overdue'  => ['text' => 'Overdue',  'color' => 'red'],
            'partial'  => ['text' => 'Partial',  'color' => 'orange'],
            'paid'     => ['text' => 'Paid',     'color' => 'green'],
            default    => ['text' => 'Unknown',  'color' => 'gray'],
        };
    }

    public function getDaysUntilDueAttribute(): int
    {
        return (int) now()->diffInDays($this->due_date, false);
    }

    public function getDaysOverdueAttribute(): int
    {
        return $this->due_date->isPast()
            ? (int) now()->diffInDays($this->due_date)
            : 0;
    }

    // ─────────────────────────────────────────
    // Methods
    // ─────────────────────────────────────────

    public function isOverdue(): bool
    {
        return $this->due_date->isPast() && $this->outstanding_amount > 0;
    }

    public function isDueSoon(int $days = 7): bool
    {
        return $this->due_date->isBetween(now(), now()->addDays($days));
    }

    // ✅ FIX: Tambah method canBeApproved
    public function canBeApproved(): bool
    {
        return $this->status === 'pending';
    }

    // ✅ FIX: Tambah method canBePaid — harus approved dulu
    public function canBePaid(): bool
    {
        return in_array($this->status, ['approved', 'partial']);
    }

    // ✅ FIX: Tambah method approve sesuai alur step 26
    public function approve(int $approvedBy): void
    {
        if (!$this->canBeApproved()) {
            throw new \Exception('Payment tidak bisa di-approve. Status saat ini: ' . $this->status);
        }

        $this->update([
            'status'      => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);
    }

    // ✅ FIX: Bug lama — outstanding dihitung SEBELUM paid_amount diupdate
    // Sekarang hitung dari newPaidAmount
    public function markAsPaid(float $amount, array $paymentDetails): void
    {
        // ✅ Hitung dulu nilai baru sebelum update
        $newPaidAmount   = (float) $this->paid_amount + $amount;
        $newOutstanding  = (float) $this->amount - $newPaidAmount;
        $newStatus       = $newOutstanding <= 0.01 ? 'paid' : 'partial';

        $this->update([
            'paid_amount'        => $newPaidAmount,
            'payment_date'       => $paymentDetails['payment_date'] ?? now(),
            'payment_method'     => $paymentDetails['payment_method'] ?? null,
            'bank_name'          => $paymentDetails['bank_name'] ?? null,
            'transfer_date'      => $paymentDetails['transfer_date'] ?? null,
            'transfer_proof_path'=> $paymentDetails['transfer_proof_path'] ?? null,
            'status'             => $newStatus,
        ]);
    }
}