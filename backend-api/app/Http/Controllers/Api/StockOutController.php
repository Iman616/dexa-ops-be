<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockOut;
use App\Models\EndingStock;
use App\Models\StockBatch;
use App\Models\Product;
use App\Models\Customer;
use App\Models\User;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\DocumentAttachment;
use App\Models\StockMovement;
use App\Models\ExpiryAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class StockOutController extends Controller
{
    /**
     * ✅ ENHANCED: Index with new fields + delivery integration
     */
    public function index(Request $request)
    {
        $query = StockOut::with([
            'product:product_id,product_code,product_name,category,unit,selling_price',
            'batch:batch_id,batch_number,product_id,expiry_date',
            'customer:customer_id,customer_name,contact_person,phone',
            'processedBy:user_id,username,full_name,email,role_id',
            'deliveryNote:delivery_note_id,delivery_note_number,recipient_name,delivery_status',
            'document_attachments',
            'stockMovements'
        ]);

        // ✅ NEW: Enhanced search dengan new fields
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('product', function($pq) use ($search) {
                    $pq->where('product_name', 'like', "%{$search}%")
                       ->orWhere('product_code', 'like', "%{$search}%");
                })
                ->orWhereHas('customer', function($cq) use ($search) {
                    $cq->where('customer_name', 'like', "%{$search}%");
                })
                ->orWhereHas('deliveryNote', function($dnq) use ($search) {
                    $dnq->where('delivery_note_number', 'like', "%{$search}%");
                })
                ->orWhere('received_by', 'like', "%{$search}%")
                ->orWhere('receiving_location', 'like', "%{$search}%");
            });
        }

        // ✅ NEW: Filter by delivery note
        if ($request->has('delivery_note_id')) {
            $query->where('delivery_note_id', $request->delivery_note_id);
        }

        // ✅ NEW: Filter by receiving condition
        if ($request->has('receiving_condition')) {
            $query->where('receiving_condition', $request->receiving_condition);
        }

        // ✅ NEW: Filter by received_by
        if ($request->has('received_by')) {
            $query->where('received_by', 'like', "%{$request->received_by}%");
        }

        // ✅ NEW: Only show gudang staff transactions
        if ($request->boolean('gudang_only')) {
            $query->whereHas('processedBy', function($q) {
                $q->where('role_id', 4); // 4 = Staff Gudang
            });
        }

        // Existing filters
        if ($request->has('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        if ($request->has('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('out_date', [$request->start_date, $request->end_date]);
        }

        $sortBy = $request->get('sort_by', 'out_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->integer('per_page', 15);
        $stockOuts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Stock out transactions retrieved successfully',
            'data' => $stockOuts
        ], 200);
    }

    /**
     * ✅ NEW: Get stock out by delivery note
     */
    public function getByDeliveryNote($deliveryNoteId)
    {
        $deliveryNote = DeliveryNote::with([
            'items.product',
            'items.batch'
        ])->find($deliveryNoteId);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found'
            ], 404);
        }

        $stockOuts = StockOut::with(['product', 'batch', 'customer', 'processedBy'])
            ->where('delivery_note_id', $deliveryNoteId)
            ->get();

        return response()->json([
            'success' => true,
            'delivery_note' => $deliveryNote,
            'stock_outs' => $stockOuts
        ], 200);
    }

    /**
     * ✅ NEW: Bulk create from delivery note (Surat Jalan)
     */
    public function bulkFromDeliveryNote(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_note_id' => 'required|exists:delivery_notes,delivery_note_id',
            'out_date' => 'required|date',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $deliveryNote = DeliveryNote::with('items.product')->find($request->delivery_note_id);
            
            if ($deliveryNote->delivery_status !== 'delivered') {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery note belum ter-deliver, tidak bisa create stock OUT'
                ], 400);
            }

            $stockOutRecords = [];
            $processedBy = Auth::id();

            foreach ($deliveryNote->items as $item) {
                // Get available batches dengan FIFO
                $batches = StockBatch::where('product_id', $item->product_id)
                    ->selectRaw('
                        stock_batches.*,
                        COALESCE(
                            (SELECT SUM(quantity) FROM stock_opening WHERE stock_opening.batch_id = stock_batches.batch_id), 0
                        ) +
                        COALESCE(
                            (SELECT SUM(quantity) FROM stock_in WHERE stock_in.batch_id = stock_batches.batch_id), 0
                        ) -
                        COALESCE(
                            (SELECT SUM(quantity) FROM stock_out WHERE stock_out.batch_id = stock_batches.batch_id), 0
                        )
                        AS available_qty
                    ')
                    ->having('available_qty', '>', 0)
                    ->orderBy('expiry_date', 'asc')
                    ->orderBy('batch_id', 'asc')
                    ->get();

                $totalAvailable = $batches->sum('available_qty');

                if ($totalAvailable < $item->quantity) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Stok tidak mencukupi untuk produk {$item->product->product_name}",
                        'available' => $totalAvailable,
                        'requested' => $item->quantity
                    ], 400);
                }

                // Process FIFO deduction
                $remaining = $item->quantity;

                foreach ($batches as $batch) {
                    if ($remaining <= 0) break;

                    $used = min($remaining, $batch->available_qty);

                    $stockOut = StockOut::create([
                        'product_id' => $item->product_id,
                        'batch_id' => $batch->batch_id,
                        'customer_id' => $deliveryNote->customer_id,
                        'delivery_note_id' => $deliveryNote->delivery_note_id,
                        'transaction_type' => 'sale',
                        'quantity' => $used,
                        'selling_price' => $item->selling_price,
                        'out_date' => $request->out_date,
                        'notes' => $request->notes,
                        'processed_by' => $processedBy,
                        'receiving_condition' => 'good' // default
                    ]);

                    // ✅ NEW: Create stock movement
                    StockMovement::create([
                        'batch_id' => $batch->batch_id,
                        'movement_type' => 'OUT',
                        'reference_type' => 'delivery_note',
                        'reference_id' => $deliveryNote->delivery_note_id,
                        'quantity' => $used,
                        'unit_cost' => $batch->purchase_price,
                        'total_cost' => $used * $batch->purchase_price,
                        'movement_date' => $request->out_date,
                        'notes' => "Stock OUT dari delivery note {$deliveryNote->delivery_note_number}"
                    ]);

                    $stockOutRecords[] = $stockOut;
                    $remaining -= $used;

                    // Update ending stock
                    $date = Carbon::parse($request->out_date);
                    EndingStock::updateEndingStock($batch->batch_id, $date->year, $date->month);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock OUT dari delivery note berhasil dicatat',
                'data' => $stockOutRecords
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal create stock OUT',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NEW: Mark as received (untuk return/rejected delivery)
     */
    public function markAsReceived(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'received_by' => 'required|string|max:100',
            'receiving_location' => 'required|string|max:100',
            'receiving_condition' => 'required|in:good,damaged,partial',
            'receiving_datetime' => 'required|date',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $stockOut = StockOut::find($id);

        if (!$stockOut) {
            return response()->json([
                'success' => false,
                'message' => 'Stock out tidak ditemukan'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $stockOut->update([
                'received_by' => $request->received_by,
                'receiving_location' => $request->receiving_location,
                'receiving_condition' => $request->receiving_condition,
                'receiving_datetime' => $request->receiving_datetime,
                'notes' => $request->notes
            ]);

            // ✅ If damaged or partial, create adjustment movement
            if (in_array($request->receiving_condition, ['damaged', 'partial'])) {
                StockMovement::create([
                    'batch_id' => $stockOut->batch_id,
                    'movement_type' => 'ADJUSTMENT',
                    'reference_type' => 'stock_out',
                    'reference_id' => $stockOut->stock_out_id,
                    'quantity' => $stockOut->quantity,
                    'unit_cost' => $stockOut->batch->purchase_price,
                    'total_cost' => $stockOut->quantity * $stockOut->batch->purchase_price,
                    'movement_date' => now(),
                    'notes' => "Adjustment: barang {$request->receiving_condition}"
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Status penerimaan berhasil diupdate',
                'data' => $stockOut->fresh()
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NEW: Upload bukti penerimaan (foto/TTD)
     */
    public function uploadProof(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB
            'document_type' => 'required|in:signature,photo,document'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $stockOut = StockOut::find($id);

        if (!$stockOut) {
            return response()->json([
                'success' => false,
                'message' => 'Stock out tidak ditemukan'
            ], 404);
        }

        try {
            $file = $request->file('file');
            $filename = 'proof_' . time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('stock_out_proofs', $filename, 'public');

            // ✅ Save to document_attachments table
            $attachment = DocumentAttachment::create([
                'reference_type' => 'stock_out',
                'reference_id' => $stockOut->stock_out_id,
                'document_type' => $request->document_type,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bukti berhasil diupload',
                'data' => $attachment
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal upload file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ ENHANCED: Store with new fields
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,product_id',
            'customer_id' => 'nullable|exists:customers,customer_id',
            'delivery_note_id' => 'nullable|exists:delivery_notes,delivery_note_id',
            'quantity' => 'required|integer|min:1',
            'out_date' => 'required|date',
            'transaction_type' => 'required|in:sale,usage,adjustment,waste',
            'selling_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'processed_by' => 'nullable|exists:users,user_id',
            'received_by' => 'nullable|string|max:100',
            'receiving_location' => 'nullable|string|max:100',
            'receiving_condition' => 'nullable|in:good,damaged,partial',
            'receiving_datetime' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Get FIFO batches
            $batches = StockBatch::where('product_id', $request->product_id)
                ->selectRaw('
                    stock_batches.*,
                    COALESCE(
                        (SELECT SUM(quantity) FROM stock_opening WHERE stock_opening.batch_id = stock_batches.batch_id), 0
                    ) +
                    COALESCE(
                        (SELECT SUM(quantity) FROM stock_in WHERE stock_in.batch_id = stock_batches.batch_id), 0
                    ) -
                    COALESCE(
                        (SELECT SUM(quantity) FROM stock_out WHERE stock_out.batch_id = stock_batches.batch_id), 0
                    )
                    AS available_qty
                ')
                ->having('available_qty', '>', 0)
                ->orderBy('expiry_date', 'asc')
                ->orderBy('batch_id', 'asc')
                ->get();

            $totalAvailable = $batches->sum('available_qty');

            if ($totalAvailable < $request->quantity) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Stok tidak mencukupi',
                    'available' => $totalAvailable,
                    'requested' => $request->quantity
                ], 400);
            }

            $processedBy = $request->processed_by ?? Auth::id();
            $remaining = $request->quantity;
            $stockOutRecords = [];

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;

                $used = min($remaining, $batch->available_qty);

                $stockOut = StockOut::create([
    'company_id'        => $request->company_id,
    'product_id'        => $request->product_id,
    'batch_id'          => $request->batch_id,
    'customer_id'       => $request->customer_id,
    'transaction_type'  => $request->transaction_type,
    'quantity'          => $request->quantity,
    'selling_price'     => $request->selling_price,
    'out_date'          => $request->out_date,
    'notes'             => $request->notes,
                    'processed_by' => $processedBy,
                    
    'received_by'       => $request->received_by,
    'receiving_location'=> $request->receiving_location,
    'receiving_condition'=> $request->receiving_condition ?? 'good',
    'receiving_datetime'=> $request->receiving_datetime,
]);
                // ✅ Create stock movement
               

                $stockOutRecords[] = $stockOut;
                $remaining -= $used;

                // Update ending stock
                $date = Carbon::parse($request->out_date);
                EndingStock::updateEndingStock($batch->batch_id, $date->year, $date->month);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock out recorded successfully',
                'data' => $stockOutRecords
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to record stock out',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show single record
     */
    public function show($id)
    {
        $stockOut = StockOut::with([
            'product',
            'batch',
            'customer',
            'processedBy',
            'deliveryNote',
            'document_attachments',
            'stockMovements'
        ])->find($id);

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

    /**
     * Update existing record
     */
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
            'customer_id' => 'nullable|exists:customers,customer_id',
            'transaction_type' => 'required|in:sale,usage,adjustment,waste',
            'selling_price' => 'required|numeric|min:0',
            'out_date' => 'required|date',
            'notes' => 'nullable|string',
            'received_by' => 'nullable|string|max:100',
            'receiving_location' => 'nullable|string|max:100',
            'receiving_condition' => 'nullable|in:good,damaged,partial',
            'receiving_datetime' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stockOut->update($request->only([
                'customer_id',
                'transaction_type',
                'selling_price',
                'out_date',
                'notes',
                'received_by',
                'receiving_location',
                'receiving_condition',
                'receiving_datetime'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Stock out updated successfully',
                'data' => $stockOut->fresh()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock out',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete record
     */
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
            DB::beginTransaction();

            $batchId = $stockOut->batch_id;
            $date = Carbon::parse($stockOut->out_date);

            $stockOut->delete();

            // Update ending stock
            EndingStock::updateEndingStock($batchId, $date->year, $date->month);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock out deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock out',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get batches by product (FIFO order)
     */
    public function getBatchesByProduct($productId)
    {
        $batches = StockBatch::where('product_id', $productId)
            ->selectRaw('
                stock_batches.*,
                COALESCE(
                    (SELECT SUM(quantity) FROM stock_opening WHERE stock_opening.batch_id = stock_batches.batch_id), 0
                ) +
                COALESCE(
                    (SELECT SUM(quantity) FROM stock_in WHERE stock_in.batch_id = stock_batches.batch_id), 0
                ) -
                COALESCE(
                    (SELECT SUM(quantity) FROM stock_out WHERE stock_out.batch_id = stock_batches.batch_id), 0
                )
                AS available_stock
            ')
            ->having('available_stock', '>', 0)
            ->orderBy('expiry_date', 'asc')
            ->orderBy('batch_id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $batches
        ], 200);
    }

    /**
     * Generate report
     */
    public function report(Request $request)
    {
        $query = StockOut::with([
            'product',
            'customer',
            'processedBy',
            'deliveryNote'
        ]);

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('out_date', [$request->start_date, $request->end_date]);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $data = $query->orderBy('out_date', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * Get summary statistics
     */
    public function summary(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $summary = StockOut::selectRaw('
                COUNT(DISTINCT stock_out_id) as total_transactions,
                SUM(quantity) as total_quantity,
                SUM(quantity * selling_price) as total_sales
            ')
            ->whereYear('out_date', $request->year)
            ->whereMonth('out_date', $request->month)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $request->month . '-' . $request->year,
                'total_transactions' => $summary->total_transactions ?? 0,
                'total_quantity' => $summary->total_quantity ?? 0,
                'total_sales' => $summary->total_sales ?? 0
            ]
        ], 200);
    }
}
