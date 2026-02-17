<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';
    protected $primaryKey = 'supplier_id';

    protected $fillable = [
        'supplier_name',
        'contact_person',
        'email',
        'phone',
        'address',
    ];

public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'product_suppliers',
            'supplier_id',
            'product_id',
            'supplier_id',
            'product_id'
        )->withPivot(['purchase_price', 'priority', 'is_active', 'min_order_qty', 'lead_time_days'])
         ->withTimestamps();
    }

     public function supplierPurchaseOrders()
    {
        return $this->hasMany(SupplierPurchaseOrder::class, 'supplier_id', 'supplier_id');
    }
    public function purchaseOrders()
    {
        return $this->hasMany(SupplierPo::class, 'supplier_id', 'supplier_id');
    }

      /* ---- Static helpers ---- */

    /**
     * Cari supplier berdasarkan nama (exact / like)
     */
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

    /**
     * Cari banyak supplier berdasarkan nama
     */
    public static function searchByName(string $name)
    {
        return self::where('supplier_name', 'like', '%' . $name . '%')->get();
    }
}
