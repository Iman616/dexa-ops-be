<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class EndingStock extends Model
{
    use HasFactory;

    protected $table = 'ending_stock';
    protected $primaryKey = 'ending_stock_id';

    protected $fillable = [
        'product_id',
        'batch_id',
        'opening_quantity',
        'opening_value',
        'in_quantity',
        'in_value',
        'out_quantity',
        'out_value',
        'ending_quantity',
        'ending_value',
        'period_year',
        'period_month',
    ];

    protected $casts = [
        'opening_quantity' => 'integer',
        'opening_value' => 'integer',
        'in_quantity' => 'integer',
        'in_value' => 'integer',
        'out_quantity' => 'integer',
        'out_value' => 'integer',
        'ending_quantity' => 'integer',
        'ending_value' => 'integer',
        'period_year' => 'integer',
        'period_month' => 'integer',
    ];

    // Relationships
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'batch_id', 'batch_id');
    }

    // Static method to update ending stock
    public static function updateEndingStock($batchId, $year = null, $month = null)
    {
        $year = $year ?? now()->year;
        $month = $month ?? now()->month;

        $batch = StockBatch::findOrFail($batchId);

        // Get opening stock
        $opening = StockOpening::where('batch_id', $batchId)
            ->whereYear('opening_date', $year)
            ->whereMonth('opening_date', $month)
            ->first();

        // Calculate stock in for the period
        $stockIn = StockIn::where('batch_id', $batchId)
            ->whereYear('received_date', $year)
            ->whereMonth('received_date', $month)
            ->selectRaw('SUM(quantity) as total_quantity, SUM(quantity * purchase_price) as total_value')
            ->first();

        // Calculate stock out for the period
        $stockOut = StockOut::where('batch_id', $batchId)
            ->whereYear('out_date', $year)
            ->whereMonth('out_date', $month)
            ->selectRaw('SUM(quantity) as total_quantity, SUM(quantity * selling_price) as total_value')
            ->first();

        $openingQty = $opening->quantity ?? 0;
        $openingValue = $opening->value ?? 0;
        $inQty = $stockIn->total_quantity ?? 0;
        $inValue = $stockIn->total_value ?? 0;
        $outQty = $stockOut->total_quantity ?? 0;
        $outValue = $stockOut->total_value ?? 0;

        // Calculate ending
        $endingQty = $openingQty + $inQty - $outQty;
        $endingValue = $openingValue + $inValue - $outValue;

        // Update or create ending stock record
        return self::updateOrCreate(
            [
                'batch_id' => $batchId,
                'period_year' => $year,
                'period_month' => $month,
            ],
            [
                'product_id' => $batch->product_id,
                'opening_quantity' => $openingQty,
                'opening_value' => $openingValue,
                'in_quantity' => $inQty,
                'in_value' => $inValue,
                'out_quantity' => $outQty,
                'out_value' => $outValue,
                'ending_quantity' => $endingQty,
                'ending_value' => $endingValue,
            ]
        );
    }

    // Recalculate all ending stock for a specific period
    public static function recalculateForPeriod($year, $month)
    {
        $batches = StockBatch::all();
        
        foreach ($batches as $batch) {
            self::updateEndingStock($batch->batch_id, $year, $month);
        }
    }
}
