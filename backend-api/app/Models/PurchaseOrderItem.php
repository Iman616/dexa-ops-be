<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $table = 'purchase_order_items';
    protected $primaryKey = 'item_id';

    protected $fillable = [
        'po_id',
        'product_id',
        'product_name',
        'specification',
        'quantity',
        'unit',
        'unit_price',
        'discount_percent',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2', // ✅ UBAH dari integer ke decimal
        'unit_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
    ];

    protected $appends = ['subtotal', 'discount_amount', 'total'];

    /* ================= RELATIONSHIPS ================= */

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /* ================= ACCESSORS ================= */

    public function getSubtotalAttribute()
    {
        // ✅ PERBAIKAN: Pastikan nilai numerik
        $quantity = floatval($this->attributes['quantity'] ?? 0);
        $unitPrice = floatval($this->attributes['unit_price'] ?? 0);
        return $quantity * $unitPrice;
    }

    public function getDiscountAmountAttribute()
    {
        // ✅ PERBAIKAN: Gunakan raw subtotal calculation
        $subtotal = $this->getSubtotalAttribute();
        $discountPercent = floatval($this->attributes['discount_percent'] ?? 0);
        return $subtotal * ($discountPercent / 100);
    }

    public function getTotalAttribute()
    {
        // ✅ PERBAIKAN: Langsung hitung
        return $this->getSubtotalAttribute() - $this->getDiscountAmountAttribute();
    }
}