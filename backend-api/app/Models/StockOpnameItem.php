<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOpnameItem extends Model
{
    protected $table      = 'stock_opname_items';
    protected $primaryKey = 'item_id';

    protected $fillable = [
        'opname_id',
        'product_id',
        'batch_id',
        // ✅ Breakdown kalkulasi system_quantity (untuk audit trail)
        'opening_quantity',   // dari stock_opening periode ini
        'stock_in_quantity',  // dari stock_in periode ini
        'stock_out_quantity', // dari stock_movements OUT periode ini
        // Opname fields
        'system_quantity',
        'physical_quantity',
        'difference',
        'adjustment_movement_id',
        'notes',
    ];

    protected $casts = [
        'system_quantity'    => 'float',
        'physical_quantity'  => 'float',
        'difference'         => 'float',
        // ✅ Tambah cast untuk kolom baru
        'opening_quantity'   => 'float',
        'stock_in_quantity'  => 'float',
        'stock_out_quantity' => 'float',
    ];

    protected $appends = ['status_selisih', 'system_quantity_breakdown'];

    /* ── Relationships ── */

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

    /* ── Accessors ── */

    public function getStatusSelisihAttribute(): string
    {
        if (is_null($this->difference)) return 'belum_dihitung';
        if ($this->difference > 0)      return 'lebih';   // fisik > sistem
        if ($this->difference < 0)      return 'kurang';  // fisik < sistem
        return 'sesuai';
    }

    /**
     * ✅ Breakdown detail bagaimana system_quantity dihitung
     * Berguna untuk ditampilkan di UI saat hover/tooltip
     */
    public function getSystemQuantityBreakdownAttribute(): array
    {
        return [
            'opening'    => (float) ($this->opening_quantity   ?? 0),
            'stock_in'   => (float) ($this->stock_in_quantity  ?? 0),
            'stock_out'  => (float) ($this->stock_out_quantity ?? 0),
            'system'     => (float) ($this->system_quantity    ?? 0),
            'formula'    => 'opening + stock_in - stock_out = system',
        ];
    }
}
