<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;


/**
 * ProformaInvoice Model
 * 
 * @property int $proforma_id
 * @property int $company_id
 * @property int $customer_id
 * @property string $proforma_number
 * @property string $proforma_date
 * @property float $total_amount
 * @property float $tax_amount
 * @property float $discount_amount
 * @property string $status
 * @property string|null $notes
 */
class ProformaInvoice extends Model
{
    protected $table = 'proforma_invoices';
    protected $primaryKey = 'proforma_id';

    protected $fillable = [
        'company_id',
        'customer_id',
        'po_id',
        'proforma_number',
        'proforma_date',
        'valid_until',
        'subtotal',
        'tax_percentage',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'status',
        'notes',
        'signed_name',
        'signed_position',
        'signed_city',
        'signed_at',
        'issued_by',
        'issued_at',
        'created_by',
        'approved_by',
        'approved_at',
        'converted_to_invoice_id',
        'converted_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'signed_at' => 'datetime',
        'issued_at' => 'datetime',
        'approved_at' => 'datetime',
        'converted_at' => 'datetime',
    ];


    // ==================== RELATIONSHIPS ====================

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProformaInvoiceItem::class, 'proforma_id', 'proforma_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    // ==================== ACCESSORS ====================

  
    // ==================== SCOPES ====================

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('proforma_number', 'like', "%{$search}%")
              ->orWhereHas('customer', function ($q) use ($search) {
                  $q->where('customer_name', 'like', "%{$search}%");
              });
        });
    }

    // ==================== METHODS ====================

    /**
     * Update status to sent
     */
    public function markAsSent(): bool
    {
        if ($this->status !== 'draft') {
            return false;
        }

        $this->status = 'sent';
        return $this->save();
    }

    /**
     * Approve proforma invoice
     */
    public function approve($userId = null): bool
    {
        if ($this->status !== 'sent') {
            return false;
        }

        $this->status = 'approved';
        $this->approved_by = $userId ?? Auth::id();
        $this->approved_at = now();
        
        return $this->save();
    }

    /**
     * Convert to invoice using stored procedure
     */
    public function convertToInvoice($userId = null): array
    {
        if ($this->status !== 'approved') {
            return [
                'success' => false,
                'message' => 'Only approved proforma can be converted',
            ];
        }

        if ($this->converted_to_invoice_id) {
            return [
                'success' => false,
                'message' => 'Already converted to invoice',
            ];
        }

        DB::beginTransaction();

        try {
            // Call stored procedure
            DB::statement('CALL sp_convert_proforma_to_invoice(?, ?, @invoice_id, @message)', [
                $this->proforma_id,
                $userId ?? Auth::id(),
            ]);

            $result = DB::select('SELECT @invoice_id as invoice_id, @message as message');
            
            if (!$result[0]->invoice_id) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => $result[0]->message,
                ];
            }

            DB::commit();

            return [
                'success' => true,
                'message' => $result[0]->message,
                'invoice_id' => $result[0]->invoice_id,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }
}