<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierPayment extends Model
{
    use SoftDeletes;

    protected $table = 'supplierpayments';
    protected $primaryKey = 'supplier_payment_id';
    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';
    const DELETED_AT = 'deleted_at';

    protected $fillable = [
        'supplier_invoice_id',
        'payment_number',
        'payment_type',
        'amount',
        'payment_date',
        'status',
        'payment_method',
        'bank_name',
        'account_number',
        'reference_number',
        'proof_file_path',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'approved_at' => 'datetime',
    ];

    // Relationships
    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id', 'supplier_invoice_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }
}
