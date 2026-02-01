<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOut extends Model
{
    protected $table = 'stock_out';
    protected $primaryKey = 'stock_out_id';

    protected $fillable = [
    'company_id',
    'product_id',
    'batch_id',
    'customer_id',
    'delivery_note_id',
    'transaction_type',
    'quantity',
    'selling_price',
    'out_date',
    'notes',
    'processed_by',
    'received_by',
    'receiving_location',
    'receiving_condition',
    'receiving_datetime',
];


    protected $casts = [
        'quantity' => 'integer',
        'selling_price' => 'decimal:2',
        'out_date' => 'date',
        'receiving_datetime' => 'datetime'
    ];

    protected $appends = ['total_value', 'hpp'];

    /* ================= ACCESSORS ================= */

    /**
     * Calculate total value (qty × selling price)
     */
    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->selling_price;
    }

    /**
     * ✅ NEW: Calculate HPP (Harga Pokok Penjualan)
     * HPP = qty × purchase_price dari batch
     */
    public function getHppAttribute()
    {
        if ($this->batch) {
            return $this->quantity * $this->batch->purchase_price;
        }
        return 0;
    }

    /**
     * ✅ NEW: Calculate profit margin
     */
    public function getProfitAttribute()
    {
        return $this->total_value - $this->hpp;
    }

    /**
     * ✅ NEW: Calculate profit percentage
     */
    public function getProfitPercentageAttribute()
    {
        if ($this->hpp > 0) {
            return round(($this->profit / $this->hpp) * 100, 2);
        }
        return 0;
    }

    /* ================= RELATIONSHIPS ================= */

    /**
     * Belongs to Product
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /**
     * Belongs to Stock Batch (untuk FIFO)
     */
    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'batch_id', 'batch_id');
    }

    /**
     * Belongs to Customer
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    /**
     * Belongs to User (who processed the transaction)
     */
    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by', 'user_id');
    }

    /**
     * ✅ NEW: Belongs to Delivery Note (Surat Jalan)
     */
    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id', 'delivery_note_id');
    }

    /**
     * ✅ NEW: Has many Document Attachments (bukti terima, foto, TTD)
     */
   public function document_attachments()
{
    return $this->hasMany(
        DocumentAttachment::class,
        'reference_id',
        'stock_out_id'
    )->where('document_type', 'stock_out');
}


    /**
     * ✅ NEW: Has many Stock Movements (audit trail)
     */
    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class, 'reference_id', 'stock_out_id')
                    ->where('reference_type', 'stock_out')
                    ->where('movement_type', 'OUT');
    }

    /* ================= SCOPES ================= */

    /**
     * ✅ NEW: Scope untuk gudang staff only
     */
    public function scopeGudangOnly($query)
    {
        return $query->whereHas('processedBy', function($q) {
            $q->where('role_id', 4); // 4 = Staff Gudang
        });
    }

    /**
     * Scope by customer
     */
    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('out_date', [$startDate, $endDate]);
    }

    /**
     * Scope by transaction type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('transaction_type', $type);
    }

    /**
     * ✅ NEW: Scope by delivery note
     */
    public function scopeByDeliveryNote($query, $deliveryNoteId)
    {
        return $query->where('delivery_note_id', $deliveryNoteId);
    }

    /**
     * ✅ NEW: Scope by receiving condition
     */
    public function scopeByCondition($query, $condition)
    {
        return $query->where('receiving_condition', $condition);
    }

    /**
     * ✅ NEW: Scope for damaged goods
     */
    public function scopeDamagedGoods($query)
    {
        return $query->where('receiving_condition', 'damaged');
    }

    /**
     * ✅ NEW: Scope for partial deliveries
     */
    public function scopePartialDeliveries($query)
    {
        return $query->where('receiving_condition', 'partial');
    }

    /* ================= HELPER METHODS ================= */

    /**
     * ✅ NEW: Check if has proof documents
     */
    public function hasProof()
    {
        return $this->documentAttachments()->count() > 0;
    }

    /**
     * ✅ NEW: Get proof documents only
     */
    public function getProofDocuments()
    {
        return $this->documentAttachments()
                    ->whereIn('document_type', ['signature', 'photo'])
                    ->get();
    }

    /**
     * ✅ NEW: Check if received
     */
    public function isReceived()
    {
        return !empty($this->received_by) && !empty($this->receiving_datetime);
    }

    /**
     * ✅ NEW: Check if good condition
     */
    public function isGoodCondition()
    {
        return $this->receiving_condition === 'good';
    }

    /**
     * ✅ NEW: Check if damaged
     */
    public function isDamaged()
    {
        return $this->receiving_condition === 'damaged';
    }

    /**
     * ✅ NEW: Get formatted receiving info
     */
    public function getReceivingInfo()
    {
        if (!$this->isReceived()) {
            return 'Belum diterima';
        }

        return [
            'received_by' => $this->received_by,
            'location' => $this->receiving_location,
            'condition' => ucfirst($this->receiving_condition),
            'datetime' => $this->receiving_datetime->format('d-m-Y H:i'),
            'has_proof' => $this->hasProof()
        ];
    }
}