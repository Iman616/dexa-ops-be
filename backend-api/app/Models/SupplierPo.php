<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierPo extends Model
{
    use HasFactory;

    protected $table = 'supplier_po';
    protected $primaryKey = 'supplier_po_id';

    protected $fillable = [
        'supplier_id',
        'po_number',
        'po_date',
        'status',
        'total_amount',
    ];

    protected $casts = [
        'po_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'supplier_po_id', 'supplier_po_id');
    }
}
