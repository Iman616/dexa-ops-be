<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockBatch extends Model
{
    use HasFactory;

    protected $table = 'stock_batches';
    protected $primaryKey = 'batch_id';

    protected $fillable = [
        'batch_number',
        'product_id',
        'manufacture_date',
        'expiry_date',
    ];

    protected $casts = [
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'batch_id', 'batch_id');
    }
}
