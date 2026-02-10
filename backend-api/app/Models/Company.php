<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;


class Company extends Model
{
    protected $table = 'companies';
    protected $primaryKey = 'company_id';

    protected $fillable = [
        'company_code', 'company_name', 'address', 'phone', 'email',
        'logo_path', 'website', 'city', 'pic_name', 'bank_name', 'bank_account',
        'is_active'
    ];


    protected $casts = [
        'is_active' => 'boolean',
    ];

     protected $appends = ['logo_url'];

    // ⬇️ DAN METHOD INI
    public function getLogoUrlAttribute()
    {
        if (!$this->logo_path) {
            return null;
        }

        return asset('storage/' . $this->logo_path);
    }

    

    /* ================= RELATIONSHIPS ================= */

    /**
     * Users yang bisa akses company ini
     */
 

      public function users()
    {
        return $this->belongsToMany(
            User::class,
            'user_companies',
            'company_id',
            'user_id'
        )->withPivot('is_default')
          ->withTimestamps();
    }

    /**
     * Stock batches di company ini
     */
    public function stockBatches()
    {
        return $this->hasMany(StockBatch::class, 'company_id', 'company_id');
    }

    /**
     * Stock IN transactions
     */
    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'company_id', 'company_id');
    }

    /**
     * Stock OUT transactions
     */
    public function stockOuts()
    {
        return $this->hasMany(StockOut::class, 'company_id', 'company_id');
    }

    /**
     * Quotations
     */
    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'company_id', 'company_id');
    }

    /**
     * Purchase Orders (from customers)
     */
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'company_id', 'company_id');
    }

    /**
     * Invoices
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'company_id', 'company_id');
    }

    /**
     * Delivery Notes
     */
    public function deliveryNotes()
    {
        return $this->hasMany(DeliveryNote::class, 'company_id', 'company_id');
    }
}
