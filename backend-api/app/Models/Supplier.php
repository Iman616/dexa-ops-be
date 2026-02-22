<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $table      = 'suppliers';
    protected $primaryKey = 'supplier_id';

    protected $fillable = [
        'supplier_name',
        'contact_person',
        'email',
        'phone',
        'address',
        'supplier_type',        // ✅ tambah: manufacturer, distributor, dll
        'is_dropship_enabled',  // ✅ tambah
        'notes',                // ✅ tambah
    ];

    protected $casts = [
        'is_dropship_enabled' => 'boolean',
    ];

    /* ================= RELATIONSHIPS ================= */

    /**
     * Supplier bisa punya banyak product via pivot product_suppliers
     */
    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'product_suppliers',
            'supplier_id',
            'product_id',
            'supplier_id',
            'product_id'
        )
        ->withPivot([
            'purchase_price',
            'priority',
            'is_active',
            'is_primary',
            'min_order_qty',
            'lead_time_days',
        ])
        ->withTimestamps();
    }

    /**
     * ✅ FIXED: hapus purchaseOrders() yang pakai SupplierPo (tidak ada)
     * Cukup satu relasi ke SupplierPurchaseOrder
     */
    public function supplierPurchaseOrders()
    {
        return $this->hasMany(SupplierPurchaseOrder::class, 'supplier_id', 'supplier_id');
    }

    /**
     * Supplier punya banyak ProductSupplier (pivot records)
     */
    public function productSuppliers()
    {
        return $this->hasMany(ProductSupplier::class, 'supplier_id', 'supplier_id');
    }

    /**
     * Stock batches yang dikirim supplier ini
     */
    public function stockBatches()
    {
        return $this->hasMany(StockBatch::class, 'supplier_id', 'supplier_id');
    }

    /* ================= STATIC HELPERS ================= */

    public static function findByName(string $name, bool $exact = false): ?self
    {
        $query = self::query();

        if ($exact) {
            $query->where('supplier_name', $name);
        } else {
            $query->where('supplier_name', 'like', '%' . $name . '%');
        }

        return $query->first();
    }

    public static function searchByName(string $name)
    {
        return self::where('supplier_name', 'like', '%' . $name . '%')->get();
    }
}
