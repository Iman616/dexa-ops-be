<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\StockBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StockMovementController extends Controller
{
    /**
     * Display a listing of stock movements
     */
    public function index(Request $request)
    {
        $query = StockMovement::with(['product', 'batch', 'createdByUser']);

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by batch
        if ($request->has('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        // Filter by movement type
        if ($request->has('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        // Filter by reference type
        if ($request->has('reference_type')) {
            $query->where('reference_type', $request->reference_type);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('product', function($pq) use ($search) {
                    $pq->where('product_name', 'like', "%{$search}%")
                      ->orWhere('product_code', 'like', "%{$search}%");
                })
                ->orWhereHas('batch', function($bq) use ($search) {
                    $bq->where('batch_number', 'like', "%{$search}%");
                });
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $movements = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Stock movements retrieved successfully',
            'data' => $movements
        ], 200);
    }

    /**
     * Store a newly created stock movement
     */
  public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,product_id',
            'batch_id' => 'required|exists:stock_batches,batch_id',
            // ✅ UPDATE validation untuk include RETURN_IN dan RETURN_OUT
            'movement_type' => 'required|in:IN,OUT,ADJUSTMENT,RETURN,RETURN_IN,RETURN_OUT',
            'quantity' => 'required|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'reference_id' => 'nullable|integer',
            'reference_type' => 'nullable|string|max:100',
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

            // Create stock movement
            $movement = StockMovement::create([
                'product_id' => $request->product_id,
                'batch_id' => $request->batch_id,
                'movement_type' => $request->movement_type,
                'quantity' => $request->quantity,
                'unit_cost' => $request->unit_cost,
                'reference_id' => $request->reference_id,
                'reference_type' => $request->reference_type,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
            ]);

            // Update batch quantity
            $batch = StockBatch::find($request->batch_id);
            if ($batch) {
                // ✅ UPDATE logic untuk handle RETURN_IN dan RETURN_OUT
                if (in_array($request->movement_type, ['IN', 'RETURN', 'RETURN_IN'])) {
                    $batch->quantity_available += $request->quantity;
                } elseif (in_array($request->movement_type, ['OUT', 'ADJUSTMENT', 'RETURN_OUT'])) {
                    $batch->quantity_available -= $request->quantity;
                }

                // Update batch status
                if ($batch->quantity_available <= 0) {
                    $batch->status = 'depleted';
                } elseif ($batch->expiry_date && $batch->expiry_date < now()) {
                    $batch->status = 'expired';
                } else {
                    $batch->status = 'active';
                }

                $batch->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock movement created successfully',
                'data' => $movement->load(['product', 'batch', 'createdByUser'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock movement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified stock movement
     */
    public function show($id)
    {
        $movement = StockMovement::with(['product', 'batch', 'createdByUser'])->find($id);

        if (!$movement) {
            return response()->json([
                'success' => false,
                'message' => 'Stock movement not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock movement retrieved successfully',
            'data' => $movement
        ], 200);
    }

    /**
     * Update the specified stock movement
     */
    public function update(Request $request, $id)
    {
        $movement = StockMovement::find($id);

        if (!$movement) {
            return response()->json([
                'success' => false,
                'message' => 'Stock movement not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
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
            // Only allow updating notes, not quantity or type
            $movement->update(['notes' => $request->notes]);

            return response()->json([
                'success' => true,
                'message' => 'Stock movement updated successfully',
                'data' => $movement
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock movement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified stock movement
     */
   public function destroy($id)
    {
        $movement = StockMovement::find($id);

        if (!$movement) {
            return response()->json([
                'success' => false,
                'message' => 'Stock movement not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Reverse the quantity in batch before delete
            $batch = $movement->batch;
            if ($batch) {
                // ✅ UPDATE logic untuk reverse RETURN_IN dan RETURN_OUT
                if (in_array($movement->movement_type, ['IN', 'RETURN', 'RETURN_IN'])) {
                    $batch->quantity_available -= $movement->quantity;
                } elseif (in_array($movement->movement_type, ['OUT', 'ADJUSTMENT', 'RETURN_OUT'])) {
                    $batch->quantity_available += $movement->quantity;
                }
                $batch->save();
            }

            $movement->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock movement deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock movement',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get stock movement summary by product
     */
    public function summary(Request $request)
    {
        $productId = $request->get('product_id');

        $query = DB::table('stock_movements')
            ->join('products', 'stock_movements.product_id', '=', 'products.product_id')
            ->select(
                'products.product_id',
                'products.product_code',
                'products.product_name',
                'stock_movements.movement_type',
                DB::raw('SUM(stock_movements.quantity) as total_quantity'),
                DB::raw('COUNT(*) as total_movements')
            )
            ->groupBy('products.product_id', 'products.product_code', 'products.product_name', 'stock_movements.movement_type');

        if ($productId) {
            $query->where('products.product_id', $productId);
        }

        $summary = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Stock movement summary retrieved successfully',
            'data' => $summary
        ], 200);
    }

    /**
     * Get movement history by batch
     */
    public function historyByBatch($batchId)
    {
        $batch = StockBatch::with('product')->find($batchId);

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        $movements = StockMovement::with(['createdByUser'])
            ->where('batch_id', $batchId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Batch movement history retrieved successfully',
            'data' => [
                'batch' => $batch,
                'movements' => $movements
            ]
        ], 200);
    }
}