<?php

namespace App\Http\Controllers\Api;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\PurchaseOrder;
use App\Models\Company;
use App\Services\DeliveryNoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class DeliveryNoteController extends BaseController  // ✅ extends BaseController
{
    protected $deliveryNoteService;

    public function __construct(DeliveryNoteService $deliveryNoteService)
    {
        $this->deliveryNoteService = $deliveryNoteService;
    }

    /**
     * Display a listing of delivery notes
     * GET /api/delivery-notes
     */
    public function index(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $query = DeliveryNote::with([
            'company',
            'invoice.customer',
            'purchaseOrder.customer',
            'quotation',
            'createdByUser',
            'issuedByUser',
            'items.product',
            'purchaseOrder.activity_type',
            'quotation.activityType',
        ])
        ->where('company_id', $companyId); // ✅ auto-filter, hapus if has('company_id')

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('po_id')) {
            $query->where('po_id', $request->po_id);
        }

        if ($request->filled('quotation_id')) {
            $query->where('quotation_id', $request->quotation_id);
        }

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('delivery_note_number', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('recipient_address', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort_by', 'delivery_note_id');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $deliveryNotes = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Delivery notes retrieved successfully',
            'data' => $deliveryNotes
        ], 200);
    }

    /**
     * Store a newly created delivery note (manual)
     * POST /api/delivery-notes
     */
    public function store(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $validator = Validator::make($request->all(), [
            // ✅ company_id tidak perlu dari request, diambil dari session
            'invoice_id' => 'nullable|exists:invoices,invoice_id',
            'po_id' => 'nullable|exists:purchase_orders,po_id',
            'quotation_id' => 'nullable|exists:quotations,quotation_id',
            'delivery_note_number' => 'required|string|max:100|unique:delivery_notes,delivery_note_number',
            'delivery_date' => 'required|date',
            'recipient_name' => 'required|string|max:255',
            'recipient_address' => 'nullable|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.product_code' => 'nullable|string|max:100',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:50',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $deliveryNote = DeliveryNote::create([
                'company_id' => $companyId, // ✅ dari session
                'invoice_id' => $request->invoice_id,
                'po_id' => $request->po_id,
                'quotation_id' => $request->quotation_id,
                'delivery_note_number' => $request->delivery_note_number,
                'delivery_date' => $request->delivery_date,
                'recipient_name' => $request->recipient_name,
                'recipient_address' => $request->recipient_address,
                'notes' => $request->notes,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            foreach ($request->items as $item) {
                DeliveryNoteItem::create([
                    'delivery_note_id' => $deliveryNote->delivery_note_id,
                    'product_id' => $item['product_id'],
                    'product_code' => $item['product_code'] ?? null,
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Delivery note created successfully',
                'data' => $deliveryNote->load('items', 'company', 'purchaseOrder', 'quotation'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create delivery note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified delivery note
     * GET /api/delivery-notes/{id}
     */
    public function show(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $deliveryNote = DeliveryNote::with([
            'company',
            'invoice.customer',
            'purchaseOrder.customer',
            'purchaseOrder.items.product',
            'quotation',
            'createdByUser',
            'issuedByUser',
            'items.product',
            'stockOuts',
            'purchaseOrder.activity_type',
            'quotation.activityType',
        ])
        ->where('company_id', $companyId) // ✅ guard cross-company
        ->find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery note retrieved successfully',
            'data' => $deliveryNote,
        ], 200);
    }

    /**
     * Update the specified delivery note
     * PUT /api/delivery-notes/{id}
     */
    public function update(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $deliveryNote = DeliveryNote::where('company_id', $companyId)->find($id); // ✅ guard

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found',
            ], 404);
        }

        if ($deliveryNote->status === 'issued') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update issued delivery note',
            ], 409);
        }

        if ($request->has('items') && is_string($request->items)) {
            $request->merge(['items' => json_decode($request->items, true)]);
        }

        $validator = Validator::make($request->all(), [
            'delivery_note_number' => 'sometimes|required|string|max:100|unique:delivery_notes,delivery_note_number,' . $id . ',delivery_note_id',
            'delivery_date' => 'sometimes|required|date',
            'recipient_name' => 'sometimes|required|string|max:255',
            'recipient_address' => 'nullable|string',
            'notes' => 'nullable|string',
            'items' => 'sometimes|required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.product_code' => 'nullable|string|max:100',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:50',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $deliveryNote->update($request->only([
                'delivery_note_number',
                'delivery_date',
                'recipient_name',
                'recipient_address',
                'notes',
            ]));

            if ($request->has('items')) {
                DeliveryNoteItem::where('delivery_note_id', $deliveryNote->delivery_note_id)->delete();

                foreach ($request->items as $item) {
                    DeliveryNoteItem::create([
                        'delivery_note_id' => $deliveryNote->delivery_note_id,
                        'product_id' => $item['product_id'],
                        'product_code' => $item['product_code'] ?? null,
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Delivery note updated successfully',
                'data' => $deliveryNote->load('items', 'company', 'purchaseOrder', 'quotation'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update delivery note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified delivery note
     * DELETE /api/delivery-notes/{id}
     */
    public function destroy(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $deliveryNote = DeliveryNote::where('company_id', $companyId)->find($id); // ✅ guard

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found',
            ], 404);
        }

        if ($deliveryNote->status === 'issued') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete issued delivery note',
            ], 409);
        }

        if ($deliveryNote->stockOuts()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete delivery note with stock out records',
            ], 409);
        }

        try {
            $deliveryNote->delete();

            return response()->json([
                'success' => true,
                'message' => 'Delivery note deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete delivery note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

   private function autoCreateStockOut($deliveryNote, $outDate)
{
    $processedBy = Auth::id();
    $customerId  = $deliveryNote->invoice?->customer_id ?? null;
    $stockOutRecords = [];

    foreach ($deliveryNote->items as $dnItem) {

        $batches = \App\Models\StockBatch::where('product_id', $dnItem->product_id)
            ->where('company_id', $deliveryNote->company_id)
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
                ) AS available_qty
            ')
            ->having('available_qty', '>', 0)
            ->orderBy('expiry_date', 'asc')
            ->orderBy('batch_id', 'asc')
            ->get();

        $totalAvailable = $batches->sum('available_qty');

        if ($totalAvailable < $dnItem->quantity) {
            throw new \Exception(
                "Stok tidak mencukupi untuk produk {$dnItem->product->product_name}. " .
                "Tersedia: {$totalAvailable}, Dibutuhkan: {$dnItem->quantity}"
            );
        }

        $remaining = $dnItem->quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;

            $used = min($remaining, $batch->available_qty);

            $sellingPrice = 0;
            if ($deliveryNote->invoice) {
                $invoiceItem = $deliveryNote->invoice->items()
                    ->where('product_id', $dnItem->product_id)
                    ->first();
                if ($invoiceItem) {
                    $sellingPrice = is_numeric($invoiceItem->unit_price)
                        ? (float) $invoiceItem->unit_price
                        : (float) ($dnItem->product->selling_price ?? 0);
                }
            }

            if ($sellingPrice == 0) {
                $sellingPrice = (float) ($dnItem->product->selling_price ?? 0);
            }

            $purchasePrice = is_numeric($batch->purchase_price)
                ? (float) $batch->purchase_price
                : 0;

            $stockOut = \App\Models\StockOut::create([
                'company_id'         => (int) $deliveryNote->company_id,
                'product_id'         => (int) $dnItem->product_id,
                'batch_id'           => (int) $batch->batch_id,
                'customer_id'        => $customerId ? (int) $customerId : null,
                'delivery_note_id'   => (int) $deliveryNote->delivery_note_id,
                'transaction_type'   => 'sale',
                'quantity'           => (int) $used,
                'selling_price'      => (string) $sellingPrice,
                'out_date'           => $outDate,
                'notes'              => "Auto Stock OUT dari DN {$deliveryNote->delivery_note_number}",
                'processed_by'       => $processedBy ? (int) $processedBy : null,
                'receiving_condition'=> 'good',
            ]);

           \App\Models\StockMovement::create([
    'product_id'     => (int) $dnItem->product_id,
    'batch_id'       => (int) $batch->batch_id,
    'movement_type'  => 'OUT',
    'quantity'       => (int) $used,
    'unit_cost'      => (string) $purchasePrice,
    'reference_id'   => (int) $stockOut->stock_out_id,
    'reference_type' => 'stock_out',
    'movement_date'  => $outDate,
    'notes'          => "Auto Stock OUT dari DN {$deliveryNote->delivery_note_number}",
    'created_by'     => $processedBy ? (int) $processedBy : null,
    'created_at'     => now(),
    'updated_at'     => now(),
]);


            $stockOutRecords[] = $stockOut;
            $remaining -= $used;

            try {
                $date = \Carbon\Carbon::parse($outDate);
                \App\Models\EndingStock::updateEndingStock($batch->batch_id, $date->year, $date->month);
            } catch (\Exception $e) {
                Log::warning("Failed to update ending stock: " . $e->getMessage());
            }
        }

        if (count($stockOutRecords) > 0) {
            try {
                $dnItem->update([
                    'stock_out_id' => (int) $stockOutRecords[0]->stock_out_id
                ]);
            } catch (\Exception $e) {
                Log::info("DN item stock_out_id update skipped: " . $e->getMessage());
            }
        }
    }

    return $stockOutRecords;
}


    /**
     * ✅ FIXED: Issue method dengan proper error handling
     * POST /api/delivery-notes/{id}/issue
     */
   /**
 * Issue Delivery Note — dengan upload signature image
 * POST /api/delivery-notes/{id}/issue
 */
public function issue(Request $request, $id)
{
    $companyId = $this->getCompanyId($request);

    $deliveryNote = DeliveryNote::with(['items.product', 'company', 'invoice.items'])
        ->where('company_id', $companyId)
        ->find($id);

    if (!$deliveryNote) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery note tidak ditemukan',
        ], 404);
    }

    if ($deliveryNote->status !== 'draft') {
        return response()->json([
            'success' => false,
            'message' => 'Hanya delivery note dengan status draft yang bisa di-issue',
        ], 422);
    }

    // ✅ Validasi — signature_image required, pola sama dengan PO Controller
    $validator = Validator::make($request->all(), [
        'signed_name'     => 'required|string|max:100',
        'signed_position' => 'required|string|max:100',
        'signed_city'     => 'required|string|max:100',
        'signature_image' => 'required|image|mimes:png,jpg,jpeg|max:2048',
        'out_date'        => 'nullable|date',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors(),
        ], 422);
    }

    DB::beginTransaction();

    try {
        // ✅ Upload signature ke storage/public/signatures/delivery_notes/
        $signaturePath = null;
        if ($request->hasFile('signature_image')) {
            $file          = $request->file('signature_image');
            $filename      = 'DN_' . $deliveryNote->delivery_note_id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $signaturePath = $file->storeAs('signatures/delivery_notes', $filename, 'public');
        }

        // ✅ Hapus file signature lama kalau ada
        if ($deliveryNote->signature_image_path && Storage::disk('public')->exists($deliveryNote->signature_image_path)) {
            Storage::disk('public')->delete($deliveryNote->signature_image_path);
        }

        $deliveryNote->update([
            'status'               => 'issued',
            'signed_name'          => $request->signed_name,
            'signed_position'      => $request->signed_position,
            'signed_city'          => $request->signed_city,
            'signature_image_path' => $signaturePath, // ✅ TAMBAH
            'signed_at'            => now(),
            'issued_by'            => Auth::id(),
            'issued_at'            => now(),
        ]);

        $outDate        = $request->out_date ?? now()->format('Y-m-d');
        $stockOutRecords = [];

        try {
            $stockOutRecords = $this->autoCreateStockOut($deliveryNote, $outDate);
        } catch (\Exception $e) {
            DB::rollBack();

            // ✅ Hapus file yang sudah terupload kalau stock out gagal
            if ($signaturePath && Storage::disk('public')->exists($signaturePath)) {
                Storage::disk('public')->delete($signaturePath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat Stock OUT: ' . $e->getMessage(),
            ], 400);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Delivery note berhasil di-issue dan ' . count($stockOutRecords) . ' Stock OUT berhasil dibuat',
            'data'    => [
                'delivery_note'      => $deliveryNote->fresh(['items', 'company']),
                'stock_out_records'  => $stockOutRecords,
            ],
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();

        // ✅ Cleanup file kalau ada exception tak terduga
        if (!empty($signaturePath) && Storage::disk('public')->exists($signaturePath)) {
            Storage::disk('public')->delete($signaturePath);
        }

        Log::error('Issue Delivery Note Error: ' . $e->getMessage(), [
            'delivery_note_id' => $id,
            'trace'            => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Gagal meng-issue delivery note',
            'error'   => $e->getMessage(),
        ], 500);
    }
}


    /**
     * Create delivery note from approved purchase order
     * POST /api/delivery-notes/from-po/{po_id}
     */
    public function createFromPurchaseOrder(Request $request, $poId)
    {
        $companyId = $this->getCompanyId($request); // ✅

        try {
            $purchaseOrder = PurchaseOrder::with([
                'company',
                'customer',
                'items.product',
                'quotation',
                'invoices'
            ])
            ->where('company_id', $companyId) // ✅ guard
            ->find($poId);

            if (!$purchaseOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase order not found',
                ], 404);
            }

            $deliveryNote = $this->deliveryNoteService->createFromPurchaseOrder($purchaseOrder);

            return response()->json([
                'success' => true,
                'message' => 'Delivery note created successfully from purchase order',
                'data' => $deliveryNote,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Generate PDF for delivery note
     * GET /api/delivery-notes/{id}/pdf
     */
    public function generatePDF(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $deliveryNote = DeliveryNote::with([
            'company',
            'invoice.customer',
            'purchaseOrder.customer',
            'purchaseOrder',
            'quotation',
            'items.product',
            'createdByUser',
            'issuedByUser'
        ])
        ->where('company_id', $companyId) // ✅ guard
        ->find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found',
            ], 404);
        }

        if ($deliveryNote->status === 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot generate PDF for draft delivery note. Please issue the delivery note first.',
            ], 422);
        }

        try {
            $customer = null;
            if ($deliveryNote->invoice && $deliveryNote->invoice->customer) {
                $customer = $deliveryNote->invoice->customer;
            } elseif ($deliveryNote->purchaseOrder && $deliveryNote->purchaseOrder->customer) {
                $customer = $deliveryNote->purchaseOrder->customer;
            }

            $data = [
                'deliveryNote' => $deliveryNote,
                'customer' => $customer,
            ];

            $pdf = Pdf::loadView('pdf.delivery-note', $data);
            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'sans-serif'
            ]);

            $filename = 'Surat-Jalan-' . $this->sanitizeFilename($deliveryNote->delivery_note_number) . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate PDF',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get delivery note by number
     * GET /api/delivery-notes/by-number/{delivery_note_number}
     */
    public function getByNumber(Request $request, $deliveryNoteNumber)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $deliveryNote = DeliveryNote::where('delivery_note_number', $deliveryNoteNumber)
            ->where('company_id', $companyId) // ✅ guard
            ->with([
                'company',
                'invoice.customer',
                'purchaseOrder.customer',
                'quotation',
                'items.product',
                'stockOuts'
            ])
            ->first();

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery note retrieved successfully',
            'data' => $deliveryNote,
        ], 200);
    }

    /**
     * Auto-generate delivery note number
     * GET /api/delivery-notes/generate-number
     */
    public function generateNumber(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅ ambil dari session

        try {
            $company = Company::find($companyId);

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found',
                ], 404);
            }

            $deliveryNoteNumber = $this->deliveryNoteService->generateDeliveryNoteNumber($company->company_code);

            return response()->json([
                'success' => true,
                'message' => 'Delivery note number generated successfully',
                'data' => [
                    'delivery_note_number' => $deliveryNoteNumber,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate delivery note number',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get delivery notes by PO
     * GET /api/delivery-notes/by-po/{po_id}
     */
    public function getByPurchaseOrder(Request $request, $poId)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $deliveryNotes = DeliveryNote::where('po_id', $poId)
            ->where('company_id', $companyId) // ✅ guard
            ->with([
                'company',
                'items.product',
                'issuedByUser',
                'stockOuts'
            ])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Delivery notes retrieved successfully',
            'data' => $deliveryNotes,
        ], 200);
    }

    /**
     * Get delivery notes by quotation
     * GET /api/delivery-notes/by-quotation/{quotation_id}
     */
    public function getByQuotation(Request $request, $quotationId)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $deliveryNotes = DeliveryNote::where('quotation_id', $quotationId)
            ->where('company_id', $companyId) // ✅ guard
            ->with([
                'company',
                'purchaseOrder',
                'items.product',
                'issuedByUser',
                'stockOuts'
            ])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Delivery notes retrieved successfully',
            'data' => $deliveryNotes,
        ], 200);
    }

    /**
     * Sanitize filename for safe file naming
     */
    private function sanitizeFilename($filename)
    {
        $filename = str_replace(['/', '\\', '?', '%', '*', ':', '|', '"', '<', '>'], '-', $filename);
        $filename = preg_replace('/\s+/', '-', $filename);
        $filename = preg_replace('/[^A-Za-z0-9\-]/', '', $filename);
        return $filename;
    }
}
