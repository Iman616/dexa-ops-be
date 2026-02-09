<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityType extends Model
{
    use HasFactory;

    protected $table = 'activity_types';
    protected $primaryKey = 'activity_type_id';

    protected $fillable = [
        'type_name',
        'type_code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get quotations with this activity type
     */
    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'activity_type_id', 'activity_type_id');
    }

     public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'activity_type_id', 'activity_type_id');
    }

    /**
     * Scope for active activity types only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
