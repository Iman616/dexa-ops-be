<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $table = 'purchase_orders';
    protected $primaryKey = 'po_id';

protected $fillable = [
    'company_id',
    'customer_id',
    'quotation_id',
    'po_number',
    'po_date',
    'po_file_path',
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
    'signed_at' => 'datetime',
    'issued_at' => 'datetime',
    'total_amount' => 'decimal:2', // TAMBAHKAN INI
];

    protected $appends = ['status_label', 'has_po_file', 'is_expired'];

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

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'po_id', 'po_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'po_id', 'po_id');
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
        return !empty($this->po_file_path) && Storage::exists($this->po_file_path);
    }

    public function getPoFileUrlAttribute()
    {
        if ($this->has_po_file) {
            return Storage::url($this->po_file_path);
        }
        return null;
    }

    public function getIsExpiredAttribute()
    {
        // PO yang sudah approved, completed, atau cancelled tidak bisa expired
        if (in_array($this->status, ['approved', 'completed', 'cancelled'])) {
            return false;
        }
        
        // Check jika ada valid_until (opsional)
        if (isset($this->attributes['valid_until'])) {
            return $this->valid_until && $this->valid_until->isPast();
        }
        
        return false;
    }

    public function getTotalAmountAttribute()
    {
        return $this->items->sum(function($item) {
            return $item->quantity * $item->unit_price * (1 - $item->discount_percent / 100);
        });
    }

    public function getSubtotalAttribute()
    {
        return $this->total_amount;
    }

    public function getTaxAmountAttribute()
    {
        // PPN 12%
        $dpp = $this->subtotal / 1.12;
        return $dpp * 0.12;
    }

    public function getGrandTotalAttribute()
    {
        return $this->subtotal;
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

    /* ================= METHODS ================= */

    /**
     * Issue purchase order dengan tanda tangan
     */
    public function issue($signedName, $signedPosition, $signedCity, $issuedBy)
    {
        $this->update([
            'status' => 'issued',
            'signed_name' => $signedName,
            'signed_position' => $signedPosition,
            'signed_city' => $signedCity,
            'signed_at' => now(),
            'issued_by' => $issuedBy,
            'issued_at' => now(),
        ]);

        return $this;
    }

    /* ================= FILE METHODS ================= */

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
            Storage::delete($this->po_file_path);
        }
    }

    protected static function boot()
    {
        parent::boot();
        
        static::deleting(function ($po) {
            $po->deletePoFile();
            $po->items()->delete();
        });
    }
}
