<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierDeliveryNote;
use App\Models\SupplierDeliveryNoteItem;
use App\Models\StockIn;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\EndingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


class SupplierDeliveryNoteController extends Controller
{
    /**
     * Display a listing of supplier delivery notes
     * GET /api/supplier-delivery-notes
     */
public function index(Request $request)
{
    $query = SupplierDeliveryNote::with([
        'company',
        'supplier',
        'supplierPo',
        'items.product',
        'receivedByUser',
        'createdByUser'
    ]);

    // ✅ DEBUG: Log total records
    Log::info('Total Delivery Notes in DB:', ['count' => SupplierDeliveryNote::count()]);

    // Filter by company
    if ($request->has('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    // Filter by supplier
    if ($request->has('supplier_id')) {
        $query->where('supplier_id', $request->supplier_id);
    }

    // Filter by status
    if ($request->has('status')) {
        $query->where('status', $request->status);
    }

    // Filter by delivery note number
    if ($request->has('search')) {
        $search = $request->search;
        $query->where(function($q) use ($search) {
            $q->where('delivery_note_number', 'like', "%{$search}%")
              ->orWhereHas('supplier', function($sq) use ($search) {
                  $sq->where('supplier_name', 'like', "%{$search}%");
              });
        });
    }

    // Filter by date range
    if ($request->has('start_date')) {
        $query->where('delivery_note_date', '>=', $request->start_date);
    }

    if ($request->has('end_date')) {
        $query->where('delivery_note_date', '<=', $request->end_date);
    }

    // Sorting
    $sortBy = $request->get('sort_by', 'delivery_note_date');
    $sortOrder = $request->get('sort_order', 'desc');
    $query->orderBy($sortBy, $sortOrder);

    // ✅ DEBUG: Log query SQL
    Log::info('Query SQL:', ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);

    // Pagination
    $perPage = $request->get('per_page', 15);
    $deliveryNotes = $query->paginate($perPage);

    // ✅ DEBUG: Log result count
    Log::info('Query Result Count:', ['count' => $deliveryNotes->count()]);

    return response()->json([
        'success' => true,
        'message' => 'Supplier delivery notes retrieved successfully',
        'data' => $deliveryNotes
    ], 200);
}

    /**
     * Store a newly created supplier delivery note
     * POST /api/supplier-delivery-notes
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
            'supplier_id' => 'nullable|exists:suppliers,supplier_id',
            'supplier_po_id' => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'delivery_note_number' => 'required|string|max:100|unique:supplier_delivery_notes,delivery_note_number',
            'delivery_note_date' => 'required|date',
            'delivery_note_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'notes' => 'nullable|string',
            
            // Items
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.batch_number' => 'required|string|max:100',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.purchase_price' => 'required|numeric|min:0',
            'items.*.manufacture_date' => 'nullable|date',
            'items.*.expiry_date' => 'nullable|date|after_or_equal:items.*.manufacture_date',
        ], [
            'delivery_note_file.mimes' => 'File harus PDF, JPG, JPEG, atau PNG',
            'delivery_note_file.max' => 'Ukuran file maksimal 5MB',
            'items.required' => 'Minimal 1 item produk harus diisi',
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

            // Create delivery note
            $deliveryNote = SupplierDeliveryNote::create([
                'company_id' => $request->company_id,
                'supplier_id' => $request->supplier_id,
                'supplier_po_id' => $request->supplier_po_id,
                'delivery_note_number' => $request->delivery_note_number,
                'delivery_note_date' => $request->delivery_note_date,
                'status' => 'pending',
                'notes' => $request->notes,
                'created_by' => Auth::id(),
            ]);

            // Upload file if exists
            if ($request->hasFile('delivery_note_file')) {
                $deliveryNote->uploadDeliveryNote($request->file('delivery_note_file'));
            }

            // Create items
            foreach ($request->items as $item) {
                SupplierDeliveryNoteItem::create([
                    'supplier_delivery_note_id' => $deliveryNote->supplier_delivery_note_id,
                    'product_id' => $item['product_id'],
                    'batch_number' => $item['batch_number'],
                    'quantity' => $item['quantity'],
                    'purchase_price' => $item['purchase_price'],
                    'manufacture_date' => $item['manufacture_date'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $deliveryNote->load(['company', 'supplier', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => 'Supplier delivery note created successfully',
                'data' => $deliveryNote
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create supplier delivery note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified supplier delivery note
     * GET /api/supplier-delivery-notes/{id}
     */
    public function show($id)
    {
        $deliveryNote = SupplierDeliveryNote::with([
            'company',
            'supplier',
            'supplierPo',
            'items.product',
            'items.stockIn',
            'receivedByUser',
            'createdByUser'
        ])->find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier delivery note not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Supplier delivery note retrieved successfully',
            'data' => $deliveryNote
        ], 200);
    }

    /**
     * Update the specified supplier delivery note
     * PUT /api/supplier-delivery-notes/{id}
     */
    public function update(Request $request, $id)
    {
        $deliveryNote = SupplierDeliveryNote::find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier delivery note not found'
            ], 404);
        }

        // Only allow update if status is pending
        if ($deliveryNote->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update delivery note that is already received or cancelled'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'nullable|exists:suppliers,supplier_id',
            'supplier_po_id' => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'delivery_note_number' => 'sometimes|required|string|max:100|unique:supplier_delivery_notes,delivery_note_number,' . $id . ',supplier_delivery_note_id',
            'delivery_note_date' => 'sometimes|required|date',
            'delivery_note_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'notes' => 'nullable|string',
            
            // Items
            'items' => 'sometimes|required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.batch_number' => 'required|string|max:100',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.purchase_price' => 'required|numeric|min:0',
            'items.*.manufacture_date' => 'nullable|date',
            'items.*.expiry_date' => 'nullable|date|after_or_equal:items.*.manufacture_date',
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

            // Update delivery note
            $deliveryNote->update($request->except(['items', 'delivery_note_file']));

            // Upload file if exists
            if ($request->hasFile('delivery_note_file')) {
                $deliveryNote->uploadDeliveryNote($request->file('delivery_note_file'));
            }

            // Update items if provided
            if ($request->has('items')) {
                // Delete old items
                $deliveryNote->items()->delete();

                // Create new items
                foreach ($request->items as $item) {
                    SupplierDeliveryNoteItem::create([
                        'supplier_delivery_note_id' => $deliveryNote->supplier_delivery_note_id,
                        'product_id' => $item['product_id'],
                        'batch_number' => $item['batch_number'],
                        'quantity' => $item['quantity'],
                        'purchase_price' => $item['purchase_price'],
                        'manufacture_date' => $item['manufacture_date'] ?? null,
                        'expiry_date' => $item['expiry_date'] ?? null,
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $deliveryNote->load(['company', 'supplier', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => 'Supplier delivery note updated successfully',
                'data' => $deliveryNote
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update supplier delivery note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified supplier delivery note
     * DELETE /api/supplier-delivery-notes/{id}
     */
    public function destroy($id)
    {
        $deliveryNote = SupplierDeliveryNote::find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier delivery note not found'
            ], 404);
        }

        // Only allow delete if status is pending
        if ($deliveryNote->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete delivery note that is already received'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $deliveryNote->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Supplier delivery note deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete supplier delivery note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Receive goods from supplier delivery note (Create Stock IN)
     * POST /api/supplier-delivery-notes/{id}/receive
     */
public function receiveGoods(Request $request, $id)
{
    $deliveryNote = SupplierDeliveryNote::with(['items.product', 'company', 'supplier'])->find($id);

    if (!$deliveryNote) {
        return response()->json([
            'success' => false,
            'message' => 'Supplier delivery note not found'
        ], 404);
    }

    if ($deliveryNote->status !== 'pending') {
        return response()->json([
            'success' => false,
            'message' => 'Delivery note is already received or cancelled'
        ], 400);
    }

    $validator = Validator::make($request->all(), [
        'receiver_name' => 'required|string|max:255',
        'receiver_position' => 'nullable|string|max:100',
        'received_datetime' => 'nullable|date',
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

        $receivedDatetime = $request->received_datetime ?? now();
        $stockInRecords = [];

        // ✅ Pastikan company_id ada
        if (!$deliveryNote->company_id) {
            throw new \Exception('Company ID is required');
        }

        foreach ($deliveryNote->items as $item) {
            // ✅ Get or create batch DENGAN company_id
            $batch = StockBatch::where('batch_number', $item->batch_number)
                ->where('product_id', $item->product_id)
                ->where('company_id', $deliveryNote->company_id)
                ->first();

            if (!$batch) {
                // ✅ CREATE batch dengan company_id WAJIB
                $batch = StockBatch::create([
                    'company_id' => $deliveryNote->company_id, // ✅ CRITICAL
                    'batch_number' => $item->batch_number,
                    'product_id' => $item->product_id,
                    'quantity_available' => $item->quantity,
                    'quantity_initial' => $item->quantity,
                    'purchase_price' => $item->purchase_price,
                    'supplier_id' => $deliveryNote->supplier_id,
                    'manufacture_date' => $item->manufacture_date,
                    'expiry_date' => $item->expiry_date,
                    'status' => 'active',
                ]);
            } else {
                // Update existing batch
                $batch->increment('quantity_available', $item->quantity);
                $batch->increment('quantity_initial', $item->quantity);
            }

            // Create Stock IN
            $stockIn = StockIn::create([
                'company_id' => $deliveryNote->company_id,
                'supplier_po_id' => $deliveryNote->supplier_po_id,
                'supplier_delivery_note_id' => $deliveryNote->supplier_delivery_note_id,
                'delivery_note_number' => $deliveryNote->delivery_note_number,
                'delivery_note_date' => $deliveryNote->delivery_note_date,
                'delivery_note_file' => $deliveryNote->delivery_note_file,
                'product_id' => $item->product_id,
                'batch_id' => $batch->batch_id,
                'quantity' => $item->quantity,
                'purchase_price' => $item->purchase_price,
                'received_date' => Carbon::parse($receivedDatetime)->toDateString(),
                'received_datetime' => $receivedDatetime,
                'receiver_name' => $request->receiver_name,
                'receiver_position' => $request->receiver_position,
                'notes' => $item->notes,
                'received_by' => Auth::id(),
            ]);

            // Update item with stock_in_id
            $item->update(['stock_in_id' => $stockIn->stock_in_id]);

            // Create Stock Movement
            StockMovement::create([
                'company_id' => $deliveryNote->company_id, // ✅ Add if your table has this
                'product_id' => $item->product_id,
                'batch_id' => $batch->batch_id,
                'movement_type' => 'IN',
                'quantity' => $item->quantity,
                'unit_cost' => $item->purchase_price,
                'reference_id' => $stockIn->stock_in_id,
                'reference_type' => 'stock_in',
                'notes' => 'Terima dari DN: ' . $deliveryNote->delivery_note_number,
                'created_by' => Auth::id(),
            ]);

            // Update Ending Stock
            $date = Carbon::parse($receivedDatetime);
            EndingStock::updateEndingStock($batch->batch_id, $date->year, $date->month);

            // Load relations
            $stockInRecords[] = $stockIn->fresh(['product', 'batch', 'company']);
        }

        // Update delivery note status
        $deliveryNote->update([
            'status' => 'received',
            'receiver_name' => $request->receiver_name,
            'receiver_position' => $request->receiver_position,
            'received_datetime' => $receivedDatetime,
            'received_by' => Auth::id(),
        ]);

        // Update PO Status if linked
        if ($deliveryNote->supplier_po_id) {
            $this->updateSupplierPoStatus($deliveryNote->supplier_po_id);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Goods received successfully',
            'data' => [
                'delivery_note' => $deliveryNote->fresh(['items.product', 'items.stockIn', 'company', 'supplier']),
                'stock_in_records' => $stockInRecords,
            ]
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Receive Goods Error:', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to receive goods',
            'error' => $e->getMessage()
        ], 500);
    }
}



    /**
     * Cancel supplier delivery note
     * POST /api/supplier-delivery-notes/{id}/cancel
     */
    public function cancel($id)
    {
        $deliveryNote = SupplierDeliveryNote::find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier delivery note not found'
            ], 404);
        }

        if ($deliveryNote->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending delivery notes can be cancelled'
            ], 400);
        }

        try {
            $deliveryNote->update(['status' => 'cancelled']);

            return response()->json([
                'success' => true,
                'message' => 'Supplier delivery note cancelled successfully',
                'data' => $deliveryNote
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel delivery note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download delivery note file
     * GET /api/supplier-delivery-notes/{id}/download
     */
    public function downloadFile($id)
    {
        $deliveryNote = SupplierDeliveryNote::find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier delivery note not found'
            ], 404);
        }

        if (!$deliveryNote->delivery_note_file) {
            return response()->json([
                'success' => false,
                'message' => 'File not found'
            ], 404);
        }

        return response()->download(
            storage_path('app/public/' . $deliveryNote->delivery_note_file)
        );
    }

 /**
 * Get pending delivery notes for dropdown
 * GET /api/supplier-delivery-notes/pending
 */
public function getPending(Request $request)
{
    $query = SupplierDeliveryNote::with([
        'company',
        'supplier',
        'items.product' // ✅ TAMBAHKAN ini
    ])
    ->where('status', 'pending');

    if ($request->has('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    $deliveryNotes = $query->get();

    return response()->json([
        'success' => true,
        'message' => 'Pending delivery notes retrieved successfully',
        'data' => $deliveryNotes
    ], 200);
}


    /**
     * Update Supplier PO Status
     */
    private function updateSupplierPoStatus($supplierPoId)
    {
        $po = \App\Models\SupplierPurchaseOrder::find($supplierPoId);
        if (!$po) return;

        $items = DB::table('supplier_purchase_order_items')
            ->where('supplier_po_id', $supplierPoId)
            ->get();

        $totalOrdered = $items->sum('quantity');
        $totalReceived = $items->sum('received_quantity');

        if ($totalReceived >= $totalOrdered) {
            $po->update(['status' => 'completed']);
        } elseif ($totalReceived > 0) {
            $po->update(['status' => 'partial']);
        }
    }
}
