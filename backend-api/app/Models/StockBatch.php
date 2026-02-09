<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockBatch extends Model
{
    use HasFactory;

    protected $table = 'stock_batches';
    protected $primaryKey = 'batch_id';

    protected $fillable = [
        'company_id',           // ✅ TAMBAHKAN INI
        'batch_number',
        'product_id',
        'quantity_available',
        'quantity_initial',
        'purchase_price',
        'supplier_id',
        'manufacture_date',
        'expiry_date',
        'alert_date',
        'received_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
        'alert_date' => 'date',
        'received_date' => 'datetime',
        'quantity_available' => 'integer',
        'quantity_initial' => 'integer',
        'purchase_price' => 'decimal:2',
    ];

    protected $appends = [
        'is_expired',
        'is_expiring_soon',
        'days_until_expiry',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'batch_id', 'batch_id');
    }

    public function stockOuts()
    {
        return $this->hasMany(StockOut::class, 'batch_id', 'batch_id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class, 'batch_id', 'batch_id');
    }

    /* ================= ACCESSORS ================= */

    public function getIsExpiredAttribute()
    {
        if (!$this->expiry_date) {
            return false;
        }
        return $this->expiry_date->isPast();
    }

    public function getIsExpiringSoonAttribute()
    {
        if (!$this->expiry_date) {
            return false;
        }
        // Expiring within 30 days
        return $this->expiry_date->isFuture() && 
               $this->expiry_date->diffInDays(now()) <= 30;
    }

    public function getDaysUntilExpiryAttribute()
    {
        if (!$this->expiry_date) {
            return null;
        }
        
        if ($this->expiry_date->isPast()) {
            return -1 * $this->expiry_date->diffInDays(now());
        }
        
        return $this->expiry_date->diffInDays(now());
    }

    /* ================= SCOPES ================= */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpired($query)
    {
        return $query->where('expiry_date', '<', now());
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->where('expiry_date', '>', now())
                     ->where('expiry_date', '<=', now()->addDays($days));
    }

    public function scopeAvailable($query)
    {
        return $query->where('quantity_available', '>', 0)
                     ->where('status', 'active');
    }

    public function scopeLowStock($query, $threshold = 10)
    {
        return $query->where('quantity_available', '<=', $threshold)
                     ->where('quantity_available', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('quantity_available', '<=', 0);
    }
}
