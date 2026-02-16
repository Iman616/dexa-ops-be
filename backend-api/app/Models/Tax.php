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
        'is_active',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'tax_rate_formatted',
        'tax_rate_decimal',
    ];

    // =============== SCOPES ===============

    /**
     * Scope untuk tax yang aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope untuk search
     */
    public function scopeSearch($query, $search)
    {
        return $query->where('tax_name', 'LIKE', "%{$search}%");
    }

    // =============== ACCESSORS ===============

    /**
     * Get formatted tax rate (e.g., "11%")
     */
    public function getTaxRateFormattedAttribute()
    {
        return number_format($this->tax_rate, 2) . '%';
    }

    /**
     * Get tax rate as decimal (e.g., 0.11 for 11%)
     */
    public function getTaxRateDecimalAttribute()
    {
        return $this->tax_rate / 100;
    }

    // =============== METHODS ===============

    /**
     * Calculate tax amount from base amount
     */
    public function calculateTaxAmount($baseAmount)
    {
        return $baseAmount * $this->tax_rate_decimal;
    }

    /**
     * Calculate total with tax
     */
    public function calculateTotalWithTax($baseAmount)
    {
        return $baseAmount + $this->calculateTaxAmount($baseAmount);
    }

    /**
     * Toggle active status
     */
    public function toggleActive()
    {
        $this->is_active = !$this->is_active;
        $this->save();
        return $this;
    }
}
