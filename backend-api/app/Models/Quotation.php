<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Quotation extends Model
{
    protected $table = 'quotations';
    protected $primaryKey = 'quotation_id';

    protected $fillable = [
        'company_id',
        'customer_id',
        'quotation_number',
        'activity_type_id',
        'quotation_date',
        'valid_until',
        'total_amount',
        'status',
        'notes',
        'created_by',
        'signed_name',
        'signed_position',
        'signed_city',
        'signature_image',
        'signed_at',
        'issued_by',
        'issued_at',
    ];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'total_amount' => 'decimal:2',
        'signed_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    protected $appends = ['is_expired', 'status_label', 'is_issued', 'signature_url'];

    /* ================= RELATIONSHIPS ================= */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    // ✅ ADDED: Relation to ActivityType
    public function activityType()
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id', 'activity_type_id');
    }

    public function items()
    {
        return $this->hasMany(QuotationItem::class, 'quotation_id', 'quotation_id');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'quotation_id', 'quotation_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function issuedByUser()
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    /* ================= ACCESSORS ================= */

    public function getIsExpiredAttribute()
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    public function getStatusLabelAttribute()
    {
        $labels = [
            'draft' => 'Draft',
            'sent' => 'Terkirim',
            'issued' => 'Diterbitkan',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'expired' => 'Kadaluarsa',
        ];
        return $labels[$this->status] ?? $this->status;
    }

    public function getIsIssuedAttribute()
    {
        return $this->status === 'issued' && !is_null($this->issued_at);
    }

    public function getSignatureUrlAttribute()
    {
        if ($this->signature_image && Storage::disk('public')->exists($this->signature_image)) {
            return url('storage/' . $this->signature_image);
        }
        return null;
    }

    /* ================= SCOPES ================= */

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeExpired($query)
    {
        return $query->where('valid_until', '<', now());
    }

    public function scopeIssued($query)
    {
        return $query->where('status', 'issued')->whereNotNull('issued_at');
    }

    // ✅ ADDED: Scope by activity type
    public function scopeByActivityType($query, $activityTypeId)
    {
        return $query->where('activity_type_id', $activityTypeId);
    }

    /* ================= METHODS ================= */

    public function updateTotalAmount()
    {
        $total = $this->items()->sum(DB::raw('quantity * unit_price'));
        $this->update(['total_amount' => $total]);
    }

    public function issue($signedName, $signedPosition, $signedCity, $signatureFile, $issuedBy)
    {
        if ($this->signature_image && Storage::disk('public')->exists($this->signature_image)) {
            Storage::disk('public')->delete($this->signature_image);
        }

        $signaturePath = null;
        if ($signatureFile) {
            $filename = 'quotation_' . $this->quotation_id . '_' . time() . '.' . $signatureFile->getClientOriginalExtension();
            $signaturePath = $signatureFile->storeAs('signatures/quotations', $filename, 'public');
        }

        $this->update([
            'status' => 'issued',
            'signed_name' => $signedName,
            'signed_position' => $signedPosition,
            'signed_city' => $signedCity,
            'signature_image' => $signaturePath,
            'signed_at' => now(),
            'issued_by' => $issuedBy,
            'issued_at' => now(),
        ]);

        return $this;
    }

    public function deleteSignature()
    {
        if ($this->signature_image && Storage::disk('public')->exists($this->signature_image)) {
            Storage::disk('public')->delete($this->signature_image);
            $this->update(['signature_image' => null]);
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($quotation) {
            $quotation->deleteSignature();
        });
    }
}
