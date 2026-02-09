<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StockIn extends Model
{
    protected $table = 'stock_in';
    protected $primaryKey = 'stock_in_id';

    protected $fillable = [
        'company_id',
        'supplier_po_id',
        'delivery_note_number',
        'delivery_note_date',
        'delivery_note_file',
        'product_id',
        'batch_id',
        'quantity',
        'purchase_price',
        'received_date',
        'notes',
        'received_by',
        'receiver_name',          // ✅ NEW
        'receiver_position',      // ✅ NEW
        'received_datetime',      // ✅ NEW
    ];

    protected $casts = [
        'company_id' => 'integer',
        'quantity' => 'integer',
        'purchase_price' => 'decimal:2',
        'received_date' => 'date',
        'delivery_note_date' => 'date',
        'received_datetime' => 'datetime',  // ✅ NEW
        'received_by' => 'integer',
    ];

    protected $appends = [
        'total_value',
        'has_delivery_note',
        'delivery_note_url',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'batch_id', 'batch_id');
    }

    public function supplierPo()
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_po_id', 'supplier_po_id');
    }

    public function receivedByUser()
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }

    /* ================= ACCESSORS ================= */

    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->purchase_price;
    }

    public function getHasDeliveryNoteAttribute()
    {
        if (empty($this->delivery_note_file)) {
            return false;
        }
        return Storage::disk('public')->exists($this->delivery_note_file);
    }

    public function getDeliveryNoteUrlAttribute()
    {
        if (!$this->delivery_note_file) {
            return null;
        }

        if (Storage::disk('public')->exists($this->delivery_note_file)) {
            return url(Storage::url($this->delivery_note_file));
        }

        return null;
    }

    /* ================= FILE METHODS ================= */

    public function uploadDeliveryNote($file)
    {
        // Delete old file if exists
        $this->deleteDeliveryNote();

        // Generate unique filename
        $filename = 'DN_' . $this->stock_in_id . '_' . time() . '.' . $file->getClientOriginalExtension();

        // Store file
        $path = $file->storeAs('delivery_notes', $filename, 'public');

        // Update database
        $this->update(['delivery_note_file' => $path]);

        return $path;
    }

    public function deleteDeliveryNote()
    {
        if (!empty($this->delivery_note_file) && Storage::disk('public')->exists($this->delivery_note_file)) {
            Storage::disk('public')->delete($this->delivery_note_file);
        }
    }

    public function hasDeliveryNote()
    {
        return $this->has_delivery_note;
    }

    /* ================= BOOT ================= */

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($stockIn) {
            $stockIn->deleteDeliveryNote();
        });
    }

    /**
 * ✅ NEW: Returns ke supplier dari stock in ini
 */
public function stockReturns()
{
    return $this->hasMany(StockReturn::class, 'stock_in_id', 'stock_in_id');
}

/**
 * ✅ NEW: Check if has returns
 */
public function hasReturns()
{
    return $this->stockReturns()->exists();
}

/**
 * ✅ NEW: Create return to supplier
 */
public function createSupplierReturn($quantity, $returnReason, $notes = null)
{
    $companyCode = $this->company->company_code;
    $returnNumber = StockReturn::generateReturnNumber($companyCode, 'supplier_return');
    
    return StockReturn::create([
        'company_id' => $this->company_id,
        'return_type' => 'supplier_return',
        'stock_in_id' => $this->stock_in_id,
        'supplier_id' => $this->supplierPo->supplier_id ?? null,
        'product_id' => $this->product_id,
        'batch_id' => $this->batch_id,
        'return_number' => $returnNumber,
        'return_date' => now(),
        'quantity' => $quantity,
        'unit' => $this->product->unit,
        'return_reason' => $returnReason,
        'return_notes' => $notes,
        'return_value' => $quantity * $this->purchase_price,
        'status' => 'draft',
    ]);
}

}
