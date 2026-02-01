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
    ];

    protected $casts = [
        'quantity' => 'integer',
        'purchase_price' => 'decimal:2',
        'received_date' => 'date',
        'delivery_note_date' => 'date',     
        'received_by' => 'integer',
    ];

    // ✅ Append computed attributes
    protected $appends = ['total_value', 'has_delivery_note', 'delivery_note_url', 'delivery_note_url']; 
    

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
        return $this->belongsTo(SupplierPO::class, 'supplier_po_id', 'supplier_po_id');
    }

    public function receivedByUser()
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }

    /* ================= ACCESSORS ================= */

    /**
     * Get total value (quantity * purchase_price)
     */
    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->purchase_price;
    }

    /**
     * ✅ Check if has delivery note file (FIXED)
     */
    public function getHasDeliveryNoteAttribute()
    {
        if (empty($this->delivery_note_file)) {
            return false;
        }
        
        // ✅ FIX: Specify disk 'public'
        return Storage::disk('public')->exists($this->delivery_note_file);
    }

    /**
     * ✅ Get delivery note file URL (FIXED)
     */
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

    /**
     * ✅ Upload delivery note file
     */
    public function uploadDeliveryNote($file)
    {
        // Delete old file if exists
        $this->deleteDeliveryNote();

        // Generate unique filename
        $filename = 'DN_' . $this->stock_in_id . '_' . time() . '.' . $file->getClientOriginalExtension();

        // Store file in storage/app/public/delivery_notes
        $path = $file->storeAs('delivery_notes', $filename, 'public');

        // Update database
        $this->update(['delivery_note_file' => $path]);

        return $path;
    }

    /**
     * ✅ Delete delivery note file (FIXED)
     */
    public function deleteDeliveryNote()
    {
        if (!empty($this->delivery_note_file) && Storage::disk('public')->exists($this->delivery_note_file)) {
            Storage::disk('public')->delete($this->delivery_note_file);
        }
    }

    /**
     * ✅ Check if has delivery note (method version)
     */
    public function hasDeliveryNote()
    {
        return $this->has_delivery_note;
    }

    /* ================= BOOT ================= */

    protected static function boot()
    {
        parent::boot();

        // ✅ Auto-delete file when record is deleted
        static::deleting(function ($stockIn) {
            $stockIn->deleteDeliveryNote();
        });
    }
}