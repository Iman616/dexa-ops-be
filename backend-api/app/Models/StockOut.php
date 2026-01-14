<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOut extends Model
{
    protected $table = 'stock_out';
    protected $primaryKey = 'stock_out_id';

    protected $fillable = [
        'product_id',
        'batch_id',
        'transaction_type',
        'quantity',
        'selling_price',
        'out_date',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'selling_price' => 'string',
        'out_date' => 'date',
    ];

    // Append computed attribute
    protected $appends = ['total_value'];

    // Accessor for total_value
    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->selling_price;
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'batch_id', 'batch_id');
    }
}
