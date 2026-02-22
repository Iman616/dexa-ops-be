<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockIn;
use App\Models\EndingStock;
use App\Models\StockBatch;
use App\Models\SupplierPurchaseOrder;
use App\Models\ProductSupplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class StockInController extends Controller
{
    public function index(Request $request)
    {
        $query = StockIn::with(['product', 'batch', 'supplierPo.supplier', 'company', 'receivedByUser']);

        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('product', function ($pq) use ($search) {
                    $pq->where('product_name', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%");
                })
                ->orWhereHas('batch', function ($bq) use ($search) {
                    $bq->where('batch_number', 'like', "%{$search}%");
                })
                ->orWhere('delivery_note_number', 'like', "%{$search}%");
            });
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('supplier_po_id')) {
            $query->where('supplier_po_id', $request->supplier_po_id);
        }

        if ($request->has('start_date')) {
            $query->where('received_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('received_date', '<=', $request->end_date);
        }

        if ($request->has('has_delivery_note')) {
            if ($request->boolean('has_delivery_note')) {
                $query->whereNotNull('delivery_note_file');
            } else {
                $query->whereNull('delivery_note_file');
            }
        }

        $sortBy    = $request->get('sort_by', 'received_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage  = $request->get('per_page', 15);
        $stockIns = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Stock in transactions retrieved successfully',
            'data'    => $stockIns,
        ], 200);
    }

    /**
     * Store a newly created stock in transaction (manual entry)
     * POST /api/stock-in
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id'           => 'required|exists:companies,company_id',
            'supplier_id'          => 'nullable|exists:suppliers,supplier_id', // ✅ bisa input langsung
            'supplier_po_id'       => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'delivery_note_number' => 'nullable|string|max:100',
            'delivery_note_date'   => 'nullable|date',
            'delivery_note_file'   => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'product_id'           => 'required|exists:products,product_id',
            'batch_number'         => 'required|string|max:100',
            'quantity'             => 'required|integer|min:1',
            'purchase_price'       => 'required|numeric|min:0',
            'received_date'        => 'nullable|date',
            'notes'                => 'nullable|string',
            'manufacture_date'     => 'nullable|date',
            'expiry_date'          => 'nullable|date|after_or_equal:manufacture_date',
        ], [
            'delivery_note_file.mimes' => 'File must be PDF, JPG, JPEG, or PNG',
            'delivery_note_file.max'   => 'File size must not exceed 5MB',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Resolve supplier_id: prioritas dari request → supplier_po → product default
            $supplierId = $request->supplier_id ?? null;

            if (!$supplierId && $request->supplier_po_id) {
                $supplierPo = SupplierPurchaseOrder::find($request->supplier_po_id);
                $supplierId = $supplierPo?->supplier_id;
            }

            if (!$supplierId) {
                $product    = \App\Models\Product::find($request->product_id);
                $supplierId = $product?->supplier_id;
            }

            // Create or update batch
            $batch = StockBatch::where('batch_number', $request->batch_number)
                ->where('product_id', $request->product_id)
                ->where('company_id', $request->company_id)
                ->first();

            if (!$batch) {
                $batch = StockBatch::create([
                    'company_id'         => $request->company_id,
                    'batch_number'       => $request->batch_number,
                    'quantity_available' => $request->quantity,
                    'quantity_initial'   => $request->quantity,
                    'purchase_price'     => $request->purchase_price,
                    'product_id'         => $request->product_id,
                    'supplier_id'        => $supplierId,
                    'manufacture_date'   => $request->manufacture_date,
                    'expiry_date'        => $request->expiry_date,
                    'status'             => 'active',
                ]);
            } else {
                $batch->increment('quantity_available', $request->quantity);
                $batch->increment('quantity_initial', $request->quantity);
            }

            // Create StockIn record
            $stockIn = StockIn::create([
                'company_id'           => $request->company_id,
                'supplier_po_id'       => $request->supplier_po_id,
                'delivery_note_number' => $request->delivery_note_number,
                'delivery_note_date'   => $request->delivery_note_date,
                'product_id'           => $request->product_id,
                'batch_id'             => $batch->batch_id,
                'quantity'             => $request->quantity,
                'purchase_price'       => $request->purchase_price,
                'received_date'        => $request->received_date ?? now()->toDateString(),
                'notes'                => $request->notes,
                'received_by'          => Auth::id(),
            ]);

            // Upload file jika ada
            if ($request->hasFile('delivery_note_file')) {
                $stockIn->uploadDeliveryNote($request->file('delivery_note_file'));
            }

            // Stock movement
            \App\Models\StockMovement::create([
                'product_id'     => $request->product_id,
                'batch_id'       => $batch->batch_id,
                'movement_type'  => 'in',  // ✅ lowercase konsisten
                'quantity'       => $request->quantity,
                'unit_cost'      => $request->purchase_price,
                'reference_id'   => $stockIn->stock_in_id,
                'reference_type' => 'stock_in',
                'notes'          => 'Stock IN manual: ' . ($request->notes ?? ''),
                'created_by'     => Auth::id(),
            ]);

            // Auto-upsert ProductSupplier ✅ FIXED: tidak pakai updateFromStockIn()
            if ($supplierId) {
                $this->upsertProductSupplier(
                    productId:     $request->product_id,
                    supplierId:    $supplierId,
                    companyId:     $request->company_id,
                    purchasePrice: (float) $request->purchase_price,
                    stockInId:     $stockIn->stock_in_id
                );
            } else {
                Log::warning('⚠️ Stock In created without supplier', [
                    'stock_in_id'    => $stockIn->stock_in_id,
                    'product_id'     => $request->product_id,
                    'supplier_po_id' => $request->supplier_po_id,
                ]);
            }

            // EndingStock di dalam transaction ✅ FIXED
            $date = \Carbon\Carbon::parse($stockIn->received_date);
            EndingStock::updateEndingStock($batch->batch_id, $date->year, $date->month);

            DB::commit();

            $stockIn->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Stock in transaction created successfully',
                'data'    => $stockIn->load(['product', 'batch', 'supplierPo', 'company']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock in transaction',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $stockIn = StockIn::with(['product', 'batch.supplier', 'supplierPo.supplier', 'company', 'receivedByUser'])
            ->find($id);

        if (!$stockIn) {
            return response()->json([
                'success' => false,
                'message' => 'Stock in transaction not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock in transaction retrieved successfully',
            'data'    => $stockIn,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $stockIn = StockIn::find($id);

        if (!$stockIn) {
            return response()->json([
                'success' => false,
                'message' => 'Stock in transaction not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'supplier_po_id'       => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'delivery_note_number' => 'nullable|string|max:100',
            'delivery_note_date'   => 'nullable|date',
            'delivery_note_file'   => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'quantity'             => 'sometimes|required|integer|min:1',
            'purchase_price'       => 'sometimes|required|numeric|min:0',
            'received_date'        => 'nullable|date',
            'notes'                => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $priceChanged = $request->has('purchase_price') &&
                $request->purchase_price != $stockIn->purchase_price;

            $stockIn->update($request->except(['delivery_note_file']));

            if ($request->hasFile('delivery_note_file')) {
                $stockIn->uploadDeliveryNote($request->file('delivery_note_file'));
            }

            // Update ProductSupplier jika harga berubah
            if ($priceChanged) {
                $supplierId = null;

                if ($stockIn->supplier_po_id) {
                    $supplierPo = SupplierPurchaseOrder::find($stockIn->supplier_po_id);
                    $supplierId = $supplierPo?->supplier_id;
                }

                // ✅ Fallback ke batch.supplier_id
                if (!$supplierId) {
                    $supplierId = $stockIn->batch?->supplier_id;
                }

                if ($supplierId) {
                    $this->upsertProductSupplier(
                        productId:     $stockIn->product_id,
                        supplierId:    $supplierId,
                        companyId:     $stockIn->company_id,
                        purchasePrice: (float) $request->purchase_price,
                        stockInId:     $stockIn->stock_in_id
                    );
                }
            }

            // EndingStock di dalam transaction ✅ FIXED
            $date  = \Carbon\Carbon::parse($stockIn->received_date);
            $batch = $stockIn->batch;
            if ($batch) {
                EndingStock::updateEndingStock($batch->batch_id, $date->year, $date->month);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock in transaction updated successfully',
                'data'    => $stockIn->load(['product', 'batch', 'supplierPo', 'company']),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock in transaction',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $stockIn = StockIn::find($id);

        if (!$stockIn) {
            return response()->json([
                'success' => false,
                'message' => 'Stock in transaction not found',
            ], 404);
        }

        try {
            DB::beginTransaction();
            $stockIn->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock in transaction deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock in transaction',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadDeliveryNote($id)
    {
        $stockIn = StockIn::find($id);

        if (!$stockIn) {
            return response()->json(['success' => false, 'message' => 'Stock in transaction not found'], 404);
        }

        if (!$stockIn->hasDeliveryNote()) {
            return response()->json(['success' => false, 'message' => 'Delivery note file not found'], 404);
        }

        return Storage::download($stockIn->delivery_note_file);
    }

    public function deleteDeliveryNoteFile($id)
    {
        $stockIn = StockIn::find($id);

        if (!$stockIn) {
            return response()->json(['success' => false, 'message' => 'Stock in transaction not found'], 404);
        }

        try {
            $stockIn->deleteDeliveryNote();
            $stockIn->update(['delivery_note_file' => null]);

            return response()->json(['success' => true, 'message' => 'Delivery note file deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete delivery note file',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function summary(Request $request)
    {
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

        if ($request->has('product_id')) {
            $query->where('products.product_id', $request->product_id);
        }

        if ($request->has('company_id')) {
            $query->where('stock_in.company_id', $request->company_id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock summary retrieved successfully',
            'data'    => $query->get(),
        ], 200);
    }

    // =========================================================
    // PRIVATE HELPERS
    // =========================================================

    /**
     * Upsert ProductSupplier — dipakai di store & update
     * Menggantikan ProductSupplier::updateFromStockIn() yang tidak ada
     */
    private function upsertProductSupplier(
        int $productId,
        int $supplierId,
        int $companyId,
        float $purchasePrice,
        int $stockInId
    ): void {
        try {
            $existing = ProductSupplier::where('product_id', $productId)
                ->where('supplier_id', $supplierId)
                ->where('company_id', $companyId)
                ->first();

            if (!$existing) {
                ProductSupplier::create([
                    'product_id'     => $productId,
                    'supplier_id'    => $supplierId,
                    'company_id'     => $companyId,
                    'purchase_price' => $purchasePrice,
                    'is_primary'     => false,
                    'is_active'      => true,
                ]);
            } else {
                $existing->update(['purchase_price' => $purchasePrice]);
            }

            Log::info('✅ Upserted ProductSupplier', [
                'stock_in_id'    => $stockInId,
                'product_id'     => $productId,
                'supplier_id'    => $supplierId,
                'purchase_price' => $purchasePrice,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Failed to upsert ProductSupplier', [
                'stock_in_id' => $stockInId,
                'error'       => $e->getMessage(),
            ]);
            // Tidak throw — jangan gagalkan transaksi utama karena ini secondary operation
        }
    }
}
