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
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // =============== RELATIONSHIPS ===============

    public function stockOuts()
    {
        return $this->hasMany(StockOut::class, 'customer_id', 'customer_id');
    }

    // =============== SCOPES ===============

    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('customer_name', 'LIKE', "%{$search}%")
              ->orWhere('contact_person', 'LIKE', "%{$search}%")
              ->orWhere('email', 'LIKE', "%{$search}%")
              ->orWhere('phone', 'LIKE', "%{$search}%");
        });
    }

    // =============== ACCESSORS ===============

    public function getDisplayNameAttribute()
    {
        return $this->contact_person 
            ? "{$this->customer_name} - {$this->contact_person}"
            : $this->customer_name;
    }
}
