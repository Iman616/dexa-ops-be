<?php
// app/Models/TenderDocument.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderDocument extends Model
{
    protected $table = 'tender_documents';
    protected $primaryKey = 'document_id';
    
    protected $fillable = [
        'po_id',
        'company_id',
        'document_type',
        'document_number',
        'document_date',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'status',
        'uploaded_by',
        'uploaded_at',
        'verified_by',
        'verified_at',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'document_date' => 'date',
        'file_size' => 'integer',
        'uploaded_at' => 'datetime',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
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

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    // Scopes
    public function scopeByType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    public function scopeByPO($query, int $poId)
    {
        return $query->where('po_id', $poId);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    // Accessors
    public function getTypeLabelAttribute(): string
    {
        return match($this->document_type) {
            'kontrak_asli' => 'Kontrak Asli',
            'kontrak_soft' => 'Kontrak Soft Copy',
            'ba_uji_fungsi' => 'BA Uji Fungsi',
            'bahp' => 'BAHP',
            'bast' => 'BAST',
            'sp2d' => 'SP2D',
            'bukti_ppn' => 'Bukti Bayar PPN',
            'bukti_pph' => 'Bukti Bayar PPh',
            default => 'Unknown',
        };
    }

    public function getStatusBadgeAttribute()
    {
        return match($this->status) {
            'draft' => ['text' => 'Draft', 'color' => 'gray'],
            'submitted' => ['text' => 'Submitted', 'color' => 'blue'],
            'verified' => ['text' => 'Verified', 'color' => 'indigo'],
            'approved' => ['text' => 'Approved', 'color' => 'green'],
            'rejected' => ['text' => 'Rejected', 'color' => 'red'],
            default => ['text' => 'Unknown', 'color' => 'gray'],
        };
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    // Methods
    public function canEdit(): bool
    {
        return in_array($this->status, ['draft', 'submitted']);
    }

    public function canVerify(): bool
    {
        return $this->status === 'submitted';
    }

    public function canApprove(): bool
    {
        return $this->status === 'verified';
    }
}
