<?php

namespace App\Http\Controllers\Api;
use Illuminate\Support\Facades\Validator;


use App\Http\Controllers\Controller;
use App\Models\EndingStock;
use Illuminate\Http\Request;

class EndingStockController extends Controller
{
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

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('product', function($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort_by', 'ending_quantity');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $endingStock = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Ending stock retrieved successfully',
            'data' => $endingStock
        ], 200);
    }

    public function recalculate(Request $request)
    {
$validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
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
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
