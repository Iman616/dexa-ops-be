<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;



class QuotationItem extends Model
{
    protected $table = 'quotation_items';
    protected $primaryKey = 'quotation_item_id';
    public $timestamps = false;

    protected $fillable = [
        'quotation_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2', // boleh cast, tapi JANGAN di-set
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id', 'quotation_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
