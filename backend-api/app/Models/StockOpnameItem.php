<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOpnameItem extends Model
{
    protected $table      = 'stock_opname_items';
    protected $primaryKey = 'item_id';

    protected $fillable = [
        'opname_id', 'product_id', 'batch_id',
        'system_quantity', 'physical_quantity',
        'difference', 'adjustment_movement_id', 'notes',
    ];

    protected $casts = [
        'system_quantity'   => 'float',
        'physical_quantity' => 'float',
        'difference'        => 'float',
    ];

    protected $appends = ['status_selisih'];

    public function opname()
    {
        return $this->belongsTo(StockOpname::class, 'opname_id', 'opname_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'batch_id', 'batch_id');
    }

    public function adjustmentMovement()
    {
        return $this->belongsTo(StockMovement::class, 'adjustment_movement_id', 'movement_id');
    }

    public function getStatusSelisihAttribute()
    {
        if (is_null($this->difference)) return 'belum_dihitung';
        if ($this->difference > 0)     return 'lebih';   // fisik > sistem
        if ($this->difference < 0)     return 'kurang';  // fisik < sistem
        return 'sesuai';
    }
}
