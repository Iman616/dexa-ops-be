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
        'use_ppn',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'use_ppn' => 'boolean',
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
        'tax_breakdown',
        'is_overdue',
    ];

    protected $attributes = [
        'payment_status' => 'unpaid',  // ✅ Default selalu unpaid
        'discount_amount' => 0,
        'currency' => 'IDR',
    ];
    /* ================= RELATIONSHIPS ================= */

    public function taxInvoices()
    {
        return $this->hasMany(TaxInvoice::class, 'invoice_id', 'invoice_id');
    }

    public function approvedTaxInvoices()
    {
        return $this->hasMany(TaxInvoice::class, 'invoice_id', 'invoice_id')
            ->where('status', 'approved');
    }

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
        if (!$this->due_date)
            return false;

        return $this->due_date->isPast() &&
            !in_array($this->payment_status, ['paid', 'completed']);
    }

    public function getTaxBreakdownAttribute(): array
    {
        $subtotal = (float) $this->subtotal;
        $discountAmount = (float) $this->discount_amount;
        $net = $subtotal - $discountAmount;
        $usePpn = (bool) ($this->use_ppn ?? true);

        if ($usePpn) {
            // ─── PPN: gunakan Tax model atau fallback DPP Nilai Lain ───
            $taxModel = $this->resolvePpnTaxModel();

            if ($taxModel) {
                $breakdown = $taxModel->buildBreakdown($subtotal, $discountAmount);
            } else {
                // Fallback exact fraction
                $taxRate = (float) ($this->tax_percentage ?? 11);
                $effectiveRate = $taxRate + 1;
                $dpp = round($net * ($taxRate / ($taxRate + 1)));
                $taxAmount = round($dpp * ($effectiveRate / 100));

                $breakdown = [
                    'tax_id' => null,
                    'tax_name' => "PPN {$taxRate}% (DPP Nilai Lain)",
                    'tax_type' => 'dpp_nilai_lain',
                    'tax_rate' => $taxRate,
                    'effective_rate' => $effectiveRate,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'net_subtotal' => $net,
                    'dpp' => $dpp,
                    'dpp_label' => "DPP Nilai Lain (Konversi {$taxRate}/{$effectiveRate})",
                    'dpp_multiplier' => round($taxRate / ($taxRate + 1), 4),
                    'tax_amount' => $taxAmount,
                    'total_with_tax' => $net + $taxAmount,
                ];
            }
        } else {
            // ─── Non-PPN: tidak ada pajak ───
            $breakdown = [
                'tax_id' => null,
                'tax_name' => 'Non-PPN',
                'tax_type' => 'non_ppn',
                'tax_rate' => 0,
                'effective_rate' => 0,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'net_subtotal' => $net,
                'dpp' => $net,
                'dpp_label' => null,
                'dpp_multiplier' => 1,
                'tax_amount' => 0,
                'total_with_tax' => $net,
            ];
        }

        // ─── Tambahkan info pembayaran ───
        $totalPaid = $this->relationLoaded('payments')
            ? $this->payments->where('status', 'success')->sum('amount')
            : $this->payments()->where('status', 'success')->sum('amount');

        $taxDeductions = $usePpn
            ? ($this->relationLoaded('taxInvoices')
                ? $this->taxInvoices->where('status', 'approved')->whereIn('tax_type', ['pph_21', 'pph_22', 'pph_23'])->sum('tax_amount')
                : $this->taxInvoices()->where('status', 'approved')->whereIn('tax_type', ['pph_21', 'pph_22', 'pph_23'])->sum('tax_amount'))
            : 0;

        $totalWithTax = $breakdown['total_with_tax'];
        $outstanding = max(0, $totalWithTax - $totalPaid);
        $netReceived = max(0, $totalPaid - $taxDeductions);
        $overpayment = max(0, $totalPaid - $totalWithTax);

        $breakdown['total_paid'] = $totalPaid;
        $breakdown['tax_deductions'] = $taxDeductions;
        $breakdown['outstanding'] = $outstanding;
        $breakdown['net_received'] = $netReceived;
        $breakdown['overpayment'] = $overpayment;

        return $breakdown;
    }

    private function resolvePpnTaxModel(): ?\App\Models\Tax
    {
        // 1. Coba cocokkan dari tax_percentage invoice ke tabel taxes yang aktif
        if (!empty($this->tax_percentage) && (float) $this->tax_percentage > 0) {
            $match = \App\Models\Tax::where('is_active', true)
                ->where('tax_rate', (float) $this->tax_percentage)
                ->orderBy('id', 'desc')
                ->first();

            if ($match)
                return $match;
        }

        // 2. Fallback: ambil tax PPN aktif terbaru
        return \App\Models\Tax::where('is_active', true)
            ->where(function ($q) {
                $q->where('tax_name', 'LIKE', '%PPN%')
                    ->orWhere('tax_name', 'LIKE', '%ppn%');
            })
            ->orderBy('id', 'desc')
            ->first();
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
        // ✅ Jika relasi sudah di-eager load, hitung dari collection (hindari N+1)
        if ($this->relationLoaded('payments')) {
            return $this->payments
                ->where('status', 'success')
                ->sum('amount');
        }

        // ✅ Fallback: query langsung
        return $this->payments()
            ->where('status', 'success')
            ->sum('amount');
    }

    public function getRemainingAmountAttribute()
    {
        $remaining = (float) $this->total_amount - (float) $this->paid_amount;
        return max(0, $remaining);
    }

    public function getDaysOverdueAttribute()
    {
        if (!$this->is_overdue)
            return 0;

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
        if (!$this->invoice_id || (float) $this->total_amount <= 0) {
            return;
        }

        $totalPaid = $this->payments()
            ->where('status', 'success')
            ->sum('amount');

        $remaining = (float) $this->total_amount - (float) $totalPaid;

        if ($remaining <= 0.01) {
            $status = 'paid';
        } elseif ((float) $totalPaid > 0) {
            $status = 'partial';
        } else {
            $status = 'unpaid';
        }

        // ✅ Pakai DB::table untuk hindari recursive event
        \Illuminate\Support\Facades\DB::table('invoices')
            ->where('invoice_id', $this->invoice_id)
            ->update([
                'payment_status' => $status,
                'updated_at' => now(),
            ]);

        // ✅ Refresh attribute di memory juga
        $this->payment_status = $status;
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


    }
}
