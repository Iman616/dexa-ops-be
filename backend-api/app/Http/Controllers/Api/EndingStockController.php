<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\Models\EndingStock;
use App\Models\StockBatch;
use Illuminate\Http\Request;

class EndingStockController extends Controller
{
    /**
     * Get ending stock list with pagination
     * GET /api/ending-stock
     */
    public function index(Request $request)
    {
        $query = EndingStock::with(['product', 'batch']);

        // Filter by period
        if ($request->has('period_year')) {
            $query->where('period_year', $request->period_year);
        }

        if ($request->has('period_month')) {
            $query->where('period_month', $request->period_month);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('category', $request->category);
            });
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('product', function($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'ending_quantity');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $endingStock = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Ending stock retrieved successfully',
            'data' => $endingStock
        ], 200);
    }

    /**
     * ✅ Get ending stock summary
     * GET /api/ending-stock/summary
     */
    public function summary(Request $request)
    {
        try {
            $query = EndingStock::query();

            // Filter by period
            if ($request->has('period_year')) {
                $query->where('period_year', $request->period_year);
            }

            if ($request->has('period_month')) {
                $query->where('period_month', $request->period_month);
            }

            // Filter by product
            if ($request->has('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            // Filter by category
            if ($request->has('category')) {
                $query->whereHas('product', function($q) use ($request) {
                    $q->where('category', $request->category);
                });
            }

            // Calculate summary
            $summary = [
                'total_batches' => $query->count(),
                'total_ending_quantity' => $query->sum('ending_quantity'),
                'total_ending_value' => $query->sum('ending_value'),
                'total_opening_quantity' => $query->sum('opening_quantity'),
                'total_opening_value' => $query->sum('opening_value'),
                'total_in_quantity' => $query->sum('in_quantity'),
                'total_in_value' => $query->sum('in_value'),
                'total_out_quantity' => $query->sum('out_quantity'),
                'total_out_value' => $query->sum('out_value'),
            ];

            // Calculate stock status counts
            $lowStockCount = 0;
            $outOfStockCount = 0;
            $expiredCount = 0;
            $expiringSoonCount = 0;

            $batches = StockBatch::with('product')->get();
            foreach ($batches as $batch) {
                // Check stock level
                if ($batch->quantity_available <= 0) {
                    $outOfStockCount++;
                } elseif ($batch->quantity_available <= 10) {
                    $lowStockCount++;
                }

                // Check expiry
                if ($batch->expiry_date) {
                    if ($batch->expiry_date < now()) {
                        $expiredCount++;
                    } elseif ($batch->expiry_date <= now()->addDays(30)) {
                        $expiringSoonCount++;
                    }
                }
            }

            $summary['low_stock_count'] = $lowStockCount;
            $summary['out_of_stock_count'] = $outOfStockCount;
            $summary['expired_count'] = $expiredCount;
            $summary['expiring_soon_count'] = $expiringSoonCount;

            return response()->json([
                'success' => true,
                'message' => 'Summary retrieved successfully',
                'data' => $summary
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Recalculate ending stock for specific period
     * POST /api/ending-stock/recalculate
     */
    public function recalculate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            EndingStock::recalculateForPeriod($request->year, $request->month);

            return response()->json([
                'success' => true,
                'message' => 'Ending stock recalculated successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to recalculate ending stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
