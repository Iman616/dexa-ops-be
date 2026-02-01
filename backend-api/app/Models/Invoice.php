<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Invoice extends Model
{
    use HasFactory;

    protected $table = 'invoices';
    protected $primaryKey = 'invoice_id';

    protected $fillable = [
        'company_id',
        'customer_id',
        'po_id',
        'proforma_invoice_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'subtotal',
        'tax_percentage',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'payment_status',
        'currency',
        'notes',
        'payment_terms',
        'delivery_terms',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
    ];

    protected $appends = [
        'is_overdue',
        'payment_status_label',
        'remaining_amount',
        'days_overdue',
        'paid_amount',
        'formatted_total',
        'formatted_remaining',
        'formatted_paid',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    // ✅ FIX: Tambah alias untuk snake_case
    public function purchase_order()
    {
        return $this->purchaseOrder();
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    // ✅ FIX: Tambah alias untuk snake_case
    public function proforma_invoice()
    {
        return $this->proformaInvoice();
    }

    public function proformaInvoice()
    {
        return $this->belongsTo(ProformaInvoice::class, 'proforma_invoice_id', 'proforma_id');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id', 'invoice_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'invoice_id', 'invoice_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'invoice_id', 'invoice_id');
    }

    // ✅ FIX: Tambah alias untuk snake_case
    public function delivery_notes()
    {
        return $this->deliveryNotes();
    }

    public function deliveryNotes()
    {
        return $this->hasMany(DeliveryNote::class, 'invoice_id', 'invoice_id');
    }

    // ✅ FIX: Tambah alias untuk snake_case
    public function created_by_user()
    {
        return $this->createdByUser();
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    /* ================= ACCESSORS ================= */

    public function getIsOverdueAttribute()
    {
        if (!$this->due_date) return false;
        
        return $this->due_date->isPast() && 
               !in_array($this->payment_status, ['paid', 'completed']);
    }

    public function getPaymentStatusLabelAttribute()
    {
        $labels = [
            'unpaid' => 'Belum Dibayar',
            'partial' => 'Dibayar Sebagian',
            'dp_paid' => 'DP Dibayar',
            'paid' => 'Lunas',
            'completed' => 'Selesai',
            'cancelled' => 'Dibatalkan',
        ];
        return $labels[$this->payment_status] ?? $this->payment_status;
    }

    /**
     * ✅ FIXED: Total yang sudah dibayar (hanya payment SUCCESS)
     */
    public function getPaidAmountAttribute()
    {
        return $this->payments()->where('status', 'success')->sum('amount');
    }

    public function getRemainingAmountAttribute()
    {
        $remaining = $this->total_amount - $this->paid_amount;
        return max(0, $remaining);
    }

    public function getDaysOverdueAttribute()
    {
        if (!$this->is_overdue) return 0;
        
        return $this->due_date->diffInDays(now());
    }

    public function getFormattedTotalAttribute()
    {
        return 'Rp ' . number_format($this->total_amount, 0, ',', '.');
    }

    public function getFormattedRemainingAttribute()
    {
        return 'Rp ' . number_format($this->remaining_amount, 0, ',', '.');
    }

    public function getFormattedPaidAttribute()
    {
        return 'Rp ' . number_format($this->paid_amount, 0, ',', '.');
    }

    /* ================= SCOPES ================= */

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'unpaid');
    }

    public function scopePartial($query)
    {
        return $query->whereIn('payment_status', ['partial', 'dp_paid']);
    }

    public function scopePaid($query)
    {
        return $query->whereIn('payment_status', ['paid', 'completed']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                     ->whereNotIn('payment_status', ['paid', 'completed', 'cancelled']);
    }

    public function scopeWithoutDeliveryNote($query)
    {
        return $query->whereDoesntHave('deliveryNotes');
    }

    public function scopeReadyForDelivery($query)
    {
        return $query->whereIn('payment_status', ['paid', 'completed'])
                     ->whereDoesntHave('deliveryNotes');
    }

    public function scopeFromProformaInvoice($query)
    {
        return $query->whereNotNull('proforma_invoice_id');
    }

    /* ================= METHODS ================= */

    /**
     * ✅ FIXED: Update payment status berdasarkan total payment SUCCESS
     */
    public function updatePaymentStatus()
    {
        // Hanya hitung payment SUCCESS
        $totalPaid = $this->payments()->where('status', 'success')->sum('amount');
        $remaining = $this->total_amount - $totalPaid;

        if ($remaining <= 0.01) { // Toleransi pembulatan
            $status = 'paid';
        } elseif ($totalPaid > 0) {
            $status = 'partial';
        } else {
            $status = 'unpaid';
        }

        $this->update(['payment_status' => $status]);
    }

    public function canCreateDeliveryNote()
    {
        return in_array($this->payment_status, ['paid', 'partial', 'completed']);
    }

    public function getSummary()
    {
        return [
            'invoice_number' => $this->invoice_number,
            'customer' => $this->customer->customer_name ?? 'N/A',
            'invoice_date' => $this->invoice_date->format('d/m/Y'),
            'due_date' => $this->due_date->format('d/m/Y'),
            'total_amount' => $this->formatted_total,
            'total_paid' => $this->formatted_paid,
            'remaining_amount' => $this->formatted_remaining,
            'payment_status' => $this->payment_status_label,
            'is_overdue' => $this->is_overdue,
            'days_overdue' => $this->days_overdue,
            'total_payments' => $this->payments->count(),
            'total_delivery_notes' => $this->deliveryNotes->count(),
            'from_proforma_invoice' => !is_null($this->proforma_invoice_id),
        ];
    }

    public function calculateTotalFromItems()
    {
        $subtotal = $this->items->sum(function ($item) {
            return $item->quantity * $item->unit_price;
        });

        $tax = $subtotal * ($this->tax_percentage / 100);
        $total = $subtotal + $tax - $this->discount_amount;

        $this->update([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
        ]);
    }

    /* ================= BOOT ================= */

    protected static function boot()
    {
        parent::boot();

        static::saved(function ($invoice) {
            if ($invoice->wasChanged('total_amount')) {
                $invoice->updatePaymentStatus();
            }
        });
    }
}
