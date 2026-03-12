<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ProformaInvoice extends Model
{
    use HasFactory;

    protected $table      = 'proforma_invoices';
    protected $primaryKey = 'proforma_id';

    protected $fillable = [
        'company_id',
        'customer_id',
        'po_id',
        'proforma_number',
        'proforma_date',
        'valid_until',
        'validity_days',

        // ✅ Kalkulasi
        'subtotal',
        'tax_percentage',
        'tax_amount',
        'dpp_adjustment',      // ✅ DPP Nilai Lain
        'discount_amount',
        'discount_percentage', // ✅ persen diskon
        'total_amount',
        'grand_total',         // ✅ final total
        'use_ppn',             // ✅ flag PPN/Non-PPN

        // Info
        'currency',
        'payment_terms',
        'delivery_terms',
        'notes',

        // TTD / Issue
        'signed_name',
        'signed_position',
        'signed_city',
        'signed_at',
        'signature_image',
        'signature_image_path',
        'issued_by',
        'issued_at',

        // Audit
        'created_by',
        'approved_by',
        'approved_at',
        'converted_to_invoice_id',
        'converted_at',

        // Bank (jika ada)
        'bank_name',
        'bank_code',
        'account_number',
        'account_holder',

        // Status
        'status',
        'cancellation_reason',
    ];

    protected $casts = [
        // Numerik
        'subtotal'            => 'decimal:2',
        'tax_percentage'      => 'decimal:2',
        'tax_amount'          => 'decimal:2',
        'dpp_adjustment'      => 'decimal:2',  // ✅
        'discount_amount'     => 'decimal:2',
        'discount_percentage' => 'decimal:2',  // ✅
        'total_amount'        => 'decimal:2',
        'grand_total'         => 'decimal:2',  // ✅

        // Boolean
        'use_ppn'             => 'boolean',    // ✅

        // Datetime
        'proforma_date'       => 'date',
        'valid_until'         => 'date',
        'signed_at'           => 'datetime',
        'issued_at'           => 'datetime',
        'approved_at'         => 'datetime',
        'converted_at'        => 'datetime',
    ];

    protected $appends = [
        'is_expired',
        'signature_image_url',
    ];

    protected $attributes = [
        'currency'            => 'IDR',
        'status'              => 'draft',
        'discount_amount'     => 0,
        'discount_percentage' => 0,
        'dpp_adjustment'      => 0,
        'use_ppn'             => true,
        'validity_days'       => 30,
    ];

    // ==================== RELATIONSHIPS ====================

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProformaInvoiceItem::class, 'proforma_id', 'proforma_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'proforma_invoice_id', 'proforma_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->createdBy();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->approvedBy();
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * ✅ PI expired jika valid_until sudah lewat dan belum converted/cancelled
     */
    public function getIsExpiredAttribute(): bool
    {
        if (!$this->valid_until) return false;

        return $this->valid_until->isPast()
            && !in_array($this->status, ['converted', 'cancelled']);
    }

    /**
     * ✅ URL publik signature image
     */
    public function getSignatureImageUrlAttribute(): ?string
    {
        if (!$this->signature_image) return null;

        return \Illuminate\Support\Facades\Storage::disk('public')
            ->url($this->signature_image);
    }

    /**
     * ✅ grand_total accessor — ambil dari kolom DB, fallback ke total_amount
     * TIDAK melakukan penghitungan ulang agar tidak double count
     */
    public function getGrandTotalAttribute(): float
    {
        return (float) ($this->attributes['grand_total'] ?? $this->attributes['total_amount'] ?? 0);
    }

    /**
     * ✅ DPP Nilai Lain accessor — ambil dari kolom DB
     */
    public function getDppAdjustmentAttribute(): float
    {
        return (float) ($this->attributes['dpp_adjustment'] ?? 0);
    }

    // ==================== SCOPES ====================

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('proforma_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn($q2) =>
                    $q2->where('customer_name', 'like', "%{$search}%")
                );
        });
    }

    // ==================== METHODS ====================

    public function markAsSent(): bool
    {
        if ($this->status !== 'draft') return false;
        $this->status = 'sent';
        return $this->save();
    }

    public function approve($userId = null): bool
    {
        if ($this->status !== 'sent') return false;
        $this->status      = 'approved';
        $this->approved_by = $userId ?? Auth::id();
        $this->approved_at = now();
        return $this->save();
    }

    /**
     * ✅ Recalculate dan simpan ulang kalkulasi PI
     * Rumus: DPP Nilai Lain = base × tax/(tax+1), PPN = DPP × tax%
     */
    public function recalculate(): void
    {
        $subtotal       = (float) $this->subtotal;
        $discountAmount = (float) $this->discount_amount;
        $baseForTax     = $subtotal - $discountAmount;
        $usePpn         = (bool) $this->use_ppn;

        if ($usePpn && (float) $this->tax_percentage > 0) {
            $taxRate   = (float) $this->tax_percentage;
            $dpp       = round($baseForTax * ($taxRate / ($taxRate + 1)), 2);
            $taxAmount = round($dpp * ($taxRate / 100), 2);
        } else {
            $dpp       = $baseForTax;
            $taxAmount = 0;
        }

        $total = round($baseForTax + $taxAmount, 2);

        DB::table('proforma_invoices')
            ->where('proforma_id', $this->proforma_id)
            ->update([
                'dpp_adjustment' => $dpp,
                'tax_amount'     => $taxAmount,
                'total_amount'   => $total,
                'grand_total'    => $total,
                'updated_at'     => now(),
            ]);

        // Refresh in-memory
        $this->dpp_adjustment = $dpp;
        $this->tax_amount     = $taxAmount;
        $this->total_amount   = $total;
        $this->grand_total    = $total;
    }

    public function convertToInvoice($userId = null): array
    {
        if ($this->status !== 'approved') {
            return ['success' => false, 'message' => 'Only approved proforma can be converted'];
        }

        if ($this->converted_to_invoice_id) {
            return ['success' => false, 'message' => 'Already converted to invoice'];
        }

        DB::beginTransaction();
        try {
            DB::statement('CALL sp_convert_proforma_to_invoice(?, ?, @invoice_id, @message)', [
                $this->proforma_id,
                $userId ?? Auth::id(),
            ]);

            $result = DB::select('SELECT @invoice_id as invoice_id, @message as message');

            if (!$result[0]->invoice_id) {
                DB::rollBack();
                return ['success' => false, 'message' => $result[0]->message];
            }

            DB::commit();
            return [
                'success'    => true,
                'message'    => $result[0]->message,
                'invoice_id' => $result[0]->invoice_id,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
