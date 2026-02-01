<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;

class DeliveryNoteItem extends Model
{
        use HasFactory;

    protected $table = 'delivery_note_items';
    protected $primaryKey = 'delivery_note_item_id';

    protected $fillable = [
        'delivery_note_id',
        'product_id',
        'stock_in_id',
        'product_code',
        'product_name',
        'quantity',
        'unit',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id', 'delivery_note_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function stockIn()
    {
        return $this->belongsTo(StockIn::class, 'stock_in_id', 'stock_in_id');
    }
}