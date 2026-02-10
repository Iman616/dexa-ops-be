<?php
// app/Models/TenderProjectDetail.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenderProjectDetail extends Model
{
    protected $table = 'tender_project_details';
    protected $primaryKey = 'detail_id';
    
    protected $fillable = [
        'po_id',
        'contract_number',
        'contract_start_date',
        'contract_end_date',
        'contract_file_path',
        'has_ba_uji_fungsi',
        'ba_uji_fungsi_date',
        'has_bahp',
        'bahp_date',
        'has_bast',
        'bast_date',
        'has_sp2d',
        'sp2d_date',
        'project_status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
        'ba_uji_fungsi_date' => 'date',
        'bahp_date' => 'date',
        'bast_date' => 'date',
        'sp2d_date' => 'date',
        'has_ba_uji_fungsi' => 'boolean',
        'has_bahp' => 'boolean',
        'has_bast' => 'boolean',
        'has_sp2d' => 'boolean',
    ];

    // Relationships
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function bankGuarantees(): HasMany
    {
        return $this->hasMany(BankGuarantee::class, 'po_id', 'po_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenderDocument::class, 'po_id', 'po_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    // Accessors
    public function getCompletionPercentageAttribute(): int
    {
        $total = 4; // BA, BAHP, BAST, SP2D
        $completed = 0;
        
        if ($this->has_ba_uji_fungsi) $completed++;
        if ($this->has_bahp) $completed++;
        if ($this->has_bast) $completed++;
        if ($this->has_sp2d) $completed++;
        
        return (int) (($completed / $total) * 100);
    }

    public function getStatusBadgeAttribute()
    {
        return match($this->project_status) {
            'ongoing' => ['text' => 'Ongoing', 'color' => 'blue'],
            'ba_done' => ['text' => 'BA Done', 'color' => 'indigo'],
            'bahp_done' => ['text' => 'BAHP Done', 'color' => 'purple'],
            'bast_done' => ['text' => 'BAST Done', 'color' => 'pink'],
            'sp2d_done' => ['text' => 'SP2D Done', 'color' => 'orange'],
            'completed' => ['text' => 'Completed', 'color' => 'green'],
            default => ['text' => 'Unknown', 'color' => 'gray'],
        };
    }

    // Methods
    public function updateDocumentStatus(string $documentType, bool $status, $date = null): void
    {
        $fieldMap = [
            'ba_uji_fungsi' => ['has_ba_uji_fungsi', 'ba_uji_fungsi_date', 'ba_done'],
            'bahp' => ['has_bahp', 'bahp_date', 'bahp_done'],
            'bast' => ['has_bast', 'bast_date', 'bast_done'],
            'sp2d' => ['has_sp2d', 'sp2d_date', 'sp2d_done'],
        ];

        if (isset($fieldMap[$documentType])) {
            [$hasField, $dateField, $statusValue] = $fieldMap[$documentType];
            
            $this->update([
                $hasField => $status,
                $dateField => $date ?? now(),
                'project_status' => $statusValue,
            ]);
        }
    }
}
