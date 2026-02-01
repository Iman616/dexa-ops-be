<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    protected $table = 'receipts';
    protected $primaryKey = 'receipt_id';

    // ✅ FIX: Ganti issued_by jadi created_by
    protected $fillable = [
        'company_id',
        'invoice_id',
        'payment_id',
        'receipt_number',
        'receipt_date', 
        'amount',
        'received_from',       
        'payment_for',        
        'notes',
        'created_by', // ✅ CHANGE: issued_by → created_by
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected $appends = ['formatted_amount', 'amount_in_words'];

    /* ================= RELATIONSHIPS ================= */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'payment_id');
    }

    // ✅ FIX: Ganti issued_by jadi created_by
    public function created_by_user()
    {
        return $this->createdByUser();
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    /* ================= ACCESSORS ================= */

    public function getFormattedAmountAttribute()
    {
        return 'Rp ' . number_format($this->amount, 0, ',', '.');
    }

    /**
     * Convert amount to Indonesian words (terbilang)
     */
    public function getAmountInWordsAttribute()
    {
        return $this->numberToWords($this->amount) . ' Rupiah';
    }

    // Get payment method dari payment relation
    public function getPaymentMethodAttribute()
    {
        return $this->payment ? $this->payment->payment_method : null;
    }

    public function getPaymentMethodLabelAttribute()
    {
        if (!$this->payment) return 'N/A';

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

        return $labels[$this->payment->payment_method] ?? $this->payment->payment_method;
    }

    /* ================= SCOPES ================= */

    public function scopeByInvoice($query, $invoiceId)
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopeByPayment($query, $paymentId)
    {
        return $query->where('payment_id', $paymentId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('receipt_date', [$startDate, $endDate]);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /* ================= HELPER METHODS ================= */

    /**
     * Simple number to words converter (Indonesian)
     */
    private function numberToWords($number)
    {
        $number = (int) $number;

        if ($number == 0) return 'Nol';

        $words = [
            '', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan',
            'Sepuluh', 'Sebelas', 'Dua Belas', 'Tiga Belas', 'Empat Belas', 'Lima Belas',
            'Enam Belas', 'Tujuh Belas', 'Delapan Belas', 'Sembilan Belas'
        ];

        if ($number < 20) {
            return $words[$number];
        }

        if ($number < 100) {
            $tens = floor($number / 10);
            $ones = $number % 10;
            return ($tens == 1 ? 'Sepuluh' : $words[$tens] . ' Puluh') . ($ones > 0 ? ' ' . $words[$ones] : '');
        }

        if ($number < 1000) {
            $hundreds = floor($number / 100);
            $remainder = $number % 100;
            return ($hundreds == 1 ? 'Seratus' : $words[$hundreds] . ' Ratus') . 
                   ($remainder > 0 ? ' ' . $this->numberToWords($remainder) : '');
        }

        if ($number < 1000000) {
            $thousands = floor($number / 1000);
            $remainder = $number % 1000;
            return ($thousands == 1 ? 'Seribu' : $this->numberToWords($thousands) . ' Ribu') . 
                   ($remainder > 0 ? ' ' . $this->numberToWords($remainder) : '');
        }

        if ($number < 1000000000) {
            $millions = floor($number / 1000000);
            $remainder = $number % 1000000;
            return $this->numberToWords($millions) . ' Juta' . 
                   ($remainder > 0 ? ' ' . $this->numberToWords($remainder) : '');
        }

        if ($number < 1000000000000) {
            $billions = floor($number / 1000000000);
            $remainder = $number % 1000000000;
            return $this->numberToWords($billions) . ' Miliar' . 
                   ($remainder > 0 ? ' ' . $this->numberToWords($remainder) : '');
        }

        return 'Angka terlalu besar';
    }
}
