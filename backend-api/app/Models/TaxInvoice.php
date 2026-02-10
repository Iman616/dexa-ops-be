<?php
// app/Models/TaxInvoice.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxInvoice extends Model
{
    protected $table = 'tax_invoices';
    protected $primaryKey = 'tax_invoice_id';
    
    protected $fillable = [
        'company_id',
        'invoice_id',
        'tax_invoice_number',
        'tax_invoice_date',
        'tax_type',
        'dpp_amount',
        'tax_rate',
        'tax_amount',
        'status',
        'file_path',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tax_invoice_date' => 'date',
        'dpp_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    // Relationships
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    // Accessors
    public function getStatusBadgeAttribute()
    {
        return match($this->status) {
            'draft' => ['text' => 'Draft', 'color' => 'gray'],
            'submitted' => ['text' => 'Submitted', 'color' => 'blue'],
            'approved' => ['text' => 'Approved', 'color' => 'green'],
            'rejected' => ['text' => 'Rejected', 'color' => 'red'],
            default => ['text' => 'Unknown', 'color' => 'gray'],
        };
    }

    // Methods
    public function canEdit(): bool
    {
        return $this->status === 'draft';
    }

    public function canSubmit(): bool
    {
        return $this->status === 'draft';
    }

    public function canApprove(): bool
    {
        return $this->status === 'submitted';
    }
}
