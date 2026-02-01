<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $table = 'stock_movements';
    protected $primaryKey = 'movement_id';

    protected $fillable = [
        'product_id',
        'batch_id',
        'movement_type',    // 'IN', 'OUT', 'ADJUSTMENT', 'RETURN'
        'quantity',
        'unit_cost',
        'reference_id',     // ID dari stock_in/stock_out/dll
        'reference_type',   // 'stock_in', 'stock_out', 'adjustment', dll
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'batch_id', 'batch_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    /* ================= SCOPES ================= */

    public function scopeByMovementType($query, $type)
    {
        return $query->where('movement_type', $type);
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByBatch($query, $batchId)
    {
        return $query->where('batch_id', $batchId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /* ================= ACCESSORS ================= */

    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->unit_cost;
    }
}