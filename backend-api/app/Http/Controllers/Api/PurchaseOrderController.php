<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\TenderProjectDetail;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Services\PurchaseOrderPdfService;

class PurchaseOrderController extends Controller
{
    protected $pdfService;

    public function __construct(PurchaseOrderPdfService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    /**
     * ✅ UPDATED: Added tender relationships to eager loading
     */
    public function index(Request $request)
    {
        $query = PurchaseOrder::with([
            'company', 
            'customer', 
            'quotation.activityType',
            'activityType', // ✅ Changed from activity_type
            'createdByUser', 
            'issuedByUser',
            'items',
            'deliveryNotes',
            // ✅ NEW: Tender relationships
            'tenderProject',
            'bankGuarantees',
            'tenderDocuments'
        ]);

        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('activity_type_id')) {
            $query->where('activity_type_id', $request->activity_type_id);
        }

        // ✅ NEW: Filter by tender type
        if ($request->has('is_tender')) {
            $isTender = $request->boolean('is_tender');
            if ($isTender) {
                $query->whereHas('activityType', function($q) {
                    $q->where('type_code', 'TENDER');
                });
            } else {
                $query->whereHas('activityType', function($q) {
                    $q->where('type_code', '!=', 'TENDER');
                });
            }
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function($cq) use ($search) {
                      $cq->where('customer_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('date_from')) {
            $query->where('po_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('po_date', '<=', $request->date_to);
        }

        $sortBy = $request->get('sort_by', 'po_id');
        $sortOrder = $request->get('sort_order', 'desc'); 
        $query->orderBy($sortBy, $sortOrder);

        if ($sortBy !== 'po_id') {
            $query->orderBy('po_id', 'desc');
        }

        $perPage = $request->get('per_page', 15);
        $purchaseOrders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Purchase orders retrieved successfully',
            'data' => $purchaseOrders
        ], 200);
    }

    public function store(Request $request)
    {
        if ($request->has('items') && is_string($request->items)) {
            $request->merge([
                'items' => json_decode($request->items, true)
            ]);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
            'customer_id' => 'required|exists:customers,customer_id',
            'quotation_id' => 'nullable|exists:quotations,quotation_id',
            'activity_type_id' => 'nullable|exists:activity_types,activity_type_id',
            'po_number' => 'required|string|max:100|unique:purchase_orders,po_number',
            'po_date' => 'required|date',
            'valid_until' => 'required|string|max:200',
            'po_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'status' => 'nullable|in:draft,issued,sent,approved,processing,completed,cancelled,expired',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,product_id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.specification' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit' => 'required|string|max:50',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $poFilePath = null;
            if ($request->hasFile('po_file')) {
                $file = $request->file('po_file');
                $filename = 'PO_' . time() . '_' . $file->getClientOriginalName();
                $poFilePath = $file->storeAs('purchase_orders', $filename, 'public');
            }

            $totalAmount = 0;
            foreach ($request->input('items') as $item) {
                $quantity = floatval($item['quantity']);
                $unitPrice = floatval($item['unit_price']);
                $discountPercent = floatval($item['discount_percent'] ?? 0);
                
                $subtotal = $quantity * $unitPrice;
                $discountAmount = $subtotal * ($discountPercent / 100);
                $total = $subtotal - $discountAmount;
                $totalAmount += $total;
            }

            $po = PurchaseOrder::create([
                'company_id' => $request->company_id,
                'customer_id' => $request->customer_id,
                'quotation_id' => $request->quotation_id,
                'activity_type_id' => $request->activity_type_id,
                'po_number' => $request->po_number,
                'po_date' => $request->po_date,
                'valid_until' => $request->valid_until,
                'po_file_path' => $poFilePath,
                'status' => $request->status ?? 'draft',
                'notes' => $request->notes,
                'total_amount' => $totalAmount,
                'created_by' => Auth::id(),
            ]);

            foreach ($request->input('items') as $item) {
                $discountPercent = $item['discount_percent'] ?? 0;
                
                PurchaseOrderItem::create([
                    'po_id' => $po->po_id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'specification' => $item['specification'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $discountPercent,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $po->load(['company', 'customer', 'activityType', 'createdByUser', 'items']);

            return response()->json([
                'success' => true,
                'message' => 'Purchase order created successfully',
                'data' => $po
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ UPDATED: Added tender relationships to eager loading
     */
    public function show($id)
    {
        $po = PurchaseOrder::with([
            'company', 
            'customer', 
            'quotation.activityType',
            'activityType',
            'quotation.items', 
            'items', 
            'invoices',
            'deliveryNotes',
            'createdByUser',
            'issuedByUser',
            // ✅ Tender relationships
            'tenderProject',
            'bankGuarantees',
            'tenderDocuments'
        ])->find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Purchase order retrieved successfully',
            'data' => $po
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $po = PurchaseOrder::find($id);

        if (!$po) {
            return response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
        }

        if ($po->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only draft purchase orders can be updated'], 403);
        }

        if ($request->has('items') && is_string($request->items)) {
            $request->merge([
                'items' => json_decode($request->items, true)
            ]);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
            'customer_id' => 'required|exists:customers,customer_id',
            'quotation_id' => 'nullable|exists:quotations,quotation_id',
            'activity_type_id' => 'nullable|exists:activity_types,activity_type_id',
            'po_number' => 'required|string|max:100|unique:purchase_orders,po_number,' . $id . ',po_id',
            'po_date' => 'required|date',
            'valid_until' => 'required|string|max:200',
            'po_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,product_id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.specification' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit' => 'required|string|max:50',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            if ($request->hasFile('po_file')) {
                if ($po->po_file_path && Storage::disk('public')->exists($po->po_file_path)) {
                    Storage::disk('public')->delete($po->po_file_path);
                }
                $file = $request->file('po_file');
                $filename = 'PO_' . time() . '_' . $file->getClientOriginalName();
                $po->po_file_path = $file->storeAs('purchase_orders', $filename, 'public');
            }

            $totalAmount = 0;
            foreach ($request->input('items') as $item) {
                $quantity = floatval($item['quantity']);
                $unitPrice = floatval($item['unit_price']);
                $discountPercent = floatval($item['discount_percent'] ?? 0);
                
                $subtotal = $quantity * $unitPrice;
                $discountAmount = $subtotal * ($discountPercent / 100);
                $total = $subtotal - $discountAmount;
                $totalAmount += $total;
            }

            $po->update([
                'company_id' => $request->company_id,
                'customer_id' => $request->customer_id,
                'quotation_id' => $request->quotation_id,
                'activity_type_id' => $request->activity_type_id,
                'po_number' => $request->po_number,
                'po_date' => $request->po_date,
                'valid_until' => $request->valid_until,
                'notes' => $request->notes,
                'total_amount' => $totalAmount,
            ]);

            PurchaseOrderItem::where('po_id', $po->po_id)->delete();

            foreach ($request->input('items') as $item) {
                $discountPercent = $item['discount_percent'] ?? 0;
                
                PurchaseOrderItem::create([
                    'po_id' => $po->po_id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'specification' => $item['specification'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $discountPercent,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $po->load(['company', 'customer', 'activityType', 'createdByUser', 'items']);

            return response()->json([
                'success' => true,
                'message' => 'Purchase order updated successfully',
                'data' => $po
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $po = PurchaseOrder::find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        if ($po->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft purchase orders can be deleted'
            ], 422);
        }

        if ($po->invoices()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete purchase order with existing invoices'
            ], 409);
        }

        if ($po->tenderDocuments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete purchase order with uploaded tender documents'
            ], 409);
        }

        if ($po->bankGuarantees()->where('status', 'active')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete purchase order with active bank guarantees'
            ], 409);
        }

        try {
            $po->delete();

            return response()->json([
                'success' => true,
                'message' => 'Purchase order deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function issue(Request $request, $id)
    {
        $po = PurchaseOrder::with(['company', 'customer', 'items'])->find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found' 
            ], 404);
        }

        if (!in_array($po->status, ['draft', 'sent'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft or sent purchase orders can be issued',
                'current_status' => $po->status
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'signed_name' => 'required|string|max:100',
            'signed_position' => 'required|string|max:100',
            'signed_city' => 'required|string|max:50',
            'signature_image' => 'required|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $po->issue(
                $request->signed_name,
                $request->signed_position,
                $request->signed_city,
                $request->file('signature_image'),
                Auth::id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Purchase order issued successfully',
                'data' => $po->load(['issuedByUser', 'company', 'customer', 'items'])
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to issue purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ COMPLETELY REWRITTEN: Auto-create tender project & delivery note
     */
    public function updateStatus(Request $request, $id)
    {
        $po = PurchaseOrder::with(['quotation.activityType', 'activityType', 'company', 'customer', 'items'])->find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,issued,sent,approved,processing,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $po->status;
            $newStatus = $request->status;
            
            $po->update(['status' => $newStatus]);

            // ✅ CRITICAL: Auto-create tender project & delivery note saat approved
            if ($newStatus === 'approved' && $oldStatus !== 'approved') {
                $this->handlePOApproval($po);
            }

            DB::commit();

            // Reload relationships
            $po->load([
                'activityType',
                'tenderProject',
                'bankGuarantees',
                'tenderDocuments',
                'deliveryNotes'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Purchase order status updated successfully',
                'data' => $po
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NEW: Handle PO approval logic
     * Auto-create tender project for TENDER type
     * Auto-create delivery note for ALL types
     */
    private function handlePOApproval(PurchaseOrder $po)
    {
        // ✅ Check if this is tender type
        $isTender = $po->is_tender; // Use accessor

        // ✅ For TENDER: Create tender project
        if ($isTender) {
            $this->createTenderProject($po);
        }

        // ✅ For ALL: Create delivery note
        $this->createDeliveryNote($po);
    }

    /**
     * ✅ NEW: Create tender project
     */
    private function createTenderProject(PurchaseOrder $po)
    {
        // Check if already exists
        $existing = TenderProjectDetail::where('po_id', $po->po_id)->first();
        
        if ($existing) {
            return; // Already created
        }

        $contractEndDate = $po->valid_until;

        // Fallback: If null, use start_date + 30 days
        if (!$contractEndDate) {
            $contractEndDate = \Carbon\Carbon::parse($po->po_date)->addDays(30);
        }

        // Create tender project
        TenderProjectDetail::create([
            'po_id' => $po->po_id,
            'contract_number' => $po->po_number,
            'contract_start_date' => $po->po_date,
            'contract_end_date' => $contractEndDate,
            'has_ba_uji_fungsi' => false,
            'ba_uji_fungsi_date' => null,
            'has_bahp' => false,
            'bahp_date' => null,
            'has_bast' => false,
            'bast_date' => null,
            'has_sp2d' => false,
            'sp2d_date' => null,
            'project_status' => 'ongoing',
            'notes' => 'Auto-created from PO approval',
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * ✅ NEW: Create delivery note
     */
    private function createDeliveryNote(PurchaseOrder $po)
    {
        // Check if already exists
        $existing = DeliveryNote::where('po_id', $po->po_id)->first();
        
        if ($existing) {
            return; // Already created
        }

        // Generate delivery note number
        $companyCode = $po->company ? $po->company->company_code : 'XXX';
        $year = date('Y');
        $sequence = DeliveryNote::whereYear('created_at', $year)->count() + 1;
        $dnNumber = "DN/{$companyCode}/{$year}/" . str_pad($sequence, 4, '0', STR_PAD_LEFT);

        // Create delivery note
        $deliveryNote = DeliveryNote::create([
            'company_id' => $po->company_id,
            'po_id' => $po->po_id,
            'delivery_note_number' => $dnNumber,
            'delivery_date' => now(),
            'recipient_name' => $po->customer ? $po->customer->customer_name : null,
            'recipient_address' => $po->customer ? $po->customer->address : null,
            'recipient_phone' => $po->customer ? $po->customer->phone : null,
            'delivery_status' => 'pending',
            'notes' => 'Auto-created from PO approval',
            'created_by' => Auth::id(),
        ]);

        // Create delivery note items from PO items
        foreach ($po->items as $poItem) {
            DeliveryNoteItem::create([
                'delivery_note_id' => $deliveryNote->delivery_note_id,
                'product_id' => $poItem->product_id,
                'product_name' => $poItem->product_name,
                'specification' => $poItem->specification,
                'quantity' => $poItem->quantity,
                'unit' => $poItem->unit,
            ]);
        }
    }

    public function generatePdf($id)
    {
        $po = PurchaseOrder::with([
            'company', 
            'customer',
            'quotation.activityType',
            'items',
            'issuedByUser',
            'tenderProject',
            'bankGuarantees'
        ])->find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        if ($po->status === 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot generate PDF for draft purchase order. Please issue the purchase order first.'
            ], 422);
        }

        try {
            $pdfPath = $this->pdfService->generatePurchaseOrderPdf($po);
            $absolutePath = Storage::disk('local')->path($pdfPath);
            
            if (!file_exists($absolutePath)) {
                throw new \Exception("PDF file not found at: {$absolutePath}");
            }

            $filename = $this->sanitizeFilename($po->po_number);

            return response()->download(
                $absolutePath,
                'PO_' . $filename . '.pdf'
            )->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadPoCustomerFile(Request $request, $id)
    {
        $po = PurchaseOrder::find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        if ($po->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only approved purchase orders can upload customer PO file',
                'current_status' => $po->status
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'po_customer_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $po->uploadPoCustomerFile($request->file('po_customer_file'));

            return response()->json([
                'success' => true,
                'message' => 'PO customer file uploaded successfully',
                'data' => $po
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload PO customer file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadPoCustomerFile($id)
    {
        $po = PurchaseOrder::find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        if (!$po->has_po_customer_file) {
            return response()->json([
                'success' => false,
                'message' => 'PO customer file not found'
            ], 404);
        }

        return Storage::download($po->po_customer_file_path);
    }

    private function sanitizeFilename($filename)
    {
        $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $filename);
        $filename = preg_replace('/\s+/', '_', $filename);
        $filename = preg_replace('/[^A-Za-z0-9\-_]/', '', $filename);
        return $filename;
    }
}