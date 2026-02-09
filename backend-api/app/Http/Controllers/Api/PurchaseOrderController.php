<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
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

  public function index(Request $request)
{
    $query = PurchaseOrder::with([
        'company', 
        'customer', 
        'quotation.activityType',
        'activity_type',
        'createdByUser', 
        'issuedByUser',
        'items',
        'deliveryNotes'
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

    // Filter activity_type_id
    if ($request->has('activity_type_id')) {
        $query->where('activity_type_id', $request->activity_type_id);
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

    // ✅ FIXED: Default sorting jadi po_id DESC (terbaru paling atas)
    $sortBy = $request->get('sort_by', 'po_id');
    $sortOrder = $request->get('sort_order', 'desc'); 
    $query->orderBy($sortBy, $sortOrder);

    // ✅ ADDED: Fallback sort jika user pakai kolom lain, tetap urutkan po_id desc sebagai secondary
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
        // ✅ FIX: Decode items jika dikirim sebagai JSON string (karena FormData)
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

            $po->load(['company', 'customer', 'activity_type', 'createdByUser', 'items']);

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

    public function show($id)
    {
        $po = PurchaseOrder::with([
            'company', 
            'customer', 
            'quotation.activityType', // ✅ FIXED: camelCase
            'activity_type',
            'quotation.items', 
            'items', 
            'invoices',
            'deliveryNotes',          // ✅ ADDED
            'createdByUser',
            'issuedByUser'
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

            $po->load(['company', 'customer', 'activity_type', 'createdByUser', 'items']);

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

    public function updateStatus(Request $request, $id)
    {
        $po = PurchaseOrder::find($id);

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

        try {
            $po->update(['status' => $request->status]);

            return response()->json([
                'success' => true,
                'message' => 'Purchase order status updated successfully',
                'data' => $po
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generatePdf($id)
    {
        $po = PurchaseOrder::with([
            'company', 
            'customer',
            'quotation.activityType', // ✅ FIXED: camelCase
            'items',
            'issuedByUser'
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
