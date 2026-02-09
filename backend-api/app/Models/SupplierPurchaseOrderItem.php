<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPurchaseOrderItem extends Model
{
    protected $table = 'supplier_purchase_order_items';
    protected $primaryKey = 'supplier_po_item_id';

    protected $fillable = [
        'supplier_po_id',
        'product_id',
        'product_name',
        'product_code',
        'quantity',
        'unit',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'subtotal',
        'total',
        'received_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'received_quantity' => 'decimal:2',
    ];

    protected $appends = ['remaining_quantity'];

    // Relationships
    public function supplierPo()
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_po_id', 'supplier_po_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    // Accessor
    public function getRemainingQuantityAttribute()
    {
        return $this->quantity - $this->received_quantity;
    }
}
