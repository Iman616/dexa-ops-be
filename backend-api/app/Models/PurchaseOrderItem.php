<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $table = 'purchase_order_items';
    protected $primaryKey = 'item_id';

    protected $fillable = [
        'po_id',
        'product_id',
        'product_name',
        'specification',
        'quantity',
        'unit',
        'unit_price',
        'discount_percent',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
    ];

    protected $appends = ['subtotal', 'discount_amount', 'total'];

    /* ================= RELATIONSHIPS ================= */

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /* ================= ACCESSORS ================= */

    public function getSubtotalAttribute()
    {
        $quantity = floatval($this->attributes['quantity'] ?? 0);
        $unitPrice = floatval($this->attributes['unit_price'] ?? 0);
        return $quantity * $unitPrice;
    }

    public function getDiscountAmountAttribute()
    {
        $subtotal = $this->subtotal;
        $discountPercent = floatval($this->attributes['discount_percent'] ?? 0);
        return $subtotal * ($discountPercent / 100);
    }

    public function getTotalAttribute()
    {
        return $this->subtotal - $this->discount_amount;
    }

    /* ================= STOCK VALIDATION (OPTIMIZED) ================= */

    /**
     * ✅ NEW: Get available stock for this item (cached)
     * Only queries when needed, cached for 5 minutes
     */
    public function getAvailableStock($companyId = null)
    {
        if (!$this->product_id) {
            return 0;
        }

        // Get company_id from PO if not provided
        if (!$companyId) {
            $this->loadMissing('purchaseOrder:po_id,company_id');
            $companyId = $this->purchaseOrder?->company_id;
        }

        if (!$companyId) {
            return 0;
        }

        // Cache key
        $cacheKey = "stock_available_{$this->product_id}_{$companyId}";

        // Cache for 5 minutes
        return Cache::remember($cacheKey, 300, function () use ($companyId) {
            return DB::table('stock_batches')
                ->where('product_id', $this->product_id)
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->sum('quantity_available') ?? 0;
        });
    }

    /**
     * ✅ NEW: Check stock status
     * Returns: 'sufficient', 'low', 'insufficient'
     */
    public function checkStockStatus($companyId = null)
    {
        $available = $this->getAvailableStock($companyId);
        $required = (float)$this->quantity;

        if ($available >= $required) {
            return 'sufficient'; // ✅ Green
        } elseif ($available > 0 && $available < $required) {
            return 'low'; // ⚠️ Yellow (partial)
        } else {
            return 'insufficient'; // ❌ Red (no stock)
        }
    }

    /**
     * ✅ NEW: Get stock info for API response
     */
    public function getStockInfo($companyId = null)
    {
        $available = $this->getAvailableStock($companyId);
        $required = (float)$this->quantity;
        $status = $this->checkStockStatus($companyId);

        return [
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'required' => $required,
            'available' => $available,
            'shortage' => max(0, $required - $available),
            'status' => $status,
            'is_sufficient' => $status === 'sufficient',
        ];
    }

    /**
     * ✅ NEW: Batch preload stock for multiple items
     * Prevents N+1 queries
     */
    public static function preloadStockData($items, $companyId)
    {
        if ($items->isEmpty()) {
            return [];
        }

        $productIds = $items->pluck('product_id')->unique()->filter();

        if ($productIds->isEmpty()) {
            return [];
        }

        // Single query for all products
        $stockData = DB::table('stock_batches')
            ->whereIn('product_id', $productIds)
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->select('product_id', DB::raw('SUM(quantity_available) as total_available'))
            ->groupBy('product_id')
            ->pluck('total_available', 'product_id');

        return $stockData;
    }
}