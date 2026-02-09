<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class SupplierDeliveryNote extends Model
{
    use SoftDeletes;

    protected $table = 'supplier_delivery_notes';
    protected $primaryKey = 'supplier_delivery_note_id';

    protected $fillable = [
        'company_id',
        'supplier_id',
        'supplier_po_id',
        'delivery_note_number',
        'delivery_note_date',
        'delivery_note_file',
        'status',
        'receiver_name',
        'receiver_position',
        'received_datetime',
        'received_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'delivery_note_date' => 'date',
        'received_datetime' => 'datetime',
    ];

    protected $appends = [
        'delivery_note_url',
        'total_items',
        'total_quantity',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function supplierPo()
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_po_id', 'supplier_po_id');
    }

    public function items()
    {
        return $this->hasMany(SupplierDeliveryNoteItem::class, 'supplier_delivery_note_id', 'supplier_delivery_note_id');
    }

    public function receivedByUser()
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'supplier_delivery_note_id', 'supplier_delivery_note_id');
    }

    /* ================= ACCESSORS ================= */

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

    public function getTotalItemsAttribute()
    {
        return $this->items()->count();
    }

    public function getTotalQuantityAttribute()
    {
        return $this->items()->sum('quantity');
    }

    /* ================= FILE METHODS ================= */

    public function uploadDeliveryNote($file)
    {
        $this->deleteDeliveryNote();

        $filename = 'SDN_' . $this->supplier_delivery_note_id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('supplier_delivery_notes', $filename, 'public');

        $this->update(['delivery_note_file' => $path]);

        return $path;
    }

    public function deleteDeliveryNote()
    {
        if (!empty($this->delivery_note_file) && Storage::disk('public')->exists($this->delivery_note_file)) {
            Storage::disk('public')->delete($this->delivery_note_file);
        }
    }

    /* ================= BOOT ================= */

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($deliveryNote) {
            $deliveryNote->deleteDeliveryNote();
        });
    }
}
