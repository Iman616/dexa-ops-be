<?php

namespace App\Http\Controllers\Api;

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
use App\Services\StockHelper;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceItem;

class PurchaseOrderController extends BaseController  // ✅ Extend BaseController
{
    protected $pdfService;

    public function __construct(PurchaseOrderPdfService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    /* ================================================================
     * INDEX
     * ================================================================ */
    public function index(Request $request)
    {
        // ✅ Auto-resolve company dari session user

        $companyId = $this->getCompanyId($request);

        $query = PurchaseOrder::select([
            'po_id',
            'company_id',
            'customer_id',
            'quotation_id',
            'activity_type_id',
            'po_number',
            'po_date',
            'valid_until',
            'status',
            'total_amount',
            'created_at',
        ])
            ->with([
                'company:company_id,company_name,company_code',
                'customer:customer_id,customer_name',
                'quotation:quotation_id,quotation_number',
                'activityType:activity_type_id,type_name,type_code',
            ])
            ->withCount(['items', 'deliveryNotes', 'tenderDocuments'])
            ->where('company_id', $companyId); // ✅ Auto-filter

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('activity_type_id')) {
            $query->where('activity_type_id', $request->activity_type_id);
        }

        if ($request->filled('is_tender')) {
            $isTender = $request->boolean('is_tender');
            $query->whereHas(
                'activityType',
                fn($q) =>
                $isTender
                    ? $q->where('type_code', 'TENDER')
                    : $q->where('type_code', '!=', 'TENDER')
            );
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                    ->orWhereHas(
                        'customer',
                        fn($cq) =>
                        $cq->where('customer_name', 'like', "%{$search}%")
                    );
            });
        }

        if ($request->filled('date_from')) {
            $query->where('po_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('po_date', '<=', $request->date_to);
        }

        $sortBy    = $request->get('sort_by', 'po_id');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        if ($sortBy !== 'po_id') {
            $query->orderBy('po_id', 'desc');
        }

        $perPage       = $request->get('per_page', 15);
        $purchaseOrders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Purchase orders retrieved successfully',
            'data'    => $purchaseOrders,
        ], 200);
    }

    /* ================================================================
     * STORE
     * ================================================================ */
    public function store(Request $request)
    {
        // ✅ Auto-resolve — tidak perlu kirim company_id dari frontend
        $companyId = $this->getCompanyId($request);

        if ($request->has('items') && is_string($request->items)) {
            $request->merge(['items' => json_decode($request->items, true)]);
        }

        $validator = Validator::make($request->all(), [
            'customer_id'              => 'required|exists:customers,customer_id',
            'quotation_id'             => 'nullable|exists:quotations,quotation_id',
            'activity_type_id'         => 'nullable|exists:activity_types,activity_type_id',
            'po_number'                => 'required|string|max:100|unique:purchase_orders,po_number',
            'po_date'                  => 'required|date',
            'valid_until'              => 'required|string|max:200',
            'po_file'                  => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'status'                   => 'nullable|in:draft,issued,sent,approved,processing,completed,cancelled,expired',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'nullable|exists:products,product_id',
            'items.*.product_name'     => 'required|string|max:255',
            'items.*.specification'    => 'nullable|string',
            'items.*.quantity'         => 'required|numeric|min:0',
            'items.*.unit'             => 'required|string|max:50',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.notes'            => 'nullable|string',
            'work_package'          => 'nullable|string|max:255',
            'activity_name'         => 'nullable|string|max:255',
            'items.*.brand'         => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // ✅ Validate stock pakai companyId dari session
        $stockValidation = $this->validateStockForItems($request->items, $companyId);

        DB::beginTransaction();
        try {
            $poFilePath = null;
            if ($request->hasFile('po_file')) {
                $file       = $request->file('po_file');
                $filename   = 'PO_' . time() . '_' . $file->getClientOriginalName();
                $poFilePath = $file->storeAs('purchase_orders', $filename, 'public');
            }

            $totalAmount = $this->calculateTotalAmount($request->input('items'));

            $po = PurchaseOrder::create([
                'company_id'       => $companyId,  // ✅ Dari session
                'customer_id'      => $request->customer_id,
                'quotation_id'     => $request->quotation_id,
                'activity_type_id' => $request->activity_type_id,
                'po_number'        => $request->po_number,
                'po_date'          => $request->po_date,
                'valid_until'      => $request->valid_until,
                'po_file_path'     => $poFilePath,
                'status'           => $request->status ?? 'draft',
                'notes'            => $request->notes,
                'total_amount'     => $totalAmount,
                'work_package'     => $request->work_package,
                'activity_name'    => $request->activity_name,
                'created_by'       => Auth::id(),
            ]);

            foreach ($request->input('items') as $item) {
                $productId = $this->resolveProductId($item);

                PurchaseOrderItem::create([
                    'po_id'            => $po->po_id,
                    'product_id'       => $productId,
                    'product_name'     => $item['product_name'],
                    'specification'    => $item['specification'] ?? null,
                    'quantity'         => $item['quantity'],
                    'unit'             => $item['unit'] ?? 'pcs',
                    'unit_price'       => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'notes'            => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $po->load(['company', 'customer', 'activityType', 'createdByUser', 'items']);

            return response()->json([
                'success'          => true,
                'message'          => 'Purchase order created successfully',
                'data'             => $po,
                'stock_validation' => $stockValidation,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create purchase order',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* ================================================================
     * SHOW
     * ================================================================ */
    public function show(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $po = PurchaseOrder::with([
            'company',
            'customer',
            'activityType',
            'quotation.activityType',
            'items', // kalau ini perlu untuk detail
            'createdByUser',
            'issuedByUser',
        ])
            ->where('company_id', $companyId)
            ->find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Purchase order retrieved successfully',
            'data'    => $po,
        ], 200);
    }


    /* ================================================================
     * UPDATE
     * ================================================================ */
    public function update(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $po = PurchaseOrder::where('company_id', $companyId)->find($id);

        if (!$po) {
            return response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
        }

        if ($po->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only draft purchase orders can be updated'], 403);
        }

        if ($request->has('items') && is_string($request->items)) {
            $request->merge(['items' => json_decode($request->items, true)]);
        }

        $validator = Validator::make($request->all(), [
            'customer_id'              => 'required|exists:customers,customer_id',
            'quotation_id'             => 'nullable|exists:quotations,quotation_id',
            'activity_type_id'         => 'nullable|exists:activity_types,activity_type_id',
            'po_number'                => 'required|string|max:100|unique:purchase_orders,po_number,' . $id . ',po_id',
            'po_date'                  => 'required|date',
            'valid_until'              => 'required|string|max:200',
            'po_file'                  => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'nullable|exists:products,product_id',
            'items.*.product_name'     => 'required|string|max:255',
            'items.*.specification'    => 'nullable|string',
            'items.*.quantity'         => 'required|numeric|min:0',
            'items.*.unit'             => 'required|string|max:50',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.notes'            => 'nullable|string',
            'work_package'          => 'nullable|string|max:255',
            'activity_name'         => 'nullable|string|max:255',
            'items.*.brand'         => 'nullable|string|max:100',
            'items.*.product_code' => 'nullable|string|max:100',
            'items.*.category'     => 'nullable|string|max:100',
            'items.*.unit'         => 'nullable|string|max:50',


        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            if ($request->hasFile('po_file')) {
                if ($po->po_file_path && Storage::disk('public')->exists($po->po_file_path)) {
                    Storage::disk('public')->delete($po->po_file_path);
                }
                $file         = $request->file('po_file');
                $filename     = 'PO_' . time() . '_' . $file->getClientOriginalName();
                $po->po_file_path = $file->storeAs('purchase_orders', $filename, 'public');
            }

            $totalAmount = $this->calculateTotalAmount($request->input('items'));

            $po->update([
                'customer_id'      => $request->customer_id,
                'quotation_id'     => $request->quotation_id,
                'activity_type_id' => $request->activity_type_id,
                'po_number'        => $request->po_number,
                'po_date'          => $request->po_date,
                'valid_until'      => $request->valid_until,
                'notes'            => $request->notes,
                'total_amount'     => $totalAmount,
                'work_package'     => $request->work_package,  // ← pindah ke sini (sebelumnya salah di dalam loop)
                'activity_name'    => $request->activity_name, // ← pindah ke sini
            ]);

            PurchaseOrderItem::where('po_id', $po->po_id)->delete();

            foreach ($request->input('items') as $item) {
                $productId = $this->resolveProductId($item); // ← auto-create jika manual

                PurchaseOrderItem::create([
                    'po_id'            => $po->po_id,
                    'product_id'       => $productId,         // ← selalu ada
                    'product_name'     => $item['product_name'],
                    'specification'    => $item['specification'] ?? null,
                    'quantity'         => $item['quantity'],
                    'unit'             => $item['unit'] ?? 'pcs',
                    'unit_price'       => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'notes'            => $item['notes'] ?? null,
                    // ← work_package & activity_name DIHAPUS dari sini, bukan kolom di items
                ]);
            }

            DB::commit();

            $po->load(['company', 'customer', 'activityType', 'createdByUser', 'items']);

            return response()->json([
                'success' => true,
                'message' => 'Purchase order updated successfully',
                'data'    => $po,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update purchase order',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* ================================================================
     * DESTROY
     * ================================================================ */
    public function destroy(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);
        $po        = PurchaseOrder::where('company_id', $companyId)->find($id);

        if (!$po) {
            return response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
        }

        if ($po->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only draft purchase orders can be deleted'], 422);
        }

        if ($po->invoices()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete purchase order with existing invoices'], 409);
        }

        if ($po->tenderDocuments()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete purchase order with uploaded tender documents'], 409);
        }

        if ($po->bankGuarantees()->where('status', 'active')->exists()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete purchase order with active bank guarantees'], 409);
        }

        try {
            $po->delete();
            return response()->json(['success' => true, 'message' => 'Purchase order deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete purchase order', 'error' => $e->getMessage()], 500);
        }
    }

    /* ================================================================
     * UPDATE STATUS
     * ================================================================ */
public function updateStatus(Request $request, $id)
{
    $companyId = $this->getCompanyId($request);

    $po = PurchaseOrder::with([
        'quotation.activityType',
        'activityType',
        'company',
        'customer',
        'items',
    ])
        ->where('company_id', $companyId)
        ->find($id);

    if (!$po) {
        return response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
    }

    $validator = Validator::make($request->all(), [
        'status'       => 'required|in:draft,issued,sent,approved,processing,completed,cancelled',
        // ✅ Wajib diisi saat approve
        'payment_type' => 'required_if:status,approved|in:dp,full',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors(),
        ], 422);
    }

    $oldStatus    = $po->status;
    $newStatus    = $request->status;
    $paymentType  = $request->input('payment_type', 'full'); // 'dp' atau 'full'
    $forceApprove = $request->boolean('force_approve', false);

    // ✅ Validate stock saat approve
    if ($newStatus === 'approved' && $oldStatus !== 'approved') {
        if (!$forceApprove) {
            $validation = $po->validateStockAvailability();
            if (!$validation['is_valid']) {
                return response()->json([
                    'success'          => false,
                    'error_code'       => 'INSUFFICIENT_STOCK',
                    'message'          => 'Stok tidak mencukupi untuk approve PO ini',
                    'stock_validation' => $validation,
                ], 422);
            }
        }
    }

    DB::beginTransaction();
    try {
        $po->update(['status' => $newStatus]);

        if ($newStatus === 'approved' && $oldStatus !== 'approved') {
            $this->handlePOApproval($po, $paymentType);
        }

        DB::commit();

        $po->load([
            'activityType',
            'tenderProject',
            'bankGuarantees',
            'tenderDocuments',
            'deliveryNotes',
            'proformaInvoices',
            'invoices',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Purchase order status updated successfully',
            'data'    => $po,
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Failed to update status',
            'error'   => $e->getMessage(),
        ], 500);
    }
}
    /* ================================================================
     * ISSUE
     * ================================================================ */
    public function issue(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $po = PurchaseOrder::with(['company', 'customer', 'items'])
            ->where('company_id', $companyId)
            ->find($id);

        if (!$po) {
            return response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
        }

        if (!in_array($po->status, ['draft', 'sent'])) {
            return response()->json([
                'success'        => false,
                'message'        => 'Only draft or sent purchase orders can be issued',
                'current_status' => $po->status,
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'signed_name'     => 'required|string|max:100',
            'signed_position' => 'required|string|max:100',
            'signed_city'     => 'required|string|max:50',
            'signature_image' => 'required|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
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
                'data'    => $po->load(['issuedByUser', 'company', 'customer', 'items']),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to issue purchase order',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* ================================================================
     * CHECK STOCK
     * ================================================================ */
    public function checkStock(Request $request)
    {
        // ✅ company_id dari session — tidak wajib dari request
        $companyId = $this->getCompanyId($request);

        $validator = Validator::make($request->all(), [
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'nullable|exists:products,product_id',
            'items.*.product_name'     => 'required|string',
            'items.*.quantity'         => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validation = $this->validateStockForItems($request->items, $companyId);

        return response()->json([
            'success' => true,
            'message' => 'Stock validation completed',
            'data'    => $validation,
        ], 200);
    }

    /* ================================================================
     * GENERATE & DOWNLOAD PDF
     * ================================================================ */
    public function generatePdf(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $po = PurchaseOrder::with([
            'company',
            'customer',
            'quotation.activityType',
            'items',
            'issuedByUser',
            'tenderProject',
            'bankGuarantees',
        ])
            ->where('company_id', $companyId)
            ->find($id);

        if (!$po) {
            return response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
        }

        if ($po->status === 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot generate PDF for draft purchase order. Please issue the purchase order first.',
            ], 422);
        }

        try {
            $pdfPath      = $this->pdfService->generatePurchaseOrderPdf($po);
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
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* ================================================================
     * UPLOAD / DOWNLOAD PO CUSTOMER FILE
     * ================================================================ */
    public function uploadPoCustomerFile(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);
        $po        = PurchaseOrder::where('company_id', $companyId)->find($id);

        if (!$po) {
            return response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
        }

        if ($po->status !== 'approved') {
            return response()->json([
                'success'        => false,
                'message'        => 'Only approved purchase orders can upload customer PO file',
                'current_status' => $po->status,
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'po_customer_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $po->uploadPoCustomerFile($request->file('po_customer_file'));
            return response()->json(['success' => true, 'message' => 'PO customer file uploaded successfully', 'data' => $po], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to upload PO customer file', 'error' => $e->getMessage()], 500);
        }
    }

    public function downloadPoCustomerFile(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);
        $po        = PurchaseOrder::where('company_id', $companyId)->find($id);

        if (!$po) {
            return response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
        }

        if (!$po->has_po_customer_file) {
            return response()->json(['success' => false, 'message' => 'PO customer file not found'], 404);
        }

        return Storage::download($po->po_customer_file_path);
    }

    /* ================================================================
     * PRIVATE HELPERS
     * ================================================================ */

    /**
     * Hitung total amount dari array items
     */
    private function calculateTotalAmount(array $items): float
    {
        return collect($items)->sum(function ($item) {
            $subtotal        = floatval($item['quantity']) * floatval($item['unit_price']);
            $discountAmount  = $subtotal * (floatval($item['discount_percent'] ?? 0) / 100);
            return $subtotal - $discountAmount;
        });
    }

    private function validateStockForItems($items, $companyId): array
    {
        if (empty($items)) {
            return ['is_valid' => true, 'total_items' => 0, 'insufficient_items' => 0, 'issues' => []];
        }

        $productItems = collect($items)->filter(fn($i) => !empty($i['product_id']));

        if ($productItems->isEmpty()) {
            return [
                'is_valid'           => true,
                'total_items'        => count($items),
                'insufficient_items' => 0,
                'issues'             => [],
                'note'               => 'Semua item adalah item custom (tanpa product)',
            ];
        }

        $stock        = StockHelper::getAvailableByProducts(
            $productItems->pluck('product_id')->unique(),
            $companyId
        );
        $issues       = [];
        $allSufficient = true;

        foreach ($items as $item) {
            if (empty($item['product_id'])) continue;

            $available = (float) $stock->get($item['product_id'], 0);
            $required  = (float) $item['quantity'];

            if ($available < $required) {
                $allSufficient = false;
                $issues[] = [
                    'product_id'     => $item['product_id'],
                    'product_name'   => $item['product_name'],
                    'required'       => $required,
                    'available'      => $available,
                    'shortage'       => $required - $available,
                    'status'         => $available > 0 ? 'low' : 'out_of_stock',
                    'recommendation' => 'Buat PO Supplier untuk menambah stok',
                ];
            }
        }

        return [
            'is_valid'           => $allSufficient,
            'total_items'        => count($items),
            'insufficient_items' => count($issues),
            'issues'             => $issues,
            'warning'            => !$allSufficient ? 'Beberapa produk stok tidak mencukupi.' : null,
        ];
    }

   private function handlePOApproval(PurchaseOrder $po, string $paymentType = 'full'): void
{
    // Tender project (selalu dibuat jika tender)
    if ($po->is_tender) {
        $this->createTenderProject($po);
    }

    // ✅ Selalu generate PI
    $pi = $this->autoGenerateProformaInvoice($po, $paymentType);

    // ✅ Selalu generate Delivery Note
    $this->createDeliveryNote($po);

    // ✅ Hanya generate Invoice jika full payment
    if ($paymentType === 'full' && $pi) {
        $this->autoGenerateInvoice($po, $pi);
    }
}



   private function autoGenerateProformaInvoice(PurchaseOrder $po, string $paymentType = 'full'): ?ProformaInvoice
{
    $sudahAdaPI = ProformaInvoice::where('po_id', $po->po_id)
        ->whereNotIn('status', ['cancelled', 'rejected'])
        ->exists();

    if ($sudahAdaPI) {
        return null;
    }

    if ($po->items->isEmpty()) {
        return null;
    }

    try {
        $subtotal = $po->items->sum(function ($item) {
            $gross    = (float) $item->quantity * (float) $item->unit_price;
            $discount = $gross * ((float) ($item->discount_percent ?? 0) / 100);
            return $gross - $discount;
        });

        $taxPercentage = 11;
        $taxAmount     = $subtotal * ($taxPercentage / 100);
        $totalAmount   = $subtotal + $taxAmount;

        $companyCode = $po->company?->company_code ?? 'XXX';
        $year        = date('Y');
        $month       = date('m');

        $last = ProformaInvoice::where('company_id', $po->company_id)
            ->whereYear('proforma_date', $year)
            ->whereMonth('proforma_date', $month)
            ->orderByDesc('proforma_id')
            ->lockForUpdate()
            ->first();

        $num            = $last ? ((int) substr($last->proforma_number, -5) + 1) : 1;
        $proformaNumber = "PI/{$companyCode}/{$year}/{$month}/" . str_pad($num, 5, '0', STR_PAD_LEFT);

        // ✅ Tandai di notes apakah ini DP atau Full
        $paymentNote = $paymentType === 'dp'
            ? 'Pembayaran: Down Payment (DP). Invoice akan diterbitkan setelah pelunasan.'
            : 'Pembayaran: Full Payment.';

        $pi = ProformaInvoice::create([
            'company_id'      => $po->company_id,
            'customer_id'     => $po->customer_id,
            'po_id'           => $po->po_id,
            'proforma_number' => $proformaNumber,
            'proforma_date'   => now()->format('Y-m-d'),
            'valid_until'     => now()->addDays(30)->format('Y-m-d'),
            'subtotal'        => $subtotal,
            'tax_percentage'  => $taxPercentage,
            'tax_amount'      => $taxAmount,
            'discount_amount' => 0,
            'total_amount'    => $totalAmount,
            'payment_terms'   => $paymentType === 'dp' ? 'DP terlebih dahulu, pelunasan menyusul' : 'Full Payment',
            'delivery_terms'  => 'FOB Destination',
            'status'          => 'draft',
            'notes'           => "Auto-generated dari PO {$po->po_number}. {$paymentNote}",
            'created_by'      => Auth::id(),
        ]);

        foreach ($po->items as $poItem) {
            ProformaInvoiceItem::create([
                'proforma_id'         => $pi->proforma_id,
                'product_id'          => $poItem->product_id,
                'product_name'        => $poItem->product_name,
                'product_description' => $poItem->specification,
                'quantity'            => $poItem->quantity,
                'unit'                => $poItem->unit,
                'unit_price'          => $poItem->unit_price,
                'notes'               => $poItem->notes,
            ]);
        }

        \Illuminate\Support\Facades\Log::info(
            "Auto-generated PI {$pi->proforma_number} dari PO {$po->po_number} [{$paymentType}]"
        );

        return $pi;

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error(
            "Gagal auto-generate PI untuk PO {$po->po_number}: " . $e->getMessage()
        );
        return null;
    }
}

private function autoGenerateInvoice(PurchaseOrder $po, ProformaInvoice $pi): void
{
    // Guard: jangan double invoice
    $sudahAda = \App\Models\Invoice::where('po_id', $po->po_id)
        ->orWhere('proforma_invoice_id', $pi->proforma_id)
        ->exists();

    if ($sudahAda) {
        return;
    }

    try {
        $companyCode = $po->company?->company_code ?? 'XXX';
        $year        = date('Y');
        $month       = date('m');

        $last = \App\Models\Invoice::where('company_id', $po->company_id)
            ->whereYear('invoice_date', $year)
            ->whereMonth('invoice_date', $month)
            ->orderByDesc('invoice_id')
            ->lockForUpdate()
            ->first();

        $num           = $last ? ((int) substr($last->invoice_number, -5) + 1) : 1;
        $invoiceNumber = "INV/{$companyCode}/{$year}/{$month}/" . str_pad($num, 5, '0', STR_PAD_LEFT);

        $invoice = \App\Models\Invoice::create([
            'company_id'          => $po->company_id,
            'customer_id'         => $po->customer_id,
            'po_id'               => $po->po_id,
            'proforma_invoice_id' => $pi->proforma_id,
            'invoice_number'      => $invoiceNumber,
            'invoice_date'        => now()->format('Y-m-d'),
            'due_date'            => now()->addDays(30)->format('Y-m-d'),
            'subtotal'            => $pi->subtotal,
            'tax_percentage'      => $pi->tax_percentage,
            'tax_amount'          => $pi->tax_amount,
            'discount_amount'     => $pi->discount_amount,
            'total_amount'        => $pi->total_amount,
            'payment_status'      => 'unpaid',
            'payment_terms'       => 'Full Payment',
            'delivery_terms'      => 'FOB Destination',
            'currency'            => 'IDR',
            'notes'               => "Auto-generated dari PO {$po->po_number} (Full Payment)",
            'created_by'          => Auth::id(),
        ]);

        // Copy items dari PI ke Invoice
        foreach ($pi->items as $piItem) {
            \App\Models\InvoiceItem::create([
                'invoice_id'          => $invoice->invoice_id,
                'product_id'          => $piItem->product_id,
                'product_name'        => $piItem->product_name,
                'product_description' => $piItem->product_description,
                'quantity'            => $piItem->quantity,
                'unit'                => $piItem->unit,
                'unit_price'          => $piItem->unit_price,
                'notes'               => $piItem->notes,
            ]);
        }

        // ✅ Update PI status jadi converted
        $pi->update([
            'status'                  => 'converted',
            'converted_to_invoice_id' => $invoice->invoice_id,
            'converted_at'            => now(),
        ]);

        \Illuminate\Support\Facades\Log::info(
            "Auto-generated Invoice {$invoice->invoice_number} dari PO {$po->po_number} [Full Payment]"
        );

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error(
            "Gagal auto-generate Invoice untuk PO {$po->po_number}: " . $e->getMessage()
        );
        // Tidak throw — approval PO tetap sukses meskipun invoice gagal
    }
}

    private function createTenderProject(PurchaseOrder $po): void
    {
        if (TenderProjectDetail::where('po_id', $po->po_id)->exists()) return;

        $contractEndDate = $po->valid_until
            ?? \Carbon\Carbon::parse($po->po_date)->addDays(30);

        TenderProjectDetail::create([
            'po_id'               => $po->po_id,
            'contract_number'     => $po->po_number,
            'contract_start_date' => $po->po_date,
            'contract_end_date'   => $contractEndDate,
            'has_ba_uji_fungsi'   => false,
            'ba_uji_fungsi_date'  => null,
            'has_bahp'            => false,
            'bahp_date'           => null,
            'has_bast'            => false,
            'bast_date'           => null,
            'has_sp2d'            => false,
            'sp2d_date'           => null,
            'project_status'      => 'ongoing',
            'notes'               => 'Auto-created from PO approval',
            'created_by'          => Auth::id(),
        ]);
    }

    private function createDeliveryNote(PurchaseOrder $po): void
    {
        if (DeliveryNote::where('po_id', $po->po_id)->exists()) return;

        $companyCode = $po->company?->company_code ?? 'XXX';
        $year        = date('Y');
        $sequence    = DeliveryNote::whereYear('created_at', $year)->count() + 1;
        $dnNumber    = "DN/{$companyCode}/{$year}/" . str_pad($sequence, 4, '0', STR_PAD_LEFT);

        $deliveryNote = DeliveryNote::create([
            'company_id'            => $po->company_id,
            'po_id'                 => $po->po_id,
            'delivery_note_number'  => $dnNumber,
            'delivery_date'         => now(),
            'recipient_name'        => $po->customer?->customer_name,
            'recipient_address'     => $po->customer?->address,
            'recipient_phone'       => $po->customer?->phone,
            'delivery_status'       => 'pending',
            'notes'                 => 'Auto-created from PO approval',
            'created_by'            => Auth::id(),
        ]);

        foreach ($po->items as $poItem) {
            DeliveryNoteItem::create([
                'delivery_note_id' => $deliveryNote->delivery_note_id,
                'product_id'       => $poItem->product_id,
                'product_name'     => $poItem->product_name,
                'specification'    => $poItem->specification,
                'quantity'         => $poItem->quantity,
                'unit'             => $poItem->unit,
            ]);
        }
    }

    public function generateNumber()
    {
        return DB::transaction(function () {

            $year = date('Y');

            $last = PurchaseOrder::whereYear('created_at', $year)
                ->lockForUpdate()
                ->orderBy('po_id', 'desc')
                ->first();

            $nextNumber = 1;

            if ($last && $last->po_number) {
                preg_match('/PO-\d{4}-(\d+)/', $last->po_number, $matches);

                if (isset($matches[1])) {
                    $nextNumber = intval($matches[1]) + 1;
                }
            }

            $poNumber = 'PO-' . $year . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

            return response()->json([
                'success' => true,
                'po_number' => $poNumber
            ]);
        });
    }


    private function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $filename);
        $filename = preg_replace('/\s+/', '_', $filename);
        return preg_replace('/[^A-Za-z0-9\-_]/', '', $filename);
    }

    private function resolveProductId(array $item): ?int
    {
        if (!empty($item['product_id'])) {
            return (int) $item['product_id'];
        }

        if (!empty($item['product_name'])) {
            $product = Product::create([
                'product_code'  => !empty($item['product_code'])
                    ? $item['product_code']
                    : 'MNL-' . strtoupper(uniqid()),
                'product_name'  => $item['product_name'],
                'brand'         => $item['brand'] ?? '-',
                'category'      => $item['category'] ?? null,
                'unit'          => $item['unit'] ?? 'pcs',
                'selling_price' => $item['unit_price'] ?? 0,
                'purchase_price' => 0,
                'supplier_id'   => null,
                'is_precursor'  => false,
            ]);
            return $product->product_id;
        }

        return null;
    }
}
