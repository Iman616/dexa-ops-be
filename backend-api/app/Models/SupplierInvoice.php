<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    protected $table = 'supplierinvoices';
    protected $primaryKey = 'supplier_invoice_id'; // ✅ SNAKE_CASE
    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'supplier_po_id',
        'supplier_id',
        'invoice_number',
        'invoice_date',
        'total_amount',
        'paid_amount',
        'payment_status',
        'due_date',
        'payment_terms',
        'invoice_file_path',
        'notes',
        'created_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    // Relationships
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function supplierPO()
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_po_id', 'supplier_po_id');
    }

    public function items()
    {
        return $this->hasMany(SupplierInvoiceItem::class, 'supplier_invoice_id', 'supplier_invoice_id');
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class, 'supplier_invoice_id', 'supplier_invoice_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
