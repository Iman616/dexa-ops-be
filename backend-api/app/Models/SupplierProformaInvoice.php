<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;


class SupplierProformaInvoice extends Model
{
    protected $table = 'supplier_proforma_invoices';
    protected $primaryKey = 'supplier_proforma_id';

    protected $fillable = [
        'company_id', 'supplier_id', 'supplier_po_id', 'supplier_proforma_number',
        'supplier_proforma_date', 'valid_until', 'subtotal', 'tax_percentage',
        'tax_amount', 'discount_amount', 'total_amount', 'status', 'notes',
        'payment_terms', 'delivery_terms', 'signed_name', 'signed_position',
        'signed_city', 'signed_at', 'signature_image', 'issued_by', 'issued_at',
        'created_by'
    ];

    protected $casts = [
        'supplier_proforma_date' => 'date',
        'valid_until' => 'date',
        'signed_at' => 'datetime',
        'issued_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    // Relationships
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function supplierPo(): BelongsTo
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_po_id');
    }
    public function items(): HasMany
    {
        return $this->hasMany(SupplierProformaInvoiceItem::class);
    }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    // Scopes
    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    // Status methods
    public function markAsIssued(array $signData): bool
    {
        if ($this->status !== 'draft') return false;

        $this->update([
            'status' => 'issued',
            'signed_name' => $signData['signed_name'],
            'signed_position' => $signData['signed_position'],
            'signed_city' => $signData['signed_city'],
            'signed_at' => now(),
            'issued_by' => Auth::id(),
            'issued_at' => now(),
            'signature_image' => $signData['signature_image'] ?? null,
        ]);
        return true;
    }

    public function approve(): bool
    {
        if ($this->status !== 'issued') return false;
        $this->update(['status' => 'approved']);
        return true;
    }
}
