<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockOpening;
use App\Models\StockBatch;
use App\Models\EndingStock;
use App\Exports\StockOpeningExport;
use App\Exports\StockOpeningWithTemplateExport;
use App\Imports\StockOpeningImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class StockOpeningController extends Controller
{
    /* =========================
     * INDEX
     * ========================= */
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

            if ($request->filled('period_month') && $request->filled('period_year')) {
                $query->whereYear('opening_date', $request->period_year)
                      ->whereMonth('opening_date', $request->period_month);
            } elseif ($request->filled('period_year')) {
                $query->whereYear('opening_date', $request->period_year);
            }

            $query->orderBy($request->get('sort_by', 'opening_date'), $request->get('sort_order', 'desc'));

            return response()->json([
                'success' => true,
                'message' => 'Stock openings retrieved successfully',
                'data'    => $query->paginate($request->get('per_page', 15)),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock openings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * SHOW
     * ========================= */
    public function show($id)
    {
        try {
            $stockOpening = StockOpening::with(['product', 'batch'])->find($id);

            if (!$stockOpening) {
                return response()->json(['success' => false, 'message' => 'Stock opening not found'], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock opening retrieved successfully',
                'data'    => $stockOpening,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock opening: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * STORE
     * ========================= */
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
                'success' => false, 'message' => 'Validation error', 'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $purchasePrice = $request->quantity > 0
                ? (float) $request->value / (int) $request->quantity
                : 0;

            $batch = StockBatch::firstOrCreate(
                ['batch_number' => $request->batch_number, 'product_id' => $request->product_id],
                [
                    'quantity_initial'   => $request->quantity,
                    'quantity_available' => $request->quantity,
                    'purchase_price'     => $purchasePrice,
                    'manufacture_date'   => $request->manufacture_date ?: null,
                    'expiry_date'        => $request->expiry_date ?: null,
                    'status'             => 'active',
                ]
            );

            if (!$batch->wasRecentlyCreated) {
                $batch->update([
                    'manufacture_date' => $request->manufacture_date ?: $batch->manufacture_date,
                    'expiry_date'      => $request->expiry_date      ?: $batch->expiry_date,
                ]);
            }

            $stockOpening = StockOpening::create([
                'product_id'   => $request->product_id,
                'batch_id'     => $batch->batch_id,
                'quantity'     => $request->quantity,
                'value'        => $request->value,
                'opening_date' => $request->opening_date,
            ]);

            $date = \Carbon\Carbon::parse($request->opening_date);
            EndingStock::updateEndingStock($batch->batch_id, $date->year, $date->month);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock opening created successfully',
                'data'    => $stockOpening->load(['product', 'batch']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 'message' => 'Failed to create stock opening: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * UPDATE
     * ========================= */
    public function update(Request $request, $id)
    {
        $stockOpening = StockOpening::find($id);
        if (!$stockOpening) {
            return response()->json(['success' => false, 'message' => 'Stock opening not found'], 404);
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
                'success' => false, 'message' => 'Validation error', 'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $oldBatchId = $stockOpening->batch_id;
            $oldDate    = \Carbon\Carbon::parse($stockOpening->opening_date);

            $purchasePrice = $request->quantity > 0
                ? (float) $request->value / (int) $request->quantity
                : 0;

            $batch = StockBatch::updateOrCreate(
                ['batch_number' => $request->batch_number, 'product_id' => $request->product_id],
                [
                    'quantity_initial'   => $request->quantity,
                    'quantity_available' => $request->quantity,
                    'purchase_price'     => $purchasePrice,
                    'manufacture_date'   => $request->manufacture_date ?: null,
                    'expiry_date'        => $request->expiry_date ?: null,
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

            return response()->json([
                'success' => true,
                'message' => 'Stock opening updated successfully',
                'data'    => $stockOpening->fresh(['product', 'batch']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 'message' => 'Failed to update stock opening: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * DESTROY
     * ========================= */
    public function destroy($id)
    {
        try {
            $stockOpening = StockOpening::find($id);
            if (!$stockOpening) {
                return response()->json(['success' => false, 'message' => 'Stock opening not found'], 404);
            }

            DB::beginTransaction();

            $batchId = $stockOpening->batch_id;
            $date    = \Carbon\Carbon::parse($stockOpening->opening_date);
            $stockOpening->delete();
            EndingStock::updateEndingStock($batchId, $date->year, $date->month);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Stock opening deleted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 'message' => 'Failed to delete stock opening: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * EXPORT — GET /stock-opening/export
     * ========================= */
    public function export(Request $request)
    {
        $filters = $request->only(['product_id', 'period_year', 'period_month', 'search']);
        $filename = 'stock_opening_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new StockOpeningExport($filters), $filename);
    }

    /* =========================
     * DOWNLOAD TEMPLATE — GET /stock-opening/template
     * ========================= */
    public function downloadTemplate()
    {
        $filename = 'template_import_stock_opening.xlsx';

        return Excel::download(new StockOpeningWithTemplateExport(), $filename);
    }

    /* =========================
     * IMPORT — POST /stock-opening/import
     * ========================= */
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false, 'message' => 'File tidak valid', 'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $import = new StockOpeningImport();
            Excel::import($import, $request->file('file'));

            $summary = $import->getSummary();

            $hasErrors = !empty($summary['errors']) || !empty($summary['failures']);

            return response()->json([
                'success' => true,
                'message' => "Import selesai: {$summary['imported']} berhasil, {$summary['skipped']} dilewati.",
                'data'    => $summary,
            ], $hasErrors ? 207 : 200); // 207 Multi-Status jika ada sebagian error

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = collect($e->failures())->map(fn($f) => [
                'row'     => $f->row(),
                'field'   => $f->attribute(),
                'message' => implode(', ', $f->errors()),
            ])->toArray();

            return response()->json([
                'success'  => false,
                'message'  => 'Validasi file gagal',
                'failures' => $failures,
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal import: ' . $e->getMessage(),
            ], 500);
        }
    }
}
