<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tax extends Model
{
    use HasFactory;

    protected $table = 'taxes';
    protected $primaryKey = 'id';

    protected $fillable = [
        'tax_name',
        'tax_rate',
        'tax_type',
        'dpp_multiplier',
        'is_include',
        'is_active',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'dpp_multiplier' => 'decimal:4',
        'is_include' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'tax_rate_formatted',
        'tax_rate_decimal',
        'effective_rate',
        'label',
    ];

    // =============== SCOPES ===============

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('tax_name', 'LIKE', "%{$search}%");
    }

    // =============== ACCESSORS ===============

    public function getTaxRateFormattedAttribute(): string
    {
        return number_format($this->tax_rate, 0) . '%';
    }

    public function getTaxRateDecimalAttribute(): float
    {
        return (float) $this->tax_rate / 100;
    }

    /**
     * Tarif efektif yang dikenakan ke pembeli.
     * DPP Nilai Lain: tax_rate + 1  (11% -> PPN 12%)
     * Standard      : tax_rate
     */
    public function getEffectiveRateAttribute(): float
    {
        if ($this->isDppNilaiLain()) {
            return (float) $this->tax_rate + 1;
        }
        return (float) $this->tax_rate;
    }

    /**
     * Label untuk ditampilkan di invoice.
     * Contoh: "PPN 12% (DPP Nilai Lain)" atau "PPN 11%"
     */
    public function getLabelAttribute(): string
    {
        if ($this->isDppNilaiLain()) {
            return "PPN {$this->effective_rate}% (DPP Nilai Lain)";
        }
        return "PPN {$this->tax_rate}%";
    }

    // =============== HELPERS ===============

    public function isDppNilaiLain(): bool
    {
        return $this->tax_type === 'dpp_nilai_lain';
    }

    /**
     * DPP multiplier akurat.
     * Prioritas: kolom dpp_multiplier (DB) -> hitung dari tax_rate
     */
    public function getDppMultiplier(): float
    {
        if (!empty($this->dpp_multiplier) && (float) $this->dpp_multiplier > 0) {
            return (float) $this->dpp_multiplier;
        }
        $rate = (float) $this->tax_rate;
        return $rate > 0 ? $rate / ($rate + 1) : 1.0;
    }

    // =============== CALCULATION METHODS ===============

    /**
     * Hitung DPP (Dasar Pengenaan Pajak).
     *
     * DPP Nilai Lain : subtotal x multiplier  -> 208,000 x (11/12) = 190,667
     * Standard       : subtotal itu sendiri
     */
    public function calculateDpp(float $netAmount): float
    {
        if ($this->tax_type !== 'dpp_nilai_lain') {
            return $netAmount;
        }
        $rate = (float) $this->tax_rate;

        return round($netAmount * ($rate / ($rate + 1)));
    }

    /**
     * Hitung PPN dari subtotal.
     *
     * DPP Nilai Lain : DPP x effective_rate% -> 190,667 x 12% = 22,880
     * Standard       : subtotal x tax_rate%
     */
    public function calculateTaxAmount(float $subtotal): float
    {
        if ($this->isDppNilaiLain()) {
            $dpp = $this->calculateDpp($subtotal);
            return round($dpp * ($this->effective_rate / 100), 0);
        }
        return round($subtotal * $this->tax_rate_decimal, 0);
    }

    /**
     * Total harga + pajak.
     */
    public function calculateTotalWithTax(float $subtotal): float
    {
        return $subtotal + $this->calculateTaxAmount($subtotal);
    }

    /**
     * Breakdown lengkap untuk ditampilkan di invoice PDF.
     * Cocok format gambar invoice.
     */
    public function buildBreakdown(float $subtotal, float $discountAmount = 0): array
    {
        $net = $subtotal - $discountAmount;

        $dpp = $this->calculateDpp($net);
        $taxAmount = $this->calculateTaxAmount($net);
        $totalWithTax = $net + $taxAmount;

        $breakdown = [
            'tax_id' => $this->id,
            'tax_name' => $this->tax_name,
            'tax_type' => $this->tax_type ?? 'standard',
            'tax_rate' => (float) $this->tax_rate,
            'effective_rate' => $this->effective_rate,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'net_subtotal' => $net,
            'dpp' => $dpp,
            'tax_amount' => $taxAmount,
            'total_with_tax' => $totalWithTax,
        ];

        // Tambah info konversi untuk DPP Nilai Lain, e.g. label "Konversi 11/12"
        if ($this->isDppNilaiLain()) {
            $r = (int) $this->tax_rate;
            $e = (int) $this->effective_rate;
            $breakdown['dpp_label'] = "DPP Nilai Lain (Konversi {$r}/{$e})";
            $breakdown['dpp_multiplier'] = $this->getDppMultiplier();
        }

        return $breakdown;
    }

    public function toggleActive(): static
    {
        $this->is_active = !$this->is_active;
        $this->save();
        return $this;
    }
}
