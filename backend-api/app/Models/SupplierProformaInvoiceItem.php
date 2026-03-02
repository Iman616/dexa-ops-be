<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProformaInvoiceItem extends Model
{
    protected $table = 'supplier_proforma_invoice_items';
    protected $primaryKey = 'item_id';
    public $timestamps = false;

    protected $fillable = [
        'supplier_proforma_id', 'product_id', 'product_name', 'product_description',
        'quantity', 'unit', 'unit_price', 'notes'
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
    ];

    protected $appends = ['subtotal', 'formatted_subtotal'];

    public function proforma(): BelongsTo
    {
        return $this->belongsTo(SupplierProformaInvoice::class, 'supplier_proforma_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getSubtotalAttribute(): float
    {
        return $this->quantity * $this->unit_price;
    }

    public function getFormattedSubtotalAttribute(): string
    {
        return 'Rp ' . number_format($this->subtotal, 0, ',', '.');
    }
}
