<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $table = 'invoice_items';
    protected $primaryKey = 'item_id';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'product_id',
        'product_name',
        'product_description', // ✅ ADDED: Deskripsi produk (dari controller)
        'quantity',
        'unit',
        'unit_price',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    protected $appends = [
        'subtotal',
        'formatted_subtotal',
        'formatted_price',
    ];

    /* ================= RELATIONSHIPS ================= */

    /**
     * Belongs to Invoice (Customer Invoice)
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }

    /**
     * Belongs to Product
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /**
     * ✅ NEW: Has many DeliveryNoteItems
     * Invoice item bisa muncul di banyak delivery notes (partial delivery)
     */
    public function deliveryNoteItems()
    {
        return $this->hasMany(DeliveryNoteItem::class, 'invoice_item_id', 'item_id');
    }

    /**
     * ✅ NEW: Check if invoice item linked to delivery note
     */
    public function hasDeliveryNote()
    {
        return $this->deliveryNoteItems()->exists();
    }

    /**
     * ✅ NEW: Get total delivered quantity
     */
    public function getTotalDeliveredQuantity()
    {
        return $this->deliveryNoteItems()->sum('quantity');
    }

    /**
     * ✅ NEW: Get remaining quantity to deliver
     */
    public function getRemainingQuantity()
    {
        return $this->quantity - $this->getTotalDeliveredQuantity();
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

    /**
     * ✅ NEW: Check if fully delivered
     */
    public function getIsFullyDeliveredAttribute()
    {
        return $this->getRemainingQuantity() <= 0;
    }

    /**
     * ✅ NEW: Get delivery status
     */
    public function getDeliveryStatusAttribute()
    {
        $delivered = $this->getTotalDeliveredQuantity();
        
        if ($delivered <= 0) {
            return 'pending';
        } elseif ($delivered >= $this->quantity) {
            return 'completed';
        } else {
            return 'partial';
        }
    }

    /* ================= SCOPES ================= */

    /**
     * ✅ NEW: Scope untuk item yang belum ada delivery note
     */
    public function scopeWithoutDeliveryNote($query)
    {
        return $query->whereDoesntHave('deliveryNoteItems');
    }

    /**
     * ✅ NEW: Scope untuk item yang partially delivered
     */
    public function scopePartiallyDelivered($query)
    {
        return $query->whereHas('deliveryNoteItems', function($q) {
            // Complex query: total delivered < invoice quantity
        });
    }
}