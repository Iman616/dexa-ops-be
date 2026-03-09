<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockOpening;
use App\Models\StockBatch;
use App\Models\EndingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockOpeningController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id'       => 'required|exists:products,product_id',
            'batch_number'     => 'required|string|max:100',
            'quantity'         => 'required|integer|min:0',
            'value'            => 'required|numeric',
            'opening_date'     => 'required|date',
            'manufacture_date' => 'nullable|date',
            'expiry_date'      => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // ─── FIX: firstOrCreate harus include semua field NOT NULL ──────────
            // stock_batches.quantity_initial  → NOT NULL, tidak ada default
            // stock_batches.purchase_price    → NOT NULL, tidak ada default
            // stock_batches.quantity_available → pakai nilai dari quantity
            // Tanpa ini → SQLSTATE[HY000]: General error: 1364 Field doesn't have a default value
            $purchasePrice = $request->quantity > 0
                ? (float)$request->value / (int)$request->quantity
                : 0;

            $batch = StockBatch::firstOrCreate(
                [
                    // ← Key untuk lookup: cari batch yang sudah ada
                    'batch_number' => $request->batch_number,
                    'product_id'   => $request->product_id,
                ],
                [
                    // ← Nilai yang diisi HANYA jika baru dibuat
                    'quantity_initial'    => $request->quantity,
                    'quantity_available'  => $request->quantity,
                    'purchase_price'      => $purchasePrice,
                    'manufacture_date'    => $request->manufacture_date ?: null,
                    'expiry_date'         => $request->expiry_date ?: null,
                    'status'              => 'active',
                ]
            );

            // Jika batch sudah ada tapi expiry/manufacture berubah, update
            if (!$batch->wasRecentlyCreated) {
                $batch->update([
                    'manufacture_date' => $request->manufacture_date ?: $batch->manufacture_date,
                    'expiry_date'      => $request->expiry_date ?: $batch->expiry_date,
                ]);
            }

            // Buat stock opening
            $stockOpening = StockOpening::create([
                'product_id'   => $request->product_id,
                'batch_id'     => $batch->batch_id,
                'quantity'     => $request->quantity,
                'value'        => $request->value,
                'opening_date' => $request->opening_date,
            ]);

            // Update ending stock
            $date = \Carbon\Carbon::parse($request->opening_date);
            EndingStock::updateEndingStock($batch->batch_id, $date->year, $date->month);

            DB::commit();

            $stockOpening->load(['product', 'batch']);

            return response()->json([
                'success' => true,
                'message' => 'Stock opening created successfully',
                'data'    => $stockOpening
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
            'product_id'       => 'required|exists:products,product_id',
            'batch_number'     => 'required|string|max:100',
            'quantity'         => 'required|integer|min:0',
            'value'            => 'required|numeric',
            'opening_date'     => 'required|date',
            'manufacture_date' => 'nullable|date',
            'expiry_date'      => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $oldBatchId = $stockOpening->batch_id;
            $oldDate    = \Carbon\Carbon::parse($stockOpening->opening_date);

            $purchasePrice = $request->quantity > 0
                ? (float)$request->value / (int)$request->quantity
                : 0;

            // ─── FIX: updateOrCreate juga harus include required fields ─────────
            $batch = StockBatch::updateOrCreate(
                [
                    'batch_number' => $request->batch_number,
                    'product_id'   => $request->product_id,
                ],
                [
                    'quantity_initial'    => $request->quantity,
                    'quantity_available'  => $request->quantity,
                    'purchase_price'      => $purchasePrice,
                    'manufacture_date'    => $request->manufacture_date ?: null,
                    'expiry_date'         => $request->expiry_date ?: null,
                ]
            );

            $stockOpening->update([
                'product_id'   => $request->product_id,
                'batch_id'     => $batch->batch_id,
                'quantity'     => $request->quantity,
                'value'        => $request->value,
                'opening_date' => $request->opening_date,
            ]);

            EndingStock::updateEndingStock($oldBatchId, $oldDate->year, $oldDate->month);

            $newDate = \Carbon\Carbon::parse($request->opening_date);
            EndingStock::updateEndingStock($batch->batch_id, $newDate->year, $newDate->month);

            DB::commit();

            $stockOpening->load(['product', 'batch']);

            return response()->json([
                'success' => true,
                'message' => 'Stock opening updated successfully',
                'data'    => $stockOpening
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock opening: ' . $e->getMessage()
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

            DB::beginTransaction();

            $batchId = $stockOpening->batch_id;
            $date    = \Carbon\Carbon::parse($stockOpening->opening_date);

            $stockOpening->delete();

            EndingStock::updateEndingStock($batchId, $date->year, $date->month);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock opening deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock opening: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $query = StockOpening::with(['product', 'batch']);

            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('product', function ($q) use ($search) {
                    $q->where('product_name', 'like', "%{$search}%")
                      ->orWhere('product_code', 'like', "%{$search}%");
                });
            }

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            // ─── FIX: Filter bulan/tahun hanya jika keduanya dikirim ────────────
            // Jika frontend kirim period_month & period_year kosong (""),
            // $request->filled() return false → tidak filter → tampil semua data
            if ($request->filled('period_month') && $request->filled('period_year')) {
                $query->whereYear('opening_date', $request->period_year)
                      ->whereMonth('opening_date', $request->period_month);
            } elseif ($request->filled('period_year')) {
                $query->whereYear('opening_date', $request->period_year);
            }

            $sortBy    = $request->get('sort_by', 'opening_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage      = $request->get('per_page', 15);
            $stockOpenings = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Stock openings retrieved successfully',
                'data'    => $stockOpenings
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
                'data'    => $stockOpening
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock opening: ' . $e->getMessage()
            ], 500);
        }
    }
}
