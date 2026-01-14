<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\StockOpening;
use App\Models\StockBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockOpeningController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,product_id',
            'batch_number' => 'required|string|max:100',
            'quantity' => 'required|integer|min:0',
            'value' => 'required|string',
            'opening_date' => 'required|date',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date', // ✅ HAPUS after_or_equal
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

            // Create or get batch
            $batch = StockBatch::firstOrCreate(
                [
                    'batch_number' => $request->batch_number,
                    'product_id' => $request->product_id,
                ],
                [
                    'manufacture_date' => $request->manufacture_date ?: null,
                    'expiry_date' => $request->expiry_date ?: null,
                ]
            );

            // Create stock opening
            $stockOpening = StockOpening::create([
                'product_id' => $request->product_id,
                'batch_id' => $batch->batch_id,
                'quantity' => $request->quantity,
                'value' => $request->value,
                'opening_date' => $request->opening_date,
            ]);

            DB::commit();

            $stockOpening->load(['product', 'batch']);

            return response()->json([
                'success' => true,
                'message' => 'Stock opening created successfully',
                'data' => $stockOpening
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock opening: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $stockOpening = StockOpening::find($id);

        if (!$stockOpening) {
            return response()->json([
                'success' => false,
                'message' => 'Stock opening not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,product_id',
            'batch_number' => 'required|string|max:100',
            'quantity' => 'required|integer|min:0',
            'value' => 'required|string',
            'opening_date' => 'required|date',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date', // ✅ HAPUS after_or_equal
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

            // Update or create batch
            $batch = StockBatch::updateOrCreate(
                [
                    'batch_number' => $request->batch_number,
                    'product_id' => $request->product_id,
                ],
                [
                    'manufacture_date' => $request->manufacture_date ?: null,
                    'expiry_date' => $request->expiry_date ?: null,
                ]
            );

            // Update stock opening
            $stockOpening->update([
                'product_id' => $request->product_id,
                'batch_id' => $batch->batch_id,
                'quantity' => $request->quantity,
                'value' => $request->value,
                'opening_date' => $request->opening_date,
            ]);

            DB::commit();

            $stockOpening->load(['product', 'batch']);

            return response()->json([
                'success' => true,
                'message' => 'Stock opening updated successfully',
                'data' => $stockOpening
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock opening: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $query = StockOpening::with(['product', 'batch']);

            // Search
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->whereHas('product', function ($q) use ($search) {
                    $q->where('product_name', 'like', "%{$search}%")
                      ->orWhere('product_code', 'like', "%{$search}%");
                });
            }

            // Filter by product
            if ($request->has('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            // Filter by period
            if ($request->has('period_month') && $request->has('period_year')) {
                $query->whereYear('opening_date', $request->period_year)
                      ->whereMonth('opening_date', $request->period_month);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'opening_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $stockOpenings = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Stock openings retrieved successfully',
                'data' => $stockOpenings
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock openings: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $stockOpening = StockOpening::with(['product', 'batch'])->find($id);

            if (!$stockOpening) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock opening not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock opening retrieved successfully',
                'data' => $stockOpening
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock opening: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $stockOpening = StockOpening::find($id);

            if (!$stockOpening) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock opening not found'
                ], 404);
            }

            $stockOpening->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock opening deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock opening: ' . $e->getMessage()
            ], 500);
        }
    }
}
