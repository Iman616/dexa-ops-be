<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryNoteItem extends Model
{
    use HasFactory;

    protected $table = 'delivery_note_items';
    protected $primaryKey = 'delivery_note_item_id';

    public $timestamps = false;

    protected $fillable = [
        'delivery_note_id',
        'invoice_item_id',      // ✅ ADDED: Link ke invoice item (optional)
        'product_id',
        'stock_out_id',         // ✅ CHANGED: dari stock_in_id ke stock_out_id (karena keluar)
        'product_code',
        'product_name',
        'quantity',
        'unit',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    protected $appends = [
        'has_stock_out',
        'stock_status',
    ];

    /* ================= RELATIONSHIPS ================= */

    /**
     * Belongs to Delivery Note (Customer Delivery)
     */
    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id', 'delivery_note_id');
    }

    /**
     * ✅ NEW: Belongs to Invoice Item
     * Link ke invoice item untuk tracking partial delivery
     */
    public function invoiceItem()
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id', 'item_id');
    }

    /**
     * Belongs to Product
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /**
     * ✅ FIXED: Belongs to Stock OUT (bukan Stock IN)
     * Karena ini customer delivery (barang keluar)
     */
    public function stockOut()
    {
        return $this->belongsTo(StockOut::class, 'stock_out_id', 'stock_out_id');
    }

    /**
     * ✅ DEPRECATED: Stock IN removed (ini untuk customer delivery, bukan supplier)
     * Keep for backward compatibility tapi jangan pakai
     */
    public function stockIn()
    {
        // Legacy: untuk backward compatibility
        // Jangan pakai relationship ini untuk customer delivery
        return null;
    }

    /* ================= ACCESSORS ================= */

    /**
     * ✅ NEW: Check if stock OUT already created
     */
    public function getHasStockOutAttribute()
    {
        return !is_null($this->stock_out_id);
    }

    /**
     * ✅ NEW: Get stock status
     */
    public function getStockStatusAttribute()
    {
        if ($this->has_stock_out) {
            return 'delivered';
        }
        
        return 'pending';
    }

    /**
     * Get formatted quantity
     */
    public function getFormattedQuantityAttribute()
    {
        return number_format($this->quantity, 2) . ' ' . $this->unit;
    }

    /* ================= SCOPES ================= */

    /**
     * ✅ NEW: Scope untuk item yang belum stock OUT
     */
    public function scopePendingStockOut($query)
    {
        return $query->whereNull('stock_out_id');
    }

    /**
     * ✅ NEW: Scope untuk item yang sudah stock OUT
     */
    public function scopeCompleted($query)
    {
        return $query->whereNotNull('stock_out_id');
    }

    /* ================= METHODS ================= */

    /**
     * ✅ NEW: Create stock OUT untuk delivery note item ini
     */
    public function createStockOut($batchId = null, $notes = null)
    {
        // Jangan create stock OUT jika sudah ada
        if ($this->has_stock_out) {
            throw new \Exception('Stock OUT already exists for this delivery note item');
        }

        $deliveryNote = $this->deliveryNote;

        $stockOut = \App\Models\StockOut::create([
            'company_id' => $deliveryNote->company_id,
            'delivery_note_id' => $deliveryNote->delivery_note_id,
            'product_id' => $this->product_id,
            'batch_id' => $batchId, // FIFO batch selection
            'out_date' => $deliveryNote->delivery_date,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'transaction_type' => 'sale', // Customer delivery = sale
            'notes' => $notes ?? "Stock OUT from delivery note {$deliveryNote->delivery_note_number}",
            'created_by' => \Illuminate\Support\Facades\Auth::id(),
        ]);

        // Update delivery_note_item dengan stock_out_id
        $this->update(['stock_out_id' => $stockOut->stock_out_id]);

        return $stockOut;
    }

    /**
     * ✅ NEW: Check if can create stock OUT
     */
    public function canCreateStockOut()
    {
        return !$this->has_stock_out && $this->quantity > 0;
    }
}