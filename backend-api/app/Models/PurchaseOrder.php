<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';
    protected $primaryKey = 'po_id';

    protected $fillable = [
        'company_id',
        'customer_id',
        'quotation_id',
        'activity_type_id',
        'po_number',
        'po_date',
        'valid_until', 
        'po_file_path',
        'po_customer_file_path',
        'po_customer_uploaded_at',
        'signature_image',
        'status',
        'notes',
        'total_amount',
        'signed_name',
        'signed_position',
        'signed_city',
        'signed_at',
        'created_by',
        'issued_by',
        'issued_at',
    ];

    protected $casts = [
        'po_date' => 'date',
        'valid_until' => 'date',
        'signed_at' => 'datetime',
        'issued_at' => 'datetime',
        'po_customer_uploaded_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    protected $appends = [
        'status_label',
        'has_po_file',
        'has_po_customer_file',
        'is_expired',
        'signature_url',
        'po_file_url',
        'po_customer_file_url',
        'activity_type_name',
        'is_tender', // ✅ NEW
    ];

    /* ================= RELATIONSHIPS ================= */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id', 'quotation_id');
    }

    public function activityType()
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id', 'activity_type_id');
    }

    // ✅ ALIAS: Support both camelCase and snake_case
    public function activity_type()
    {
        return $this->activityType();
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'po_id', 'po_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'po_id', 'po_id');
    }

    public function deliveryNotes()
    {
        return $this->hasMany(DeliveryNote::class, 'po_id', 'po_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function issuedByUser()
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    // ✅ Tender relationships
    public function tenderProject()
    {
        return $this->hasOne(TenderProjectDetail::class, 'po_id', 'po_id');
    }

    public function bankGuarantees()
    {
        return $this->hasMany(BankGuarantee::class, 'po_id', 'po_id');
    }

    public function tenderDocuments()
    {
        return $this->hasMany(TenderDocument::class, 'po_id', 'po_id');
    }

    /* ================= ACCESSORS ================= */

    public function getStatusLabelAttribute()
    {
        $labels = [
            'draft' => 'Draft',
            'issued' => 'Diterbitkan',
            'sent' => 'Terkirim',
            'approved' => 'Disetujui',
            'processing' => 'Diproses',
            'completed' => 'Selesai',
            'cancelled' => 'Dibatalkan',
            'expired' => 'Kadaluarsa',
        ];
        return $labels[$this->status] ?? $this->status;
    }

    public function getHasPoFileAttribute()
    {
        return !empty($this->po_file_path) && Storage::disk('public')->exists($this->po_file_path);
    }

    public function getHasPoCustomerFileAttribute()
    {
        return !empty($this->po_customer_file_path) && Storage::disk('public')->exists($this->po_customer_file_path);
    }

    public function getSignatureUrlAttribute()
    {
        if ($this->signature_image && Storage::disk('public')->exists($this->signature_image)) {
            return url('storage/' . $this->signature_image);
        }
        return null;
    }

    public function getPoFileUrlAttribute()
    {
        if ($this->has_po_file) {
            return url('storage/' . $this->po_file_path);
        }
        return null;
    }

    public function getPoCustomerFileUrlAttribute()
    {
        if ($this->has_po_customer_file) {
            return url('storage/' . $this->po_customer_file_path);
        }
        return null;
    }

    public function getIsExpiredAttribute()
    {
        if (in_array($this->status, ['approved', 'completed', 'cancelled'])) {
            return false;
        }

        if (!empty($this->valid_until)) {
            return now()->greaterThan($this->valid_until);
        }

        return false;
    }

    public function getActivityTypeNameAttribute()
    {
        if ($this->activityType) {
            return $this->activityType->type_name;
        }

        if ($this->quotation && $this->quotation->activityType) {
            return $this->quotation->activityType->type_name;
        }

        return null;
    }

    /**
     * ✅ NEW: Check if this PO is tender type
     * This is used by frontend to conditionally render tender UI
     */
    public function getIsTenderAttribute()
    {
        // Priority 1: Check from direct activity_type relationship
        if ($this->activityType && $this->activityType->type_code === 'TENDER') {
            return true;
        }
        
        // Priority 2: Check from quotation->activityType
        if ($this->quotation && $this->quotation->activityType) {
            return in_array($this->quotation->activityType->type_code, ['TENDER', 'paket_pengadaan', 'penunjukan_langsung']);
        }
        
        // Priority 3: Check by activity_type_id (if 1 or 2, likely tender)
        if (in_array($this->activity_type_id, [1, 2])) {
            return true;
        }
        
        return false;
    }

    /* ================= METHODS ================= */

    public function issue($signedName, $signedPosition, $signedCity, $signatureFile, $issuedBy)
    {
        if ($this->signature_image && Storage::disk('public')->exists($this->signature_image)) {
            Storage::disk('public')->delete($this->signature_image);
        }

        $signaturePath = null;
        if ($signatureFile) {
            $filename = 'po_' . $this->po_id . '_' . time() . '.' . $signatureFile->getClientOriginalExtension();
            $signaturePath = $signatureFile->storeAs('signatures/purchase_orders', $filename, 'public');
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

    public function uploadPoFile($file)
    {
        $this->deletePoFile();
        $filename = 'PO_' . $this->po_id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('purchase_orders', $filename, 'public');
        $this->update(['po_file_path' => $path]);
        return $path;
    }

    public function deletePoFile()
    {
        if ($this->has_po_file) {
            Storage::disk('public')->delete($this->po_file_path);
        }
    }

    public function uploadPoCustomerFile($file)
    {
        $this->deletePoCustomerFile();
        $filename = 'PO_CUSTOMER_' . $this->po_id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('purchase_orders/customer', $filename, 'public');
        
        $this->update([
            'po_customer_file_path' => $path,
            'po_customer_uploaded_at' => now(),
        ]);
        
        return $path;
    }

    public function deletePoCustomerFile()
    {
        if ($this->has_po_customer_file) {
            Storage::disk('public')->delete($this->po_customer_file_path);
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($po) {
            $po->deleteSignature();
            $po->deletePoFile();
            $po->deletePoCustomerFile();
            $po->items()->delete();
        });
    }
}