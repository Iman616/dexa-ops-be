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
        return $this->hasMany(Product::class, 'supplier_id', 'supplier_id');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(SupplierPo::class, 'supplier_id', 'supplier_id');
    }
}
