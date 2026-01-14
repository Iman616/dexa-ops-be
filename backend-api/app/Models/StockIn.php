<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockIn extends Model
{
    protected $table = 'stock_in';
    protected $primaryKey = 'stock_in_id';

    protected $fillable = [
        'supplier_po_id',
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
        'received_by' => 'integer',
    ];

    // Append computed attribute
    protected $appends = ['total_value'];

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

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by', 'id');
    }

    // Computed attribute untuk total_value
    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->purchase_price;
    }

    // Hapus boot method karena tidak perlu lagi
}