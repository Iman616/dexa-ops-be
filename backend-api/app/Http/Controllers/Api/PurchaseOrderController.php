<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use App\Models\Invoice;
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
            'quotation', 
            'createdByUser', 
            'issuedByUser',
            'items'
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

        $sortBy = $request->get('sort_by', 'po_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

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
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|exists:companies,company_id',
        'customer_id' => 'required|exists:customers,customer_id',
        'quotation_id' => 'nullable|exists:quotations,quotation_id',
        'po_number' => 'required|string|max:100|unique:purchase_orders,po_number',
        'po_date' => 'required|date',
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
        // Handle file upload
        $poFilePath = null;
        if ($request->hasFile('po_file')) {
            $file = $request->file('po_file');
            $filename = 'PO_' . time() . '_' . $file->getClientOriginalName();
            $poFilePath = $file->storeAs('purchase_orders', $filename, 'public');
        }

        // HITUNG TOTAL AMOUNT DARI ITEMS
        $totalAmount = 0;
        foreach ($request->items as $item) {
            $subtotal = $item['quantity'] * $item['unit_price'];
            $discountAmount = $subtotal * (($item['discount_percent'] ?? 0) / 100);
            $total = $subtotal - $discountAmount;
            $totalAmount += $total;
        }

        // Create PO dengan total_amount
        $po = PurchaseOrder::create([
            'company_id' => $request->company_id,
            'customer_id' => $request->customer_id,
            'quotation_id' => $request->quotation_id,
            'po_number' => $request->po_number,
            'po_date' => $request->po_date,
            'po_file_path' => $poFilePath,
            'status' => $request->status ?? 'draft',
            'notes' => $request->notes,
            'total_amount' => $totalAmount, // SIMPAN TOTAL
            'created_by' => Auth::id(),
        ]);

        // Create items
        foreach ($request->items as $item) {
            $subtotal = $item['quantity'] * $item['unit_price'];
            $discountPercent = $item['discount_percent'] ?? 0;
            $discountAmount = $subtotal * ($discountPercent / 100);
            $total = $subtotal - $discountAmount;

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

        // Load relationships
        $po->load(['company', 'customer', 'quotation', 'createdByUser', 'items']);

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
            'quotation.items', 
            'items', 
            'invoices',
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
        return response()->json([
            'success' => false,
            'message' => 'Purchase order not found'
        ], 404);
    }

    // Validasi: hanya draft yang bisa diupdate
    if ($po->status !== 'draft') {
        return response()->json([
            'success' => false,
            'message' => 'Only draft purchase orders can be updated'
        ], 403);
    }

    $validator = Validator::make($request->all(), [
        'company_id' => 'required|exists:companies,company_id',
        'customer_id' => 'required|exists:customers,customer_id',
        'quotation_id' => 'nullable|exists:quotations,quotation_id',
        'po_number' => 'required|string|max:100|unique:purchase_orders,po_number,' . $id . ',po_id',
        'po_date' => 'required|date',
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
        // Handle file upload
        if ($request->hasFile('po_file')) {
            // Delete old file
            if ($po->po_file_path && Storage::disk('public')->exists($po->po_file_path)) {
                Storage::disk('public')->delete($po->po_file_path);
            }

            $file = $request->file('po_file');
            $filename = 'PO_' . time() . '_' . $file->getClientOriginalName();
            $po->po_file_path = $file->storeAs('purchase_orders', $filename, 'public');
        }

        // HITUNG TOTAL AMOUNT DARI ITEMS
        $totalAmount = 0;
        foreach ($request->items as $item) {
            $subtotal = $item['quantity'] * $item['unit_price'];
            $discountAmount = $subtotal * (($item['discount_percent'] ?? 0) / 100);
            $total = $subtotal - $discountAmount;
            $totalAmount += $total;
        }

        // Update PO
        $po->update([
            'company_id' => $request->company_id,
            'customer_id' => $request->customer_id,
            'quotation_id' => $request->quotation_id,
            'po_number' => $request->po_number,
            'po_date' => $request->po_date,
            'notes' => $request->notes,
            'total_amount' => $totalAmount, // UPDATE TOTAL
        ]);

        // Delete existing items
        PurchaseOrderItem::where('po_id', $po->po_id)->delete();

        // Create new items
        foreach ($request->items as $item) {
            $subtotal = $item['quantity'] * $item['unit_price'];
            $discountPercent = $item['discount_percent'] ?? 0;
            $discountAmount = $subtotal * ($discountPercent / 100);
            $total = $subtotal - $discountAmount;

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

        // Load relationships
        $po->load(['company', 'customer', 'quotation', 'createdByUser', 'items']);

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

        // Hanya draft yang bisa dihapus
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

    /**
     * Issue purchase order dengan tanda tangan digital
     */
    public function issue(Request $request, $id)
    {
        $po = PurchaseOrder::with(['company', 'customer', 'items'])->find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        // Validasi hanya bisa issue jika status draft
        if ($po->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft purchase orders can be issued',
                'current_status' => $po->status
            ], 422);
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
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $po->issue(
                $request->signed_name,
                $request->signed_position,
                $request->signed_city,
                Auth::id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Purchase order issued successfully',
                'data' => $po->load(['issuedByUser'])
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
     * Update status PO
     */
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

    public function downloadPoFile($id)
    {
        $po = PurchaseOrder::find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        if (!$po->has_po_file) {
            return response()->json([
                'success' => false,
                'message' => 'PO file not found'
            ], 404);
        }

        return Storage::download($po->po_file_path);
    }

    /**
     * Generate PDF for Purchase Order
     */
    public function generatePdf($id)
    {
        $po = PurchaseOrder::with([
            'company', 
            'customer', 
            'items',
            'issuedByUser'
        ])->find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        // Validasi: tidak bisa generate PDF jika masih draft
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

            // Sanitize PO number
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

    /**
     * Sanitize filename
     */
    private function sanitizeFilename($filename)
    {
        $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $filename);
        $filename = preg_replace('/\s+/', '_', $filename);
        $filename = preg_replace('/[^A-Za-z0-9\-_]/', '', $filename);
        return $filename;
    }
}
