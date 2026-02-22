<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSupplier extends Model
{
    protected $table = 'product_suppliers';
    protected $primaryKey = 'product_supplier_id';

    protected $fillable = [
        'product_id',
        'supplier_id',
        'company_id',
        'purchase_price',
        'is_primary',
        'is_active',
        'last_purchase_date',
        'last_purchase_price',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'last_purchase_price' => 'decimal:2',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'last_purchase_date' => 'date',
    ];

    // Relationships
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeForProduct($query, int $productId, int $companyId)
    {
        return $query->where('product_id', $productId)
                     ->where('company_id', $companyId)
                     ->active();
    }

    /**
     * Get primary supplier untuk product di company tertentu
     */
    public static function getPrimarySupplier(int $productId, int $companyId)
    {
        return static::with('supplier')
            ->where('product_id', $productId)
            ->where('company_id', $companyId)
            ->where('is_primary', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Auto-update dari stock_in (dipanggil saat stock in)
     */
    public static function updateFromStockIn(
        int $productId,
        int $supplierId,
        int $companyId,
        float $purchasePrice,
        string $purchaseDate
    ) {
        // Cari atau buat record
        $record = static::firstOrCreate(
            [
                'product_id' => $productId,
                'supplier_id' => $supplierId,
                'company_id' => $companyId,
            ],
            [
                'purchase_price' => $purchasePrice,
                'is_primary' => true, // Set primary jika baru pertama kali
                'is_active' => true,
            ]
        );

        // Update last purchase info
        $record->update([
            'last_purchase_date' => $purchaseDate,
            'last_purchase_price' => $purchasePrice,
            'purchase_price' => $purchasePrice, // Update default price
        ]);

        return $record;
    }

    /**
     * Set supplier sebagai primary (supplier lain jadi backup)
     */
    public function setPrimary()
    {
        // Set semua supplier lain untuk produk ini jadi non-primary
        static::where('product_id', $this->product_id)
            ->where('company_id', $this->company_id)
            ->where('product_supplier_id', '!=', $this->product_supplier_id)
            ->update(['is_primary' => false]);

        // Set ini sebagai primary
        $this->update(['is_primary' => true]);

        return $this;
    }
}