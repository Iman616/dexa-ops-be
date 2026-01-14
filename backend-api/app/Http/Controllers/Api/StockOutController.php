<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockOut;
use App\Models\StockBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StockOutController extends Controller
{
    public function index(Request $request)
    {
        $query = StockOut::with(['product', 'batch']);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('product', function($pq) use ($search) {
                    $pq->where('product_name', 'like', "%{$search}%")
                      ->orWhere('product_code', 'like', "%{$search}%");
                });
            });
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        // Filter by transaction type
        if ($request->has('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        // Filter by period (month & year)
        if ($request->has('period_month') && $request->has('period_year')) {
            $query->whereYear('out_date', $request->period_year)
                  ->whereMonth('out_date', $request->period_month);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('out_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('out_date', '<=', $request->end_date);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'out_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->integer('per_page', 15);
        $stockOuts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Stock out transactions retrieved successfully',
            'data' => $stockOuts
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,product_id',
            'batch_number' => 'required|string|max:100',
            'transaction_type' => 'required|in:sale,usage,adjustment,waste',
            'quantity' => 'required|integer|min:1',
            'selling_price' => 'required|numeric|min:0',
            'out_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Get or create batch
            $batch = StockBatch::firstOrCreate(
                [
                    'batch_number' => $request->batch_number,
                    'product_id' => $request->product_id,
                ]
            );

            // Check stock availability
            $availableStock = $this->getAvailableStock($request->product_id, $batch->batch_id);
            
            if ($availableStock < $request->quantity) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Stok tidak mencukupi',
                    'available' => $availableStock,
                    'requested' => $request->quantity
                ], 400);
            }

            $stockOut = StockOut::create([
                'product_id' => $request->product_id,
                'batch_id' => $batch->batch_id,
                'transaction_type' => $request->transaction_type,
                'quantity' => $request->quantity,
                'selling_price' => $request->selling_price,
                'out_date' => $request->out_date ?? now(),
                'notes' => $request->notes,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock out transaction created successfully',
                'data' => $stockOut->load(['product', 'batch'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock out transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $stockOut = StockOut::find($id);

        if (!$stockOut) {
            return response()->json([
                'success' => false,
                'message' => 'Stock out transaction not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,product_id',
            'batch_number' => 'required|string|max:100',
            'transaction_type' => 'required|in:sale,usage,adjustment,waste',
            'quantity' => 'required|integer|min:1',
            'selling_price' => 'required|numeric|min:0',
            'out_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Get or create batch
            $batch = StockBatch::firstOrCreate(
                [
                    'batch_number' => $request->batch_number,
                    'product_id' => $request->product_id,
                ]
            );

            // Check stock availability (exclude current stock out)
            $availableStock = $this->getAvailableStock($request->product_id, $batch->batch_id);
            $availableStock += $stockOut->quantity; // Add back current quantity
            
            if ($availableStock < $request->quantity) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Stok tidak mencukupi',
                    'available' => $availableStock,
                    'requested' => $request->quantity
                ], 400);
            }

            $stockOut->update([
                'product_id' => $request->product_id,
                'batch_id' => $batch->batch_id,
                'transaction_type' => $request->transaction_type,
                'quantity' => $request->quantity,
                'selling_price' => $request->selling_price,
                'out_date' => $request->out_date ?? $stockOut->out_date,
                'notes' => $request->notes,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock out transaction updated successfully',
                'data' => $stockOut->load(['product', 'batch'])
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock out transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getAvailableStock($productId, $batchId)
    {
        $stockIn = DB::table('stock_in')
            ->where('product_id', $productId)
            ->where('batch_id', $batchId)
            ->sum('quantity');

        $stockOut = DB::table('stock_out')
            ->where('product_id', $productId)
            ->where('batch_id', $batchId)
            ->sum('quantity');

        return $stockIn - $stockOut;
    }

    public function show($id)
    {
        $stockOut = StockOut::with(['product', 'batch'])->find($id);

        if (!$stockOut) {
            return response()->json([
                'success' => false,
                'message' => 'Stock out transaction not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $stockOut
        ], 200);
    }

    public function destroy($id)
    {
        $stockOut = StockOut::find($id);

        if (!$stockOut) {
            return response()->json([
                'success' => false,
                'message' => 'Stock out transaction not found'
            ], 404);
        }

        try {
            $stockOut->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock out transaction deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock out transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
