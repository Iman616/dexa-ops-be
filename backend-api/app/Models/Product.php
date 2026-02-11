<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';
    protected $primaryKey = 'product_id';

    protected $fillable = [
        'product_code',
        'product_name',
        'category',
        'product_type',      // ✅ NEW
        'brand',
        'unit',
        'supplier_id',
        'purchase_price',
        'selling_price',
        'is_precursor',
        'description',
    ];

    protected $casts = [
        'is_precursor' => 'boolean',
        'supplier_id' => 'integer',
        'purchase_price' => 'integer',
        'selling_price' => 'integer',
    ];

    /* ================= CONSTANTS ================= */
    
    const PRODUCT_TYPES = [
        'prekursor' => 'Prekursor (Form EUD)',
        'bbo' => 'BBO (Form EUD)',
        'ppi' => 'PPI (Form EUD)',
        'teknis' => 'Khusus (Teknis)',
        'glassware' => 'Mudah Pecah (Glassware)',
        'alat_lab' => 'Alat Lab',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'product_id', 'product_id');
    }

    public function stockBatches()
    {
        return $this->hasMany(StockBatch::class, 'product_id', 'product_id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class, 'product_id', 'product_id');
    }

    /* ================= SCOPES ================= */

    public function scopePrecursor($query)
    {
        return $query->where('is_precursor', true);
    }

    public function scopeByProductType($query, $type)
    {
        return $query->where('product_type', $type);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('product_code', 'like', "%{$search}%")
              ->orWhere('product_name', 'like', "%{$search}%")
              ->orWhere('category', 'like', "%{$search}%")
              ->orWhere('brand', 'like', "%{$search}%");
        });
    }

    public function scopeFilterByCategory($query, $category)
    {
        if ($category) {
            return $query->where('category', $category);
        }
        return $query;
    }

    /* ================= ACCESSORS ================= */

    public function getFormattedSellingPriceAttribute()
    {
        return 'Rp ' . number_format($this->selling_price, 0, ',', '.');
    }

    public function getFormattedPurchasePriceAttribute()
    {
        return 'Rp ' . number_format($this->purchase_price, 0, ',', '.');
    }

    public function getProductTypeLabelAttribute()
    {
        return self::PRODUCT_TYPES[$this->product_type] ?? $this->product_type;
    }

    /* ================= STATIC METHODS ================= */

    public static function getProductTypeOptions()
    {
        return collect(self::PRODUCT_TYPES)->map(function ($label, $value) {
            return [
                'value' => $value,
                'label' => $label,
            ];
        })->values();
    }
}