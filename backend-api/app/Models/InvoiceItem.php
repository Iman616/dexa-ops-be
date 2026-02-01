<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $table = 'invoice_items';
    protected $primaryKey = 'item_id';

    public $timestamps = false; // Jika tidak ada created_at/updated_at

    protected $fillable = [
        'invoice_id',
        'product_id',
        'product_name',
        'quantity',
        'unit',
        'unit_price',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    protected $appends = ['subtotal', 'formatted_subtotal'];

    /* ================= RELATIONSHIPS ================= */

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /* ================= ACCESSORS ================= */

    /**
     * Calculate subtotal (quantity × unit_price)
     */
    public function getSubtotalAttribute()
    {
        return $this->quantity * $this->unit_price;
    }

    /**
     * Get formatted subtotal
     */
    public function getFormattedSubtotalAttribute()
    {
        return 'Rp ' . number_format($this->subtotal, 0, ',', '.');
    }

    /**
     * Get formatted unit price
     */
    public function getFormattedPriceAttribute()
    {
        return 'Rp ' . number_format($this->unit_price, 0, ',', '.');
    }
}