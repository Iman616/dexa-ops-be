<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceItem extends Model
{
    protected $table = 'supplierinvoiceitems';
    protected $primaryKey = 'item_id';
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'supplier_invoice_id',
        'product_id',
        'product_name',
        'quantity',
        'unit',
        'unit_price',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    // Relationships
    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id', 'supplier_invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
