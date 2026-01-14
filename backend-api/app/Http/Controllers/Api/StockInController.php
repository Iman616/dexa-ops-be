<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockIn;
use App\Models\StockBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StockInController extends Controller
{
    /**
     * Display a listing of stock in transactions
     */
    public function index(Request $request)
    {
        $query = StockIn::with(['product', 'batch', 'supplierPo.supplier']);

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

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by supplier PO
        if ($request->has('supplier_po_id')) {
            $query->where('supplier_po_id', $request->supplier_po_id);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('received_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('received_date', '<=', $request->end_date);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'received_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $stockIns = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Stock in transactions retrieved successfully',
            'data' => $stockIns
        ], 200);
    }

    /**
     * Store a newly created stock in transaction
     */
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'supplier_po_id' => 'nullable|exists:supplier_po,supplier_po_id',
        'product_id' => 'required|exists:products,product_id',
        'batch_number' => 'required|string|max:100',
        'quantity' => 'required|integer|min:1',
        'purchase_price' => 'required|numeric|min:0',
        'received_date' => 'nullable|date',
        'notes' => 'nullable|string',
        'manufacture_date' => 'nullable|date',
        'expiry_date' => 'nullable|date|after_or_equal:manufacture_date',
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

        // Check if batch exists, if not create new one
        $batch = StockBatch::where('batch_number', $request->batch_number)
            ->where('product_id', $request->product_id)
            ->first();

        if (!$batch) {
            $batch = StockBatch::create([
                'batch_number' => $request->batch_number,
                'product_id' => $request->product_id,
                'manufacture_date' => $request->manufacture_date,
                'expiry_date' => $request->expiry_date,
            ]);
        }

        // Create stock in transaction
        $stockIn = StockIn::create([
            'supplier_po_id' => $request->supplier_po_id,
            'product_id' => $request->product_id,
            'batch_id' => $batch->batch_id,
            'quantity' => $request->quantity,
            'purchase_price' => $request->purchase_price,
            'received_date' => $request->received_date ?? now(),
            'notes' => $request->notes,
            'received_by' => Auth::id(), // ← Hapus fallback yang bermasalah
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Stock in transaction created successfully',
            'data' => $stockIn->load(['product', 'batch', 'supplierPo'])
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Failed to create stock in transaction',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Display the specified stock in transaction
     */
    public function show($id)
    {
        $stockIn = StockIn::with(['product', 'batch', 'supplierPo.supplier'])->find($id);

        if (!$stockIn) {
            return response()->json([
                'success' => false,
                'message' => 'Stock in transaction not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock in transaction retrieved successfully',
            'data' => $stockIn
        ], 200);
    }

    /**
     * Update the specified stock in transaction
     */
    public function update(Request $request, $id)
    {
        $stockIn = StockIn::find($id);

        if (!$stockIn) {
            return response()->json([
                'success' => false,
                'message' => 'Stock in transaction not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'supplier_po_id' => 'nullable|exists:supplier_po,supplier_po_id',
            'quantity' => 'sometimes|required|integer|min:1',
            'purchase_price' => 'sometimes|required|numeric|min:0',
            'received_date' => 'nullable|date',
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
            $stockIn->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Stock in transaction updated successfully',
                'data' => $stockIn->load(['product', 'batch', 'supplierPo'])
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock in transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified stock in transaction
     */
    public function destroy($id)
    {
        $stockIn = StockIn::find($id);

        if (!$stockIn) {
            return response()->json([
                'success' => false,
                'message' => 'Stock in transaction not found'
            ], 404);
        }

        try {
            $stockIn->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock in transaction deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock in transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stock summary by product
     */
    public function summary(Request $request)
    {
        $productId = $request->get('product_id');

        $query = DB::table('stock_in')
            ->join('products', 'stock_in.product_id', '=', 'products.product_id')
            ->select(
                'products.product_id',
                'products.product_code',
                'products.product_name',
                DB::raw('SUM(stock_in.quantity) as total_quantity'),
                DB::raw('COUNT(DISTINCT stock_in.batch_id) as total_batches'),
                DB::raw('AVG(stock_in.purchase_price) as avg_purchase_price'),
                DB::raw('MAX(stock_in.received_date) as last_received_date')
            )
            ->groupBy('products.product_id', 'products.product_code', 'products.product_name');

        if ($productId) {
            $query->where('products.product_id', $productId);
        }

        $summary = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Stock summary retrieved successfully',
            'data' => $summary
        ], 200);
    }
}
