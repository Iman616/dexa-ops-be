<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StockReturn extends Model
{
    use SoftDeletes;

    protected $table = 'stock_returns';
    protected $primaryKey = 'return_id';

    protected $fillable = [
        'company_id',
        'return_type',
        'delivery_note_id',
        'stock_out_id',
        'stock_in_id',
        'customer_id',
        'supplier_id',
        'product_id',
        'batch_id',
        'return_number',
        'return_date',
        'quantity',
        'unit',
        'return_reason',
        'return_notes',
        'status',
        'approved_by',
        'approved_at',
        'approval_notes',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'return_value',
        'refund_method',
        'refund_amount',
        'refund_status',
        'proof_file_path',
        'signed_by',
        'signed_position',
        'signed_at',
        'processed_by',
        'processed_at',
        'processing_notes',
        'created_by',
    ];

    protected $casts = [
        'return_date' => 'date',
        'quantity' => 'decimal:2',
        'return_value' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'signed_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    protected $appends = [
        'is_customer_return',
        'is_supplier_return',
        'can_be_approved',
        'can_be_processed',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id', 'delivery_note_id');
    }

    public function stockOut()
    {
        return $this->belongsTo(StockOut::class, 'stock_out_id', 'stock_out_id');
    }

    public function stockIn()
    {
        return $this->belongsTo(StockIn::class, 'stock_in_id', 'stock_in_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'batch_id', 'batch_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function rejectedByUser()
    {
        return $this->belongsTo(User::class, 'rejected_by', 'user_id');
    }

    public function processedByUser()
    {
        return $this->belongsTo(User::class, 'processed_by', 'user_id');
    }

    /**
     * Stock movement yang di-generate dari return ini
     */
    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class, 'reference_id', 'return_id')
            ->where('reference_type', 'stock_return');
    }

    /* ================= ACCESSORS ================= */

    public function getIsCustomerReturnAttribute()
    {
        return $this->return_type === 'customer_return';
    }

    public function getIsSupplierReturnAttribute()
    {
        return $this->return_type === 'supplier_return';
    }

    public function getCanBeApprovedAttribute()
    {
        return in_array($this->status, ['draft', 'pending']);
    }

    public function getCanBeProcessedAttribute()
    {
        return $this->status === 'approved';
    }

    public function getReturnValueFormattedAttribute()
    {
        return 'Rp ' . number_format($this->return_value, 0, ',', '.');
    }

    public function getRefundAmountFormattedAttribute()
    {
        return 'Rp ' . number_format($this->refund_amount, 0, ',', '.');
    }

    /* ================= SCOPES ================= */

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeCustomerReturns($query)
    {
        return $query->where('return_type', 'customer_return');
    }

    public function scopeSupplierReturns($query)
    {
        return $query->where('return_type', 'supplier_return');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['draft', 'pending']);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeBySupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('return_date', [$startDate, $endDate]);
    }

    public function scopeByReason($query, $reason)
    {
        return $query->where('return_reason', $reason);
    }

    /* ================= STATIC METHODS - GENERATE RETURN NUMBER ================= */

    public static function generateReturnNumber($companyCode, $returnType)
    {
        $prefix = $returnType === 'customer_return' ? 'RET-C' : 'RET-S';
        $yearMonth = now()->format('Ym');
        
        $lastReturn = self::where('return_number', 'LIKE', "{$prefix}-{$companyCode}-{$yearMonth}-%")
            ->orderBy('return_number', 'desc')
            ->first();
        
        if ($lastReturn) {
            $lastNumber = (int) substr($lastReturn->return_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }
        
        return "{$prefix}-{$companyCode}-{$yearMonth}-{$newNumber}";
    }

    /* ================= WORKFLOW METHODS ================= */

    /**
     * Submit return untuk approval
     */
    public function submit()
    {
        if ($this->status !== 'draft') {
            throw new \Exception('Only draft returns can be submitted');
        }

        $this->update(['status' => 'pending']);
        
        return $this;
    }

    /**
     * Approve return
     */
    public function approve($notes = null)
    {
        if (!$this->can_be_approved) {
            throw new \Exception('Return cannot be approved in current status');
        }

        $this->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_notes' => $notes,
        ]);

        return $this;
    }

    /**
     * Reject return
     */
    public function reject($reason)
    {
        if (!$this->can_be_approved) {
            throw new \Exception('Return cannot be rejected in current status');
        }

        $this->update([
            'status' => 'rejected',
            'rejected_by' => Auth::id(),
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $this;
    }

    /**
     * Process return - adjust stock
     */
    public function process($notes = null)
    {
        if (!$this->can_be_processed) {
            throw new \Exception('Return must be approved before processing');
        }

        DB::beginTransaction();
        try {
            $this->update([
                'status' => 'processing',
                'processed_by' => Auth::id(),
                'processed_at' => now(),
                'processing_notes' => $notes,
            ]);

            // Adjust stock based on return type
            if ($this->is_customer_return) {
                $this->processCustomerReturn();
            } else {
                $this->processSupplierReturn();
            }

            $this->update(['status' => 'completed']);

            DB::commit();
            return $this;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process customer return - add stock back
     */
    protected function processCustomerReturn()
    {
        // Create STOCK IN untuk return dari customer
        $stockIn = StockIn::create([
            'company_id' => $this->company_id,
            'product_id' => $this->product_id,
            'batch_id' => $this->batch_id,
            'quantity' => $this->quantity,
            'purchase_price' => $this->return_value / $this->quantity, // Average value
            'received_date' => $this->return_date,
            'notes' => "Customer Return: {$this->return_number} - {$this->return_notes}",
            'received_by' => Auth::id(),
            'receiver_name' => $this->signed_by,
            'received_datetime' => now(),
        ]);

        // Update batch quantity_available
        if ($this->batch) {
            $this->batch->increment('quantity_available', $this->quantity);
        }

        // Create stock movement
        StockMovement::create([
            'product_id' => $this->product_id,
            'batch_id' => $this->batch_id,
            'movement_type' => 'RETURN_IN',
            'quantity' => $this->quantity,
            'unit_cost' => $this->return_value / $this->quantity,
            'reference_id' => $this->return_id,
            'reference_type' => 'stock_return',
            'notes' => "Customer return: {$this->return_number}",
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Process supplier return - reduce stock
     */
    protected function processSupplierReturn()
    {
        // Create STOCK OUT untuk return ke supplier
        $stockOut = StockOut::create([
            'company_id' => $this->company_id,
            'product_id' => $this->product_id,
            'batch_id' => $this->batch_id,
            'quantity' => $this->quantity,
            'selling_price' => 0, // Return tidak ada selling price
            'out_date' => $this->return_date,
            'transaction_type' => 'return_supplier',
            'notes' => "Supplier Return: {$this->return_number} - {$this->return_notes}",
            'processed_by' => Auth::id(),
        ]);

        // Update batch quantity_available
        if ($this->batch) {
            $this->batch->decrement('quantity_available', $this->quantity);
        }

        // Create stock movement
        StockMovement::create([
            'product_id' => $this->product_id,
            'batch_id' => $this->batch_id,
            'movement_type' => 'RETURN_OUT',
            'quantity' => $this->quantity,
            'unit_cost' => $this->return_value / $this->quantity,
            'reference_id' => $this->return_id,
            'reference_type' => 'stock_return',
            'notes' => "Supplier return: {$this->return_number}",
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Cancel return
     */
    public function cancel($reason)
    {
        if ($this->status === 'completed') {
            throw new \Exception('Cannot cancel completed return');
        }

        $this->update([
            'status' => 'cancelled',
            'rejection_reason' => $reason,
            'rejected_by' => Auth::id(),
            'rejected_at' => now(),
        ]);

        return $this;
    }

    /* ================= FILE METHODS ================= */

    public function uploadProofFile($file)
    {
        $this->deleteProofFile();

        $filename = 'RET_' . $this->return_id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('return_proofs', $filename, 'public');

        $this->update(['proof_file_path' => $path]);

        return $path;
    }

    public function deleteProofFile()
    {
        if (!empty($this->proof_file_path) && Storage::disk('public')->exists($this->proof_file_path)) {
            Storage::disk('public')->delete($this->proof_file_path);
        }
    }

    public function getProofFileUrl()
    {
        if (!$this->proof_file_path) {
            return null;
        }

        if (Storage::disk('public')->exists($this->proof_file_path)) {
            return url(Storage::url($this->proof_file_path));
        }

        return null;
    }

    /* ================= HELPER METHODS ================= */

    public function getSummary()
    {
        return [
            'return_id' => $this->return_id,
            'return_number' => $this->return_number,
            'return_type' => $this->return_type,
            'return_date' => $this->return_date->format('d/m/Y'),
            'product_name' => $this->product->product_name,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'reason' => $this->return_reason,
            'status' => $this->status,
            'customer' => $this->customer->customer_name ?? null,
            'supplier' => $this->supplier->company_name ?? null,
            'return_value' => $this->return_value_formatted,
            'refund_amount' => $this->refund_amount_formatted,
            'has_proof' => !empty($this->proof_file_path),
        ];
    }

    /* ================= BOOT ================= */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($return) {
            if (empty($return->created_by)) {
                $return->created_by = Auth::id();
            }
        });

        static::deleting(function ($return) {
            $return->deleteProofFile();
        });
    }
}
