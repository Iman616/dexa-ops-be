<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockOpening extends Model
{
    use HasFactory;

    protected $table = 'stock_opening';
    protected $primaryKey = 'opening_id';

    protected $fillable = [
        'product_id',
        'batch_id',
        'quantity',
        'value',
        'opening_date',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'value' => 'string',
        'opening_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'batch_id', 'batch_id');
    }
}
