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
        'quantity' => 'integer',
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
        return $this->quantity * $this->unit_price;
    }

    public function getDiscountAmountAttribute()
    {
        return $this->subtotal * ($this->discount_percent / 100);
    }

    public function getTotalAttribute()
    {
        return $this->subtotal - $this->discount_amount;
    }
}