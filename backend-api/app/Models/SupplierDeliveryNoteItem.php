<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierDeliveryNoteItem extends Model
{
    protected $table = 'supplier_delivery_note_items';
    protected $primaryKey = 'item_id';

    protected $fillable = [
        'supplier_delivery_note_id',
        'product_id',
        'batch_number',
        'quantity',
        'purchase_price',
        'manufacture_date',
        'expiry_date',
        'stock_in_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'purchase_price' => 'decimal:2',
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
    ];

    protected $appends = ['total_value'];

    /* ================= RELATIONSHIPS ================= */

    public function deliveryNote()
    {
        return $this->belongsTo(SupplierDeliveryNote::class, 'supplier_delivery_note_id', 'supplier_delivery_note_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function stockIn()
    {
        return $this->belongsTo(StockIn::class, 'stock_in_id', 'stock_in_id');
    }

    /* ================= ACCESSORS ================= */

    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->purchase_price;
    }
}
