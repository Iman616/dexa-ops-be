<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $table = 'customers';
    protected $primaryKey = 'customer_id';

    protected $fillable = [
        'customer_name',
        'contact_person',
        'email',
        'phone',
        'address',
        'npwp',        
        'nib',          
        'tax_address',  
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ NEW: Hidden attributes (untuk privacy)
    protected $hidden = [
        // Uncomment jika ingin menyembunyikan NPWP dari response
        // 'npwp',
    ];

    // ✅ NEW: Appends computed attributes
    protected $appends = [
        'display_name',
        'has_tax_info', // ✅ NEW
    ];

    // =============== RELATIONSHIPS ===============

    public function stockOuts()
    {
        return $this->hasMany(StockOut::class, 'customer_id', 'customer_id');
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'customer_id', 'customer_id');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'customer_id', 'customer_id');
    }

    // =============== SCOPES ===============

    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('customer_name', 'LIKE', "%{$search}%")
              ->orWhere('contact_person', 'LIKE', "%{$search}%")
              ->orWhere('email', 'LIKE', "%{$search}%")
              ->orWhere('phone', 'LIKE', "%{$search}%")
              ->orWhere('npwp', 'LIKE', "%{$search}%")    // ✅ NEW
              ->orWhere('nib', 'LIKE', "%{$search}%");    // ✅ NEW
        });
    }

    /**
     * ✅ NEW: Scope untuk filter customer dengan NPWP
     */
    public function scopeHasNpwp($query)
    {
        return $query->whereNotNull('npwp')->where('npwp', '!=', '');
    }

    /**
     * ✅ NEW: Scope untuk filter customer dengan NIB
     */
    public function scopeHasNib($query)
    {
        return $query->whereNotNull('nib')->where('nib', '!=', '');
    }

    // =============== ACCESSORS ===============

    public function getDisplayNameAttribute()
    {
        return $this->contact_person
            ? "{$this->customer_name} - {$this->contact_person}"
            : $this->customer_name;
    }

    /**
     * ✅ NEW: Check if customer has complete tax information
     */
    public function getHasTaxInfoAttribute()
    {
        return !empty($this->npwp) || !empty($this->nib);
    }

    /**
     * ✅ NEW: Format NPWP untuk display (XX.XXX.XXX.X-XXX.XXX)
     */
    public function getFormattedNpwpAttribute()
    {
        if (empty($this->npwp)) {
            return null;
        }

        // Remove any existing formatting
        $npwp = preg_replace('/[^0-9]/', '', $this->npwp);

        // Format: XX.XXX.XXX.X-XXX.XXX
        if (strlen($npwp) === 15) {
            return substr($npwp, 0, 2) . '.' .
                   substr($npwp, 2, 3) . '.' .
                   substr($npwp, 5, 3) . '.' .
                   substr($npwp, 8, 1) . '-' .
                   substr($npwp, 9, 3) . '.' .
                   substr($npwp, 12, 3);
        }

        return $this->npwp;
    }

    /**
     * ✅ NEW: Get tax address or fallback to regular address
     */
    public function getTaxAddressOrDefaultAttribute()
    {
        return $this->tax_address ?: $this->address;
    }

    // =============== MUTATORS ===============

    /**
     * ✅ NEW: Clean NPWP before saving (remove formatting)
     */
    public function setNpwpAttribute($value)
    {
        // Store only numbers
        $this->attributes['npwp'] = $value ? preg_replace('/[^0-9]/', '', $value) : null;
    }

    /**
     * ✅ NEW: Clean NIB before saving
     */
    public function setNibAttribute($value)
    {
        $this->attributes['nib'] = $value ? preg_replace('/[^0-9]/', '', $value) : null;
    }
}
