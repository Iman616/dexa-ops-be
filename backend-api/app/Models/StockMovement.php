<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $table = 'stock_movements';
    protected $primaryKey = 'movement_id';

    public $timestamps = false; // tabel hanya punya created_at
protected $fillable = [
    'product_id',
    'batch_id',
    'movement_type',
    'quantity',
    'unit_price',
    'unit_cost',
    'reference_id',
    'reference_type',
      'movement_date',
    'notes',
    'created_by',
    'created_at',  // ✅ tambah
    'updated_at',  // ✅ tambah
];


    protected $casts = [
        'quantity'    => 'decimal:2',
        'unit_price'  => 'decimal:2',
        'unit_cost'   => 'decimal:2',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
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

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
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

    public function scopeByCompany($query, $companyId) // ✅ TAMBAH
    {
        return $query->where('stock_movements.company_id', $companyId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /* ================= ACCESSORS ================= */

    // total_cost sudah generated di DB, tidak perlu dihitung manual
    // tapi tetap sediakan accessor sebagai fallback
    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->unit_price; // ✅ FIX
    }
}
