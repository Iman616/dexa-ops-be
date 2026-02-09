<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierPurchaseOrder extends Model
{
    use SoftDeletes;

    protected $table = 'supplier_purchase_orders';
    protected $primaryKey = 'supplier_po_id';

    protected $fillable = [
        'po_number',
        'supplier_id',
        'company_id',
        'po_date',
        'expected_delivery_date',
        'status',
        'payment_status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'notes',
        'terms',
        'signed_name',
        'signed_position',
        'signed_city',
        'issued_at',
        'issued_by',
        'created_by',
    ];

    protected $casts = [
        'po_date' => 'date',
        'expected_delivery_date' => 'date',
        'issued_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected $appends = ['status_label', 'payment_status_label', 'is_overdue'];

    // Relationships
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function items()
    {
        return $this->hasMany(SupplierPurchaseOrderItem::class, 'supplier_po_id', 'supplier_po_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function issuedByUser()
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    // Accessors
    public function getStatusLabelAttribute()
    {
        return ucfirst($this->status);
    }

    public function getPaymentStatusLabelAttribute()
    {
        return ucfirst($this->payment_status);
    }

    public function getIsOverdueAttribute()
    {
        if ($this->status === 'completed' || $this->status === 'cancelled') {
            return false;
        }
        return $this->expected_delivery_date && $this->expected_delivery_date->isPast();
    }

    // Generate PO Number
    public static function generatePoNumber()
    {
        $prefix = 'SPO';
        $date = now()->format('Ym');
        $latest = self::whereRaw("po_number LIKE '{$prefix}-{$date}-%'")
            ->orderBy('supplier_po_id', 'desc')
            ->first();

        if ($latest) {
            $lastNumber = (int) substr($latest->po_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}-{$date}-{$newNumber}";
    }

    
}
