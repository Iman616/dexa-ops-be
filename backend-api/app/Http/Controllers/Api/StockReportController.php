<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReportController extends Controller
{
    /**
     * Laporan Stok Keseluruhan (seperti di Excel)
     */
    public function stockReport(Request $request)
    {
        $month = $request->get('month', now()->month);
        $year = $request->get('year', now()->year);

        $report = [
            'period' => [
                'month' => $month,
                'year' => $year
            ],
            'categories' => [
                'no_expiry' => $this->getStockByExpiryStatus('no_expiry'),
                'long_expiry' => $this->getStockByExpiryStatus('long_expiry'),
                'expiring_soon' => $this->getStockByExpiryStatus('expiring_soon'),
                'expired' => $this->getStockByExpiryStatus('expired'),
            ],
            'summary' => $this->getSummary()
        ];

        return response()->json([
            'success' => true,
            'data' => $report
        ], 200);
    }

    private function getStockByExpiryStatus($status)
    {
        $query = DB::table('stock_batches')
            ->join('products', 'stock_batches.product_id', '=', 'products.product_id')
            ->leftJoin('stock_in', 'stock_batches.batch_id', '=', 'stock_in.batch_id')
            ->select(
                'products.product_id',
                'products.product_code',
                'products.product_name',
                'products.category',
                'stock_batches.batch_number',
                'stock_batches.expiry_date',
                DB::raw('COALESCE(SUM(stock_in.quantity), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(stock_in.quantity * stock_in.purchase_price), 0) as total_value')
            )
            ->groupBy('products.product_id', 'products.product_code', 'products.product_name', 
                     'products.category', 'stock_batches.batch_number', 'stock_batches.expiry_date');

        switch ($status) {
            case 'no_expiry':
                $query->whereNull('stock_batches.expiry_date');
                break;
            case 'long_expiry':
                $query->where('stock_batches.expiry_date', '>', now()->addMonths(3));
                break;
            case 'expiring_soon':
                $query->whereBetween('stock_batches.expiry_date', [
                    now(),
                    now()->addMonths(3)
                ]);
                break;
            case 'expired':
                $query->where('stock_batches.expiry_date', '<', now());
                break;
        }

        $data = $query->get();

        return [
            'count' => $data->count(),
            'total_value' => $data->sum('total_value'),
            'items' => $data
        ];
    }

    private function getSummary()
    {
        return DB::table('products')
            ->leftJoin('stock_in', 'products.product_id', '=', 'stock_in.product_id')
            ->select(
                DB::raw('COUNT(DISTINCT products.product_id) as total_products'),
                DB::raw('COALESCE(SUM(stock_in.quantity), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(stock_in.quantity * stock_in.purchase_price), 0) as total_value')
            )
            ->first();
    }
}
