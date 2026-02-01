<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProformaInvoiceItem Model
 * 
 * @property int $item_id
 * @property int $proforma_id
 * @property int $product_id
 * @property string $product_name
 * @property string|null $product_description
 * @property float $quantity
 * @property string $unit
 * @property float $unit_price
 * @property float $subtotal (computed)
 * @property string|null $notes
 * @property string $created_at
 * 
 * @property-read ProformaInvoice $proformaInvoice
 * @property-read Product $product
 * 
 * @author Claude AI
 * @date 2026-01-28
 */
class ProformaInvoiceItem extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'proforma_invoice_items';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'item_id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'proforma_id',
        'product_id',
        'product_name',
        'product_description',
        'quantity',
        'unit',
        'unit_price',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /**
     * The attributes that should be appended to the model's array form.
     *
     * @var array<string>
     */
    protected $appends = [
        'subtotal',
        'formatted_quantity',
        'formatted_unit_price',
        'formatted_subtotal',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-set created_at on create
        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the proforma invoice that owns the item.
     */
    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class, 'proforma_id', 'proforma_id');
    }

    /**
     * Get the product associated with the item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get the subtotal (computed field).
     *
     * @return float
     */
    public function getSubtotalAttribute(): float
    {
        return $this->quantity * $this->unit_price;
    }

    /**
     * Get formatted quantity.
     *
     * @return string
     */
    public function getFormattedQuantityAttribute(): string
    {
        return number_format($this->quantity, 2, ',', '.');
    }

    /**
     * Get formatted unit price.
     *
     * @return string
     */
    public function getFormattedUnitPriceAttribute(): string
    {
        return 'Rp ' . number_format($this->unit_price, 0, ',', '.');
    }

    /**
     * Get formatted subtotal.
     *
     * @return string
     */
    public function getFormattedSubtotalAttribute(): string
    {
        return 'Rp ' . number_format($this->subtotal, 0, ',', '.');
    }

    // ==================== SCOPES ====================

    /**
     * Scope a query to filter by proforma invoice.
     */
    public function scopeByProforma($query, $proformaId)
    {
        return $query->where('proforma_id', $proformaId);
    }

    /**
     * Scope a query to filter by product.
     */
    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    // ==================== METHODS ====================

    /**
     * Update quantity and recalculate.
     *
     * @param float $quantity
     * @return bool
     */
    public function updateQuantity(float $quantity): bool
    {
        $this->quantity = $quantity;
        return $this->save();
    }

    /**
     * Update unit price and recalculate.
     *
     * @param float $unitPrice
     * @return bool
     */
    public function updateUnitPrice(float $unitPrice): bool
    {
        $this->unit_price = $unitPrice;
        return $this->save();
    }

    /**
     * Check if product is still available in stock.
     *
     * @return bool
     */
    public function isProductAvailable(): bool
    {
        if (!$this->product) {
            return false;
        }

        return $this->product->stock_quantity >= $this->quantity;
    }

    /**
     * Get stock availability status.
     *
     * @return array{available: bool, stock: float, required: float, shortage: float}
     */
    public function getStockAvailability(): array
    {
        $stock = $this->product->stock_quantity ?? 0;
        $shortage = max(0, $this->quantity - $stock);

        return [
            'available' => $stock >= $this->quantity,
            'stock' => $stock,
            'required' => $this->quantity,
            'shortage' => $shortage,
        ];
    }
}