<?php
// app/Models/BankGuarantee.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankGuarantee extends Model
{
    protected $table = 'bank_guarantees';
    protected $primaryKey = 'guarantee_id';
    
    protected $fillable = [
        'po_id',
        'company_id',
        'guarantee_type',
        'bank_name',
        'bank_branch',
        'guarantee_number',
        'guarantee_amount',
        'guarantee_percentage',
        'issue_date',
        'expiry_date',
        'return_date',
        'admin_fee',
        'collateral_fee',
        'status',
        'file_path',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'guarantee_amount' => 'decimal:2',
        'guarantee_percentage' => 'decimal:2',
        'admin_fee' => 'decimal:2',
        'collateral_fee' => 'decimal:2',
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'return_date' => 'date',
    ];

    // Relationships
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('status', 'active')
                     ->whereBetween('expiry_date', [now(), now()->addDays($days)]);
    }

    public function scopeJampel($query)
    {
        return $query->where('guarantee_type', 'jampel');
    }

    public function scopeJamuk($query)
    {
        return $query->where('guarantee_type', 'jamuk');
    }

    // Accessors
    public function getTypeLabelAttribute(): string
    {
        return $this->guarantee_type === 'jampel' 
            ? 'Jaminan Pelaksanaan' 
            : 'Jaminan Uang Muka';
    }

    public function getStatusBadgeAttribute()
    {
        return match($this->status) {
            'active' => ['text' => 'Active', 'color' => 'green'],
            'returned' => ['text' => 'Returned', 'color' => 'blue'],
            'expired' => ['text' => 'Expired', 'color' => 'red'],
            'claimed' => ['text' => 'Claimed', 'color' => 'orange'],
            default => ['text' => 'Unknown', 'color' => 'gray'],
        };
    }

    public function getDaysUntilExpiryAttribute(): int
    {
        return now()->diffInDays($this->expiry_date, false);
    }

    public function getTotalCostAttribute(): float
    {
        return $this->admin_fee + $this->collateral_fee;
    }

    // Methods
    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->status === 'active' 
            && $this->expiry_date->isBetween(now(), now()->addDays($days));
    }

    public function isExpired(): bool
    {
        return $this->expiry_date->isPast() && $this->status === 'active';
    }
}
