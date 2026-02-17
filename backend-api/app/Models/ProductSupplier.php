<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pivot model: satu produk bisa punya banyak agen/supplier
 */
class ProductSupplier extends Model
{
    protected $table = 'product_suppliers';
    protected $primaryKey = 'product_supplier_id';

    protected $fillable = [
        'product_id', 'supplier_id', 'purchase_price',
        'priority', 'is_active', 'supplier_product_code',
        'min_order_qty', 'lead_time_days', 'notes',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'is_active'      => 'boolean',
    ];

    /* ---- Relationships ---- */

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    /* ---- Static helpers ---- */

    /**
     * Cari semua agen aktif untuk sebuah produk, urut prioritas tertinggi dulu
     */
    public static function getActiveSuppliers(int $productId)
    {
        return self::with('supplier')
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();
    }

    /**
     * Cari agen berdasarkan supplier_name (fuzzy)
     */
    public static function findBySupplierName(int $productId, string $supplierName)
    {
        return self::with('supplier')
            ->where('product_id', $productId)
            ->whereHas('supplier', function ($q) use ($supplierName) {
                $q->where('supplier_name', 'like', '%' . $supplierName . '%');
            })
            ->where('is_active', true)
            ->first();
    }
}