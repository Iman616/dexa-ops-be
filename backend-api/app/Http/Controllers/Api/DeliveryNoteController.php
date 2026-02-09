<?php


namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

class DeliveryNoteController extends Controller
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
        $query = DeliveryNote::with([
            'company',
            'invoice.customer',
            'purchaseOrder.customer',
            'quotation',
            'createdByUser',
            'issuedByUser',
            'items.product',
              'purchaseOrder.activity_type',  // ✅ TAMBAH
        'quotation.activityType', 
        ]);

        // Filter by company
        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by PO
        if ($request->has('po_id')) {
            $query->where('po_id', $request->po_id);
        }

        // Filter by quotation
        if ($request->has('quotation_id')) {
            $query->where('quotation_id', $request->quotation_id);
        }

        // Filter by invoice
        if ($request->has('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        // Search
        if ($request->has('search')) {
        $search = $request->search;
        $query->where(function ($q) use ($search) {
            $q->where('delivery_note_number', 'like', "%{$search}%")
              ->orWhere('recipient_company', 'like', "%{$search}%")
              ->orWhere('supplier_name', 'like', "%{$search}%");
        });
    }

    if ($request->has('status')) {
        $query->where('status', $request->status);
    }

    if ($request->has('po_id')) {
        $query->where('po_id', $request->po_id);
    }

    if ($request->has('quotation_id')) {
        $query->where('quotation_id', $request->quotation_id);
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
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
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

            // Create delivery note
            $deliveryNote = DeliveryNote::create([
                'company_id' => $request->company_id,
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

            // Create items
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
    public function show($id)
    {
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
             'purchaseOrder.activity_type',  // ✅ TAMBAH
        'quotation.activityType',
        ])->find($id);

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
        $deliveryNote = DeliveryNote::find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found',
            ], 404);
        }

        // Prevent update if already issued
        if ($deliveryNote->status === 'issued') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update issued delivery note',
            ], 409);
        }

        // Decode items if sent as JSON string (from FormData)
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

            // Update delivery note
            $deliveryNote->update($request->only([
                'delivery_note_number',
                'delivery_date',
                'recipient_name',
                'recipient_address',
                'notes',
            ]));

            // Update items if provided
            if ($request->has('items')) {
                // Delete existing items
                DeliveryNoteItem::where('delivery_note_id', $deliveryNote->delivery_note_id)->delete();

                // Create new items
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
    public function destroy($id)
    {
        $deliveryNote = DeliveryNote::find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found',
            ], 404);
        }

        // Prevent delete if issued
        if ($deliveryNote->status === 'issued') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete issued delivery note',
            ], 409);
        }

        // Prevent delete if has stock out
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

    /**
     * Issue delivery note (mark as issued with signature)
     * POST /api/delivery-notes/{id}/issue
     */
    public function issue(Request $request, $id)
    {
        $deliveryNote = DeliveryNote::with('company', 'items')->find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found',
            ], 404);
        }

        if ($deliveryNote->status === 'issued') {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note already issued',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'signed_name' => 'required|string|max:100',
            'signed_position' => 'required|string|max:100',
            'signed_city' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $deliveryNote->issue($request->only([
                'signed_name',
                'signed_position',
                'signed_city',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Delivery note issued successfully',
                'data' => $deliveryNote->fresh(['issuedByUser', 'company', 'items']),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create delivery note from approved purchase order
     * POST /api/delivery-notes/from-po/{po_id}
     */
    public function createFromPurchaseOrder($poId)
    {
        try {
            $purchaseOrder = PurchaseOrder::with([
                'company',
                'customer',
                'items.product',
                'quotation',
                'invoices'
            ])->find($poId);

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
    public function generatePDF($id)
    {
        $deliveryNote = DeliveryNote::with([
            'company',
            'invoice.customer',
            'purchaseOrder.customer',
            'purchaseOrder',
            'quotation',
            'items.product',
            'createdByUser',
            'issuedByUser'
        ])->find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found',
            ], 404);
        }

        // Cannot generate PDF for draft
        if ($deliveryNote->status === 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot generate PDF for draft delivery note. Please issue the delivery note first.',
            ], 422);
        }

        try {
            // Determine customer from various sources
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

            // Load view with data
            $pdf = Pdf::loadView('pdf.delivery-note', $data);

            // Set paper size and orientation
            $pdf->setPaper('A4', 'portrait');

            // Set options
            $pdf->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'sans-serif'
            ]);

            // Generate filename
            $filename = 'Surat-Jalan-' . $this->sanitizeFilename($deliveryNote->delivery_note_number) . '.pdf';

            // Return PDF for download
            return $pdf->download($filename);

            // Or for preview in browser:
            // return $pdf->stream($filename);
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
    public function getByNumber($deliveryNoteNumber)
    {
        $deliveryNote = DeliveryNote::where('delivery_note_number', $deliveryNoteNumber)
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
     * GET /api/delivery-notes/generate-number/{company_id}
     */
    public function generateNumber($companyId)
    {
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
    public function getByPurchaseOrder($poId)
    {
        $deliveryNotes = DeliveryNote::where('po_id', $poId)
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
    public function getByQuotation($quotationId)
    {
        $deliveryNotes = DeliveryNote::where('quotation_id', $quotationId)
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
