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
        'amount',
        'paid_amount',
        'status',
        'payment_method',
        'bank_name',
        'account_number',
        'transfer_date',
        'transfer_proof_path',
        'agent_invoice_number',
        'agent_invoice_file_path',
        'reminder_sent_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'payment_date' => 'date',
        'transfer_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'reminder_sent_at' => 'datetime',
    ];

    protected $appends = ['outstanding_amount'];

    // Relationships
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

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function scopeDueSoon($query, int $days = 7)
    {
        return $query->where('status', 'pending')
                     ->whereBetween('due_date', [now(), now()->addDays($days)]);
    }

    // Accessors
    public function getOutstandingAmountAttribute(): float
    {
        return $this->amount - $this->paid_amount;
    }

    public function getStatusBadgeAttribute()
    {
        return match($this->status) {
            'pending' => ['text' => 'Pending', 'color' => 'yellow'],
            'overdue' => ['text' => 'Overdue', 'color' => 'red'],
            'partial' => ['text' => 'Partial', 'color' => 'blue'],
            'paid' => ['text' => 'Paid', 'color' => 'green'],
            default => ['text' => 'Unknown', 'color' => 'gray'],
        };
    }

    public function getDaysUntilDueAttribute(): int
    {
        return now()->diffInDays($this->due_date, false);
    }

    public function getDaysOverdueAttribute(): int
    {
        return $this->due_date->isPast() 
            ? now()->diffInDays($this->due_date) 
            : 0;
    }

    // Methods
    public function isOverdue(): bool
    {
        return $this->due_date->isPast() && $this->outstanding_amount > 0;
    }

    public function isDueSoon(int $days = 7): bool
    {
        return $this->due_date->isBetween(now(), now()->addDays($days));
    }

    public function markAsPaid(float $amount, array $paymentDetails): void
    {
        $this->update([
            'paid_amount' => $this->paid_amount + $amount,
            'payment_date' => $paymentDetails['payment_date'] ?? now(),
            'payment_method' => $paymentDetails['payment_method'] ?? null,
            'bank_name' => $paymentDetails['bank_name'] ?? null,
            'transfer_date' => $paymentDetails['transfer_date'] ?? null,
            'transfer_proof_path' => $paymentDetails['transfer_proof_path'] ?? null,
            'status' => $this->outstanding_amount <= 0 ? 'paid' : 'partial',
        ]);
    }
}
