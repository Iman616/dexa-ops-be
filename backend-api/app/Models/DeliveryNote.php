<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;


class DeliveryNote extends Model
{
    use HasFactory;

    protected $table = 'delivery_notes';
    protected $primaryKey = 'delivery_note_id';

   protected $fillable = [
    'company_id',
    'invoice_id',           
      'po_id',              // TAMBAHAN
        'quotation_id', 
    'delivery_note_number',
    'delivery_date',
    'recipient_name',
    'recipient_address',
    'notes',
    'status',
    'signed_name',
    'signed_position',
    'signed_city',
    'signed_at',
    'issued_by',
    'issued_at',
    'created_by',
];


protected $casts = [
    'delivery_date' => 'date',
    'signed_at'     => 'datetime',
    'issued_at'     => 'datetime',
];

    protected $appends = ['total_items', 'delivery_status'];

    /* ================= RELATIONSHIPS ================= */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    /**
     * ✅ NEW: Belongs to Invoice
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function items()
    {
        return $this->hasMany(DeliveryNoteItem::class, 'delivery_note_id', 'delivery_note_id');
    }

    /**
     * ✅ NEW: Delivery note bisa trigger stock OUT
     * Link ke stock_out via delivery_note_id
     */
    public function stockOuts()
    {
        return $this->hasMany(StockOut::class, 'delivery_note_id', 'delivery_note_id');
    }

    public function issuedByUser()
{
    return $this->belongsTo(User::class, 'issued_by', 'user_id');
}

public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id', 'quotation_id');
    }

    // EXISTING METHODS...
    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'delivery_note_id', 'delivery_note_id');
    }

    /* ================= ACCESSORS ================= */

    public function getTotalItemsAttribute()
    {
        return $this->items()->count();
    }

    /**
     * ✅ NEW: Get delivery status based on stock OUT
     */
    public function getDeliveryStatusAttribute()
    {
        if ($this->stockOuts()->exists()) {
            // Check receiving_condition dari stock_out
            $conditions = $this->stockOuts->pluck('receiving_condition')->unique();
            
            if ($conditions->contains('damaged')) {
                return 'damaged';
            }
            
            if ($conditions->contains('incomplete')) {
                return 'incomplete';
            }
            
            return 'delivered';
        }
        
        return 'pending';
    }

    /**
     * ✅ NEW: Get status label
     */
    public function getDeliveryStatusLabelAttribute()
    {
        $labels = [
            'pending' => 'Menunggu Pengiriman',
            'delivered' => 'Terkirim',
            'damaged' => 'Rusak',
            'incomplete' => 'Tidak Lengkap',
        ];
        
        return $labels[$this->delivery_status] ?? $this->delivery_status;
    }

    /* ================= SCOPES ================= */

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByInvoice($query, $invoiceId)
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('delivery_date', [$startDate, $endDate]);
    }

    /**
     * ✅ NEW: Scope untuk delivery yang pending (belum ada stock OUT)
     */
    public function scopePending($query)
    {
        return $query->whereDoesntHave('stockOuts');
    }

    /**
     * ✅ NEW: Scope untuk delivery yang sudah terkirim
     */
    public function scopeDelivered($query)
    {
        return $query->whereHas('stockOuts');
    }

    /* ================= METHODS ================= */

    /**
     * ✅ NEW: Process delivery (create stock OUT via StockOutController)
     */
    public function processDelivery($outDate = null, $notes = null)
    {        
        return [
            'success' => false,
            'message' => 'Use StockOutController::bulkFromDeliveryNote() instead',
            'delivery_note_id' => $this->delivery_note_id
        ];
    }
    public function canCreateStockOut()
    {
        // Tidak bisa create stock OUT jika:
        // 1. Belum ada items
        // 2. Sudah ada stock OUT
        
        return $this->items()->exists() && !$this->stockOuts()->exists();
    }

    /**
     * Get full summary of delivery note
     */
    public function getSummary()
    {
        $invoice = $this->invoice;
        
        return [
            'delivery_note_id' => $this->delivery_note_id,
            'delivery_note_number' => $this->delivery_note_number,
            'delivery_date' => $this->delivery_date->format('d/m/Y'),
            'invoice_number' => $invoice->invoice_number ?? 'N/A',
            'customer' => $invoice->purchaseOrder->customer->customer_name ?? 'N/A',
            'recipient_name' => $this->recipient_name,
            'recipient_address' => $this->recipient_address,
            'total_items' => $this->total_items,
            'delivery_status' => $this->delivery_status_label,
            'has_stock_out' => $this->stockOuts()->exists(),
        ];
    }

    /**
 * ✅ Mark delivery note as received
 * This will create stock OUT entries automatically
 */
public function markAsReceived($receivedDate = null, $receivingCondition = 'good', $notes = null)
{
    try {
        // Validasi: tidak bisa mark received jika sudah ada stock OUT
        if ($this->stockOuts()->exists()) {
            return [
                'success' => false,
                'message' => 'Delivery note already received (stock OUT exists)',
            ];
        }

        // Validasi: harus ada items
        if (!$this->items()->exists()) {
            return [
                'success' => false,
                'message' => 'Delivery note has no items',
            ];
        }

        $receivedDate = $receivedDate ?? now();
        
        // Create stock OUT untuk setiap item
        $stockOuts = [];
        foreach ($this->items as $item) {
            $stockOut = \App\Models\StockOut::create([
                'company_id' => $this->company_id,
                'delivery_note_id' => $this->delivery_note_id,
                'product_id' => $item->product_id,
                'out_date' => $receivedDate,
                'quantity' => $item->quantity,
                'receiving_condition' => $receivingCondition,
                'notes' => $notes ?? "Stock OUT from delivery note {$this->delivery_note_number}",
                'created_by' => Auth::id() ?? $this->created_by,
            ]);
            
            $stockOuts[] = $stockOut;
        }

        return [
            'success' => true,
            'message' => 'Delivery note marked as received',
            'stock_outs_created' => count($stockOuts),
            'data' => $this->fresh(['stockOuts']),
        ];

    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => 'Failed to mark as received',
            'error' => $e->getMessage(),
        ];
    }
}

public function isIssued()
{
    return $this->status === 'issued';
}

public function issue(array $data)
{
    if ($this->status === 'issued') {
        throw new \Exception('Delivery note already issued');
    }

    $this->update([
        'status'          => 'issued',
        'signed_name'     => $data['signed_name'],
        'signed_position' => $data['signed_position'],
        'signed_city'     => $data['signed_city'],
        'signed_at'       => now(),
        'issued_by'       => Auth::id(),
        'issued_at'       => now(),
    ]);

    return $this;
}


/**
 * ✅ Check if already received
 */
public function isReceived()
{
    return $this->stockOuts()->exists();
}

// Scope untuk cek apakah invoice sudah punya delivery note
public function scopeForInvoice($query, $invoiceId)
{
    return $query->where('invoice_id', $invoiceId);
}


/**
 * ✅ Cancel received status (delete stock OUTs)
 */
public function cancelReceived()
{
    try {
        $deletedCount = $this->stockOuts()->delete();
        
        return [
            'success' => true,
            'message' => 'Received status cancelled',
            'stock_outs_deleted' => $deletedCount,
        ];

    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => 'Failed to cancel received status',
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * ✅ NEW: Returns dari delivery note ini
 */
public function stockReturns()
{
    return $this->hasMany(StockReturn::class, 'delivery_note_id', 'delivery_note_id');
}

/**
 * ✅ NEW: Check if has returns
 */
public function hasReturns()
{
    return $this->stockReturns()->exists();
}

/**
 * ✅ NEW: Get total returned quantity
 */
public function getTotalReturnedQuantity()
{
    return $this->stockReturns()
        ->where('status', 'completed')
        ->sum('quantity');
}

/**
 * ✅ NEW: Create return from this delivery note
 */
public function createReturn($itemData, $returnReason, $notes = null)
{
    $companyCode = $this->company->company_code;
    $returnNumber = StockReturn::generateReturnNumber($companyCode, 'customer_return');
    
    return StockReturn::create([
        'company_id' => $this->company_id,
        'return_type' => 'customer_return',
        'delivery_note_id' => $this->delivery_note_id,
        'stock_out_id' => $itemData['stock_out_id'] ?? null,
        'customer_id' => $this->purchaseOrder->customer_id ?? null,
        'product_id' => $itemData['product_id'],
        'batch_id' => $itemData['batch_id'] ?? null,
        'return_number' => $returnNumber,
        'return_date' => now(),
        'quantity' => $itemData['quantity'],
        'unit' => $itemData['unit'] ?? 'pcs',
        'return_reason' => $returnReason,
        'return_notes' => $notes,
        'return_value' => $itemData['return_value'] ?? 0,
        'status' => 'draft',
    ]);
}

}
