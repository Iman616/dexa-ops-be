<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;


class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';
    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'invoice_id',
        'payment_number',
        'payment_type',
        'amount',
        'payment_date',
        'status',
        'payment_method',
        'bank_name',
        'account_number',
        'account_holder',
        'reference_number',
        'gateway_reference',
        'notes',
        'proof_file_path',
        'created_by',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    protected $appends = [
        'status_label',
        'formatted_amount',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }

    public function receipt()
    {
        return $this->hasOne(Receipt::class, 'payment_id', 'payment_id');
    }

    public function created_by_user()
    {
        return $this->createdByUser();
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function approved_by_user()
    {
        return $this->approvedByUser();
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function cancelled_by_user()
    {
        return $this->cancelledByUser();
    }

    public function cancelledByUser()
    {
        return $this->belongsTo(User::class, 'cancelled_by', 'user_id');
    }

    /* ================= ACCESSORS ================= */

    public function getStatusLabelAttribute()
    {
        $labels = [
            'pending' => 'Menunggu Konfirmasi',
            'success' => 'Berhasil',
            'failed' => 'Gagal',
            'cancelled' => 'Dibatalkan',
        ];
        return $labels[$this->status] ?? $this->status;
    }

    public function getFormattedAmountAttribute()
    {
        return 'Rp ' . number_format($this->amount, 0, ',', '.');
    }

    public function getPaymentMethodLabelAttribute()
    {
        $labels = [
            'cash' => 'Tunai',
            'transfer' => 'Transfer Bank',
            'va' => 'Virtual Account',
            'ewallet' => 'E-Wallet',
            'credit_card' => 'Kartu Kredit',
            'debit_card' => 'Kartu Debit',
            'cheque' => 'Cek/Giro',
            'other' => 'Lainnya',
        ];
        return $labels[$this->payment_method] ?? $this->payment_method;
    }

    /* ================= SCOPES ================= */

    public function scopeSuccess($query)
    {
        return $query->where('status', 'success');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByInvoice($query, $invoiceId)
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopeByMethod($query, $method)
    {
        return $query->where('payment_method', $method);
    }

    /* ================= METHODS ================= */

    /**
     * ✅ CHECK: Apakah payment bisa generate receipt
     */
    public function canGenerateReceipt()
    {
        // Harus status success dan belum punya receipt
        return $this->status === 'success' && !$this->receipt()->exists();
    }

    /**
     * ✅ GENERATE: Receipt dari payment
     */
 public function generateReceipt()
{
    if (!$this->canGenerateReceipt()) {
        return [
            'success' => false,
            'message' => 'Payment harus berstatus success dan belum memiliki kwitansi'
        ];
    }

    try {
        // Load invoice relation jika belum loaded
        if (!$this->relationLoaded('invoice')) {
            $this->load('invoice.customer', 'invoice.company');
        }

        // Auto-generate receipt number
        $lastReceipt = Receipt::whereYear('created_at', date('Y'))
            ->whereMonth('created_at', date('m'))
            ->orderBy('receipt_id', 'desc')
            ->first();

        $nextNumber = $lastReceipt ? (int)substr($lastReceipt->receipt_number, -4) + 1 : 1;
        $receiptNumber = 'KWT-' . date('Ym') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        // ✅ FIX: Ganti issued_by jadi created_by
        $receipt = Receipt::create([
            'company_id' => $this->invoice->company_id ?? null,
            'invoice_id' => $this->invoice_id,
            'payment_id' => $this->payment_id,
            'receipt_number' => $receiptNumber,
            'receipt_date' => $this->payment_date,
            'amount' => $this->amount,
            'received_from' => $this->invoice->customer->customer_name ?? 'N/A',
            'payment_for' => "Pembayaran Invoice {$this->invoice->invoice_number}",
            'notes' => $this->notes,
            'created_by' => Auth::id(), // ✅ CHANGE: issued_by → created_by
        ]);

        return [
            'success' => true,
            'message' => 'Kwitansi berhasil di-generate',
            'data' => $receipt->load(['invoice', 'payment'])
        ];

    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => 'Gagal generate kwitansi: ' . $e->getMessage()
        ];
    }
}


    /**
     * Check if payment can be approved
     */
    public function canBeApproved()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payment can be cancelled
     */
    public function canBeCancelled()
    {
        return !in_array($this->status, ['cancelled', 'failed']) && !$this->receipt()->exists();
    }

    /**
     * Approve payment
     */
    public function approve($notes = null)
    {
        if (!$this->canBeApproved()) {
            return [
                'success' => false,
                'message' => 'Hanya payment pending yang bisa di-approve'
            ];
        }

        try {
            $this->update([
                'status' => 'success',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'notes' => $notes ? $this->notes . "\n\nApproval Note: " . $notes : $this->notes,
            ]);

            // Update invoice payment status
            if ($this->invoice) {
                $this->invoice->updatePaymentStatus();
            }

            return [
                'success' => true,
                'message' => 'Payment berhasil di-approve'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Gagal approve payment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Cancel payment
     */
    public function cancel($reason)
    {
        if (!$this->canBeCancelled()) {
            return [
                'success' => false,
                'message' => 'Payment ini tidak bisa dibatalkan'
            ];
        }

        try {
            $this->update([
                'status' => 'cancelled',
                'cancelled_by' => Auth::id(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            // Update invoice payment status
            if ($this->invoice) {
                $this->invoice->updatePaymentStatus();
            }

            return [
                'success' => true,
                'message' => 'Payment berhasil dibatalkan'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Gagal cancel payment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get payment summary info
     */
    public function getSummary()
    {
        return [
            'payment_number' => $this->payment_number,
            'invoice_number' => $this->invoice->invoice_number ?? 'N/A',
            'customer' => $this->invoice->customer->customer_name ?? 'N/A',
            'amount' => $this->formatted_amount,
            'payment_date' => $this->payment_date->format('d/m/Y'),
            'payment_method' => $this->payment_method_label,
            'status' => $this->status_label,
            'has_receipt' => $this->receipt()->exists(),
            'receipt_number' => $this->receipt ? $this->receipt->receipt_number : null,
        ];
    }
}
