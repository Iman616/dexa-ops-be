<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierDeliveryNote;
use App\Models\SupplierDeliveryNoteItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\StockIn;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\ProductSupplier;
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

        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('delivery_note_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('supplier_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->has('start_date')) {
            $query->where('delivery_note_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('delivery_note_date', '<=', $request->end_date);
        }

        $sortBy    = $request->get('sort_by', 'delivery_note_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage       = $request->get('per_page', 15);
        $deliveryNotes = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Supplier delivery notes retrieved successfully',
            'data'    => $deliveryNotes,
        ], 200);
    }

    /**
     * Store a newly created supplier delivery note
     * POST /api/supplier-delivery-notes
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id'                         => 'required|exists:companies,company_id',
            'supplier_id'                        => 'nullable|exists:suppliers,supplier_id',
            'supplier_po_id'                     => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'delivery_note_number'               => 'required|string|max:100|unique:supplier_delivery_notes,delivery_note_number',
            'delivery_note_date'                 => 'required|date',
            'delivery_note_file'                 => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'notes'                              => 'nullable|string',
            'items'                              => 'required|array|min:1',
            'items.*.product_id'                 => 'required|exists:products,product_id',
            'items.*.batch_number'               => 'required|string|max:100',
            'items.*.quantity'                   => 'required|integer|min:1',
            'items.*.purchase_price'             => 'required|numeric|min:0',
            'items.*.manufacture_date'           => 'nullable|date',
            'items.*.expiry_date'                => 'nullable|date|after_or_equal:items.*.manufacture_date',
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

            $deliveryNote = SupplierDeliveryNote::create([
                'company_id'           => $request->company_id,
                'supplier_id'          => $request->supplier_id,
                'supplier_po_id'       => $request->supplier_po_id,
                'delivery_note_number' => $request->delivery_note_number,
                'delivery_note_date'   => $request->delivery_note_date,
                'status'               => 'pending',
                'notes'                => $request->notes,
                'created_by'           => Auth::id(),
            ]);

            if ($request->hasFile('delivery_note_file')) {
                $deliveryNote->uploadDeliveryNote($request->file('delivery_note_file'));
            }

            foreach ($request->items as $item) {
                SupplierDeliveryNoteItem::create([
                    'supplier_delivery_note_id' => $deliveryNote->supplier_delivery_note_id,
                    'product_id'                => $item['product_id'],
                    'batch_number'              => $item['batch_number'],
                    'quantity'                  => $item['quantity'],
                    'purchase_price'            => $item['purchase_price'],
                    'manufacture_date'          => $item['manufacture_date'] ?? null,
                    'expiry_date'               => $item['expiry_date'] ?? null,
                    'notes'                     => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $deliveryNote->load(['company', 'supplier', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => 'Supplier delivery note created successfully',
                'data'    => $deliveryNote,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create supplier delivery note',
                'error'   => $e->getMessage(),
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
            'createdByUser',
        ])->find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier delivery note not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Supplier delivery note retrieved successfully',
            'data'    => $deliveryNote,
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
                'message' => 'Supplier delivery note not found',
            ], 404);
        }

        if ($deliveryNote->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update delivery note that is already received or cancelled',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id'                        => 'nullable|exists:suppliers,supplier_id',
            'supplier_po_id'                     => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'delivery_note_number'               => 'sometimes|required|string|max:100|unique:supplier_delivery_notes,delivery_note_number,' . $id . ',supplier_delivery_note_id',
            'delivery_note_date'                 => 'sometimes|required|date',
            'delivery_note_file'                 => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'notes'                              => 'nullable|string',
            'items'                              => 'sometimes|required|array|min:1',
            'items.*.product_id'                 => 'required|exists:products,product_id',
            'items.*.batch_number'               => 'required|string|max:100',
            'items.*.quantity'                   => 'required|integer|min:1',
            'items.*.purchase_price'             => 'required|numeric|min:0',
            'items.*.manufacture_date'           => 'nullable|date',
            'items.*.expiry_date'                => 'nullable|date|after_or_equal:items.*.manufacture_date',
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

            $deliveryNote->update($request->except(['items', 'delivery_note_file']));

            if ($request->hasFile('delivery_note_file')) {
                $deliveryNote->uploadDeliveryNote($request->file('delivery_note_file'));
            }

            if ($request->has('items')) {
                $deliveryNote->items()->delete();

                foreach ($request->items as $item) {
                    SupplierDeliveryNoteItem::create([
                        'supplier_delivery_note_id' => $deliveryNote->supplier_delivery_note_id,
                        'product_id'                => $item['product_id'],
                        'batch_number'              => $item['batch_number'],
                        'quantity'                  => $item['quantity'],
                        'purchase_price'            => $item['purchase_price'],
                        'manufacture_date'          => $item['manufacture_date'] ?? null,
                        'expiry_date'               => $item['expiry_date'] ?? null,
                        'notes'                     => $item['notes'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $deliveryNote->load(['company', 'supplier', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => 'Supplier delivery note updated successfully',
                'data'    => $deliveryNote,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update supplier delivery note',
                'error'   => $e->getMessage(),
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
                'message' => 'Supplier delivery note not found',
            ], 404);
        }

        if ($deliveryNote->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete delivery note that is already received',
            ], 400);
        }

        try {
            DB::beginTransaction();
            $deliveryNote->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Supplier delivery note deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete supplier delivery note',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Receive goods from delivery note → Create Stock In + Auto-upsert ProductSupplier
     * POST /api/supplier-delivery-notes/{id}/receive
     */
    public function receiveGoods(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'receiver_name'             => 'required|string|max:100',
            'receiver_position'         => 'nullable|string|max:100',
            'received_datetime'         => 'nullable|date',
            'auto_create_draft_invoice' => 'nullable|boolean',
            'payment_terms'             => 'nullable|in:net7,net14,net30,net60',
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

            $deliveryNote = SupplierDeliveryNote::with(['items.product', 'company'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($deliveryNote->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery note sudah diproses atau dibatalkan',
                ], 422);
            }

            $receivedDatetime = $request->received_datetime
                ? Carbon::parse($request->received_datetime)
                : now();

            $stockInRecords = [];

            foreach ($deliveryNote->items as $item) {

                // 1) Create or update stock batch
                $batch = StockBatch::firstOrCreate(
                    [
                        'company_id'   => $deliveryNote->company_id,
                        'product_id'   => $item->product_id,
                        'batch_number' => $item->batch_number,
                    ],
                    [
                        'supplier_id'                    => $deliveryNote->supplier_id,
                        'supplier_delivery_note_item_id' => $item->item_id,
                        'manufacture_date'               => $item->manufacture_date,
                        'expiry_date'                    => $item->expiry_date,
                        'purchase_price'                 => $item->purchase_price,
                        'quantity_initial'               => 0,
                        'quantity_available'             => 0,
                        'status'                         => 'active',
                        'received_date'                  => $receivedDatetime,
                    ]
                );

                // 2) Create StockIn record
                $stockIn = StockIn::create([
                    'company_id'                     => $deliveryNote->company_id,
                    'product_id'                     => $item->product_id,
                    'batch_id'                       => $batch->batch_id,
                    'supplier_delivery_note_id'      => $deliveryNote->supplier_delivery_note_id,
                    'supplier_delivery_note_item_id' => $item->item_id,
                    'delivery_note_number'           => $deliveryNote->delivery_note_number,
                    'delivery_note_date'             => $deliveryNote->delivery_note_date,
                    'delivery_note_file'             => $deliveryNote->delivery_note_file,
                    'quantity'                       => $item->quantity,
                    'purchase_price'                 => $item->purchase_price,
                    'received_datetime'              => $receivedDatetime,
                    'receiver_name'                  => $request->receiver_name,
                    'receiver_position'              => $request->receiver_position,
                    'created_by'                     => Auth::id(),
                ]);

                // 3) Update batch quantity
                $batch->increment('quantity_available', $item->quantity);

                if ($batch->wasRecentlyCreated) {
                    $batch->update(['quantity_initial' => $batch->quantity_available]);
                }

                // 4) Stock movement
                StockMovement::create([
                    'product_id'    => $item->product_id,
                    'batch_id'      => $batch->batch_id,
                    'movement_type' => 'in',
                    'quantity'      => $item->quantity,
                    'unit_cost'     => $item->purchase_price,
                    'reference_id'  => $deliveryNote->supplier_delivery_note_id,
                    'reference_type'=> 'supplier_delivery_note',
                    'notes'         => 'Stock in from supplier delivery note ' . $deliveryNote->delivery_note_number,
                    'created_by'    => Auth::id(),
                ]);

                // 5) Update link di DN item
                $item->update(['stock_in_id' => $stockIn->stock_in_id]);

                // 6) Auto-upsert ProductSupplier ← FIXED: kurung benar
                if ($deliveryNote->supplier_id) {
                    $existingPS = ProductSupplier::where('product_id', $item->product_id)
                        ->where('supplier_id', $deliveryNote->supplier_id)
                        ->where('company_id', $deliveryNote->company_id)
                        ->first();

                    if (!$existingPS) {
                        ProductSupplier::create([
                            'product_id'     => $item->product_id,
                            'supplier_id'    => $deliveryNote->supplier_id,
                            'company_id'     => $deliveryNote->company_id,
                            'purchase_price' => $item->purchase_price,
                            'is_primary'     => false,
                            'is_active'      => true,
                        ]);
                    } else {
                        $existingPS->update([
                            'purchase_price' => $item->purchase_price,
                        ]);
                    }
                }

                // ✅ FIXED: Ini di luar blok if supplier_id
                $stockInRecords[] = $stockIn;
            }

            // Update DN status
            $deliveryNote->update([
                'status'            => 'received',
                'received_datetime' => $receivedDatetime,
                'receiver_name'     => $request->receiver_name,
                'receiver_position' => $request->receiver_position,
            ]);

            // Update Supplier PO status jika ada
            if ($deliveryNote->supplier_po_id) {
                $this->updateSupplierPoStatus($deliveryNote->supplier_po_id);
            }

            // Auto-create draft invoice jika diminta
            $draftInvoice = null;
            if ($request->boolean('auto_create_draft_invoice')) {
                $draftInvoice = $this->createDraftInvoice($deliveryNote, $request);
            }

            DB::commit();

            $deliveryNote->load(['items.product', 'supplier', 'company']);

            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil diterima' . ($draftInvoice ? ' dan draft invoice dibuat' : ''),
                'data'    => [
                    'delivery_note'    => $deliveryNote,
                    'stock_in_records' => $stockInRecords,
                    'draft_invoice'    => $draftInvoice,
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Receive goods error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to receive goods',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create draft invoice from delivery note
     */
    private function createDraftInvoice($deliveryNote, $request)
    {
        $totalAmount = $deliveryNote->items->sum(fn($item) => $item->quantity * $item->purchase_price);

        $draftNumber  = 'DRAFT-' . $deliveryNote->delivery_note_number;
        $paymentTerms = $request->payment_terms ?? 'net30';
        $daysToAdd    = match ($paymentTerms) {
            'net7'  => 7,
            'net14' => 14,
            'net30' => 30,
            'net60' => 60,
            default => 30,
        };

        $invoice = SupplierInvoice::create([
            'supplier_id'               => $deliveryNote->supplier_id,
            'supplier_po_id'            => $deliveryNote->supplier_po_id,
            'supplier_delivery_note_id' => $deliveryNote->supplier_delivery_note_id,
            'invoice_number'            => $draftNumber,
            'invoice_date'              => now(),
            'due_date'                  => now()->addDays($daysToAdd),
            'payment_terms'             => $paymentTerms,
            'total_amount'              => $totalAmount,
            'paid_amount'               => 0,
            'payment_status'            => 'unpaid',
            'invoice_status'            => 'draft',
            'notes'                     => 'Auto-generated draft from ' . $deliveryNote->delivery_note_number,
            'created_by'                => Auth::id(),
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);

        foreach ($deliveryNote->items as $dnItem) {
            SupplierInvoiceItem::create([
                'supplier_invoice_id' => $invoice->supplier_invoice_id,
                'product_id'          => $dnItem->product_id,
                'product_name'        => $dnItem->product->product_name,
                'quantity'            => $dnItem->quantity,
                'unit'                => $dnItem->product->unit,
                'unit_price'          => $dnItem->purchase_price,
                'created_at'          => now(),
            ]);
        }

        return $invoice->fresh(['items', 'supplier', 'supplierDeliveryNote', 'createdByUser']);
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
                'message' => 'Supplier delivery note not found',
            ], 404);
        }

        if (!$deliveryNote->delivery_note_file) {
            return response()->json([
                'success' => false,
                'message' => 'File not found',
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
        $query = SupplierDeliveryNote::with(['company', 'supplier', 'items.product'])
            ->where('status', 'pending');

        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pending delivery notes retrieved successfully',
            'data'    => $query->get(),
        ], 200);
    }

    /**
     * Update Supplier PO Status
     */
    private function updateSupplierPoStatus($supplierPoId)
    {
        $po = \App\Models\SupplierPurchaseOrder::find($supplierPoId);
        if (!$po) return;

        $items         = DB::table('supplier_purchase_order_items')
            ->where('supplier_po_id', $supplierPoId)
            ->get();

        $totalOrdered  = $items->sum('quantity');
        $totalReceived = $items->sum('received_quantity');

        if ($totalReceived >= $totalOrdered) {
            $po->update(['status' => 'completed']);
        } elseif ($totalReceived > 0) {
            $po->update(['status' => 'partial']);
        }
    }
}
