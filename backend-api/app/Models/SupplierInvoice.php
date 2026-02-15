<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    // ✅ FIXED: Table name without underscore (matches database)
    protected $table = 'supplierinvoices'; // ✅ NOT 'supplier_invoices'
    protected $primaryKey = 'supplier_invoice_id';
    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'supplier_po_id',
        'supplier_id',
        'supplier_delivery_note_id',
        'invoice_number',
        'invoice_date',
        'total_amount',
        'paid_amount',
        'payment_status',
        'invoice_status',
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

    protected $appends = [
        'remaining_amount',
        'is_overdue',
        'days_overdue',
        'is_draft',
        'is_issued',
        'has_file',
    ];

    // ✅ Accessors
    public function getRemainingAmountAttribute()
    {
        return $this->total_amount - $this->paid_amount;
    }
    
    public function getIsOverdueAttribute()
    {
        if (!$this->due_date) {
            return false;
        }
        
        return $this->due_date->isPast() 
            && in_array($this->payment_status, ['unpaid', 'partial']);
    }
    
    public function getDaysOverdueAttribute()
    {
        if (!$this->is_overdue) {
            return 0;
        }
        
        return now()->diffInDays($this->due_date);
    }
    
    public function getIsDraftAttribute()
    {
        return $this->invoice_status === 'draft';
    }
    
    public function getIsIssuedAttribute()
    {
        return $this->invoice_status === 'issued';
    }
    
    public function getHasFileAttribute()
    {
        return !empty($this->invoice_file_path);
    }
    
    // ✅ Relationships
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }
    
    public function supplierPO()
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_po_id', 'supplier_po_id');
    }
    
    public function supplierDeliveryNote()
    {
        return $this->belongsTo(SupplierDeliveryNote::class, 'supplier_delivery_note_id', 'supplier_delivery_note_id');
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
    
    // ✅ Scopes
    public function scopeDraft($query)
    {
        return $query->where('invoice_status', 'draft');
    }
    
    public function scopeIssued($query)
    {
        return $query->where('invoice_status', 'issued');
    }
    
    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'unpaid');
    }
    
    public function scopePartial($query)
    {
        return $query->where('payment_status', 'partial');
    }
    
    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }
    
    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->whereIn('payment_status', ['unpaid', 'partial']);
    }
}