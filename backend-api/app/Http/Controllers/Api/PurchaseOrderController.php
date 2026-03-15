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


class PurchaseOrderController extends BaseController
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
            'data' => $purchaseOrders,
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
            'work_package' => 'nullable|string|max:255',
            'activity_name' => 'nullable|string|max:255',
            'items.*.brand' => 'nullable|string|max:100',
            'use_ppn' => 'nullable|boolean',
            'items.*.product_type' => 'nullable|in:prekursor,bbo,ppi,teknis,glassware,alat_lab',
            'items.*.supplier_id' => 'nullable|exists:suppliers,supplier_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // ✅ Validate stock pakai companyId dari session
        $stockValidation = $this->validateStockForItems($request->items, $companyId);

        DB::beginTransaction();
        try {
            $poFilePath = null;
            if ($request->hasFile('po_file')) {
                $file = $request->file('po_file');
                $filename = 'PO_' . time() . '_' . $file->getClientOriginalName();
                $poFilePath = $file->storeAs('purchase_orders', $filename, 'public');
            }

            $totalAmount = $this->calculateTotalAmount($request->input('items'));

            $po = PurchaseOrder::create([
                'company_id' => $companyId,  // ✅ Dari session
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
                'work_package' => $request->work_package,
                'activity_name' => $request->activity_name,
                'use_ppn' => $request->boolean('use_ppn', true),
                'created_by' => Auth::id(),
            ]);

            foreach ($request->input('items') as $item) {
                $productId = $this->resolveProductId($item);

                PurchaseOrderItem::create([
                    'po_id' => $po->po_id,
                    'product_id' => $productId,
                    'product_name' => $item['product_name'],
                    'specification' => $item['specification'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? 'pcs',
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $po->load(['company', 'customer', 'activityType', 'createdByUser', 'items']);

            return response()->json([
                'success' => true,
                'message' => 'Purchase order created successfully',
                'data' => $po,
                'stock_validation' => $stockValidation,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create purchase order',
                'error' => $e->getMessage(),
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
            'data' => $po,
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
            'work_package' => 'nullable|string|max:255',
            'activity_name' => 'nullable|string|max:255',
            'items.*.brand' => 'nullable|string|max:100',
            'items.*.product_code' => 'nullable|string|max:100',
            'items.*.category' => 'nullable|string|max:100',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.product_type' => 'nullable|in:prekursor,bbo,ppi,teknis,glassware,alat_lab',
            'items.*.supplier_id' => 'nullable|exists:suppliers,supplier_id',


        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
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

            $totalAmount = $this->calculateTotalAmount($request->input('items'));

            $po->update([
                'customer_id' => $request->customer_id,
                'quotation_id' => $request->quotation_id,
                'activity_type_id' => $request->activity_type_id,
                'po_number' => $request->po_number,
                'po_date' => $request->po_date,
                'valid_until' => $request->valid_until,
                'notes' => $request->notes,
                'total_amount' => $totalAmount,
                'work_package' => $request->work_package,  // ← pindah ke sini (sebelumnya salah di dalam loop)
                'activity_name' => $request->activity_name, // ← pindah ke sini
            ]);

            PurchaseOrderItem::where('po_id', $po->po_id)->delete();

            foreach ($request->input('items') as $item) {
                $productId = $this->resolveProductId($item); // ← auto-create jika manual

                PurchaseOrderItem::create([
                    'po_id' => $po->po_id,
                    'product_id' => $productId,         // ← selalu ada
                    'product_name' => $item['product_name'],
                    'specification' => $item['specification'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? 'pcs',
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'notes' => $item['notes'] ?? null,
                    // ← work_package & activity_name DIHAPUS dari sini, bukan kolom di items
                ]);
            }

            DB::commit();

            $po->load(['company', 'customer', 'activityType', 'createdByUser', 'items']);

            return response()->json([
                'success' => true,
                'message' => 'Purchase order updated successfully',
                'data' => $po,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update purchase order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* ================================================================
     * DESTROY
     * ================================================================ */
    public function destroy(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);
        $po = PurchaseOrder::where('company_id', $companyId)->find($id);

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
            'status' => 'required|in:draft,issued,sent,approved,processing,completed,cancelled',
            'payment_type' => 'required_if:status,approved|in:dp,full',
            'use_ppn' => 'nullable|boolean',  // ✅ TAMBAH
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $oldStatus = $po->status;
        $newStatus = $request->status;
        $paymentType = $request->input('payment_type', 'full');
        $forceApprove = $request->boolean('force_approve', false);

        // ✅ Ambil use_ppn — prioritas: dari request, fallback dari PO yg tersimpan, default true
        $usePpn = $request->has('use_ppn')
            ? $request->boolean('use_ppn')
            : (bool) ($po->use_ppn ?? true);

        if ($newStatus === 'approved' && $oldStatus !== 'approved') {
            if (!$forceApprove) {
                $validation = $po->validateStockAvailability();
                if (!$validation['is_valid']) {
                    return response()->json([
                        'success' => false,
                        'error_code' => 'INSUFFICIENT_STOCK',
                        'message' => 'Stok tidak mencukupi untuk approve PO ini',
                        'stock_validation' => $validation,
                    ], 422);
                }
            }
        }

        DB::beginTransaction();
        try {
            // ✅ Simpan use_ppn ke PO juga
            $po->update([
                'status' => $newStatus,
                'use_ppn' => $usePpn,
            ]);

            if ($newStatus === 'approved' && $oldStatus !== 'approved') {
                $this->handlePOApproval($po, $paymentType, $usePpn);  // ✅ pass $usePpn
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

            return response()->json(['success' => true, 'message' => 'Purchase order status updated successfully', 'data' => $po], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to update status', 'error' => $e->getMessage()], 500);
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
                'success' => false,
                'message' => 'Only draft or sent purchase orders can be issued',
                'current_status' => $po->status,
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
                'errors' => $validator->errors(),
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
                'data' => $po->load(['issuedByUser', 'company', 'customer', 'items']),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to issue purchase order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // app/Http/Controllers/Api/PurchaseOrderController.php

    /**
     * POST /api/purchase-orders/{id}/process
     *
     * Jika stok cukup  → langsung proses PO
     * Jika stok kurang → buat SupplierPO + (opsional) ProformaInvoice ke supplier
     */
    public function process(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $po = PurchaseOrder::with(['items.product', 'customer', 'company'])
            ->where('company_id', $companyId)
            ->find($id);

        if (!$po) {
            return response()->json(['success' => false, 'message' => 'PO tidak ditemukan'], 404);
        }

        if (!in_array($po->status, ['issued', 'approved', 'processing'])) {
            return response()->json([
                'success' => false,
                'message' => 'PO harus berstatus issued, approved, atau processing untuk diproses',
            ], 422);
        }

        $procurement = $po->getStockShortageForProcurement();

        $targetStatus = ($po->status === 'processing') ? 'completed' : 'processing';

        // ─── STOK CUKUP: langsung update status ──────────────────────────────
        if (!$procurement['needs_procurement']) {
            $po->update(['status' => $targetStatus]);

            return response()->json([
                'success' => true,
                'message' => $targetStatus === 'completed'
                    ? 'PO selesai, stok tersedia'
                    : 'PO berhasil diproses, stok tersedia',
                'needs_procurement' => false,
                'data' => $po->fresh(),
            ]);
        }

        // ─── STOK KURANG ─────────────────────────────────────────────────────
        if (!$request->boolean('auto_create_supplier_po')) {
            return response()->json([
                'success' => false,
                'needs_procurement' => true,
                'message' => 'Stok tidak mencukupi.',
                'shortage' => $procurement['issues'],
                'target_status' => $targetStatus,  // ← kirim ke FE
            ], 409);
        }

        // ─── AUTO-CREATE SupplierPO + ProformaInvoice ─────────────────────
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,supplier_id',
            'expected_delivery' => 'nullable|date',
            'create_proforma' => 'nullable|boolean',   // opsional buat PI
            'payment_type' => 'nullable|in:full,dp', // full payment atau dp
            'dp_percentage' => 'nullable|numeric|min:1|max:100',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $company = $po->company;
            $supplierId = $request->supplier_id;

            // ── 1. Buat SupplierPO ─────────────────────────────────────────
            $supplierPoNumber = SupplierPurchaseOrder::generatePoNumber();

            // Hitung total dari item yang shortage saja
            $shortageProductIds = collect($procurement['issues'])->pluck('product_id');
            $shortageItems = $po->items->whereIn('product_id', $shortageProductIds->toArray());

            $subtotal = $shortageItems->sum(fn($i) => $i->quantity * $i->unit_price);
            $taxAmount = $subtotal * 0.11;
            $totalAmount = $subtotal + $taxAmount;

            $supplierPo = SupplierPurchaseOrder::create([
                'po_number' => $supplierPoNumber,
                'po_id' => $po->po_id,       // link ke PO customer
                'supplier_id' => $supplierId,
                'company_id' => $companyId,
                'po_date' => now()->toDateString(),
                'expected_delivery_date' => $request->expected_delivery ?? now()->addDays(7)->toDateString(),
                'status' => 'draft',
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'notes' => $request->notes ?? "Pengadaan untuk PO {$po->po_number}",
                'created_by' => Auth::id(),
            ]);

            // ── 2. Buat SupplierPO Items dari shortage ─────────────────────
            foreach ($shortageItems as $poItem) {
                $issue = collect($procurement['issues'])->firstWhere('product_id', $poItem->product_id);
                $qtyNeeded = $issue['shortage']; // hanya beli kekurangannya

                $supplierPo->items()->create([
                    'product_id' => $poItem->product_id,
                    'product_name' => $poItem->product_name,
                    'quantity' => $qtyNeeded,
                    'unit' => $poItem->unit,
                    'unit_price' => $poItem->unit_price,
                    'subtotal' => $qtyNeeded * $poItem->unit_price,
                ]);
            }

            // ── 3. (Opsional) Buat ProformaInvoice ke supplier ────────────
            $proforma = null;
            if ($request->boolean('create_proforma', true)) {
                $paymentType = $request->payment_type ?? 'full';
                $dpPercentage = $request->dp_percentage ?? 100;
                $proformaAmount = $paymentType === 'dp'
                    ? $totalAmount * ($dpPercentage / 100)
                    : $totalAmount;

                $proformaNumber = $this->generateProformaNumber($companyId);

                $proforma = ProformaInvoice::create([
                    'company_id' => $companyId,
                    'customer_id' => null,   // ini untuk supplier, bukan customer
                    'po_id' => $po->po_id,
                    'proforma_number' => $proformaNumber,
                    'proforma_date' => now()->toDateString(),
                    'valid_until' => now()->addDays(14)->toDateString(),
                    'subtotal' => $subtotal,
                    'tax_percentage' => 11,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => 0,
                    'total_amount' => $proformaAmount,
                    'payment_terms' => $paymentType === 'dp'
                        ? "DP {$dpPercentage}% sebelum pengiriman"
                        : 'Pembayaran penuh sebelum pengiriman',
                    'notes' => "PI untuk Supplier PO {$supplierPoNumber}",
                    'status' => 'draft',
                    'created_by' => Auth::id(),
                ]);

                // Items PI dari shortage items
                foreach ($shortageItems as $poItem) {
                    $issue = collect($procurement['issues'])->firstWhere('product_id', $poItem->product_id);
                    $qtyNeeded = $issue['shortage'];

                    $proforma->items()->create([
                        'product_id' => $poItem->product_id,
                        'product_name' => $poItem->product_name,
                        'quantity' => $qtyNeeded,
                        'unit' => $poItem->unit,
                        'unit_price' => $poItem->unit_price,
                        'subtotal' => $qtyNeeded * $poItem->unit_price,
                    ]);
                }
            }

            $po->update(['status' => $targetStatus]);
            DB::commit();

            return response()->json([
                'success' => true,
                'needs_procurement' => true,
                'message' => 'PO diproses. SupplierPO' . ($proforma ? ' & Proforma Invoice' : '') . ' berhasil dibuat.',
                'data' => [
                    'purchase_order' => $po->fresh(),
                    'supplier_po' => $supplierPo->load(['items', 'supplier']),
                    'proforma' => $proforma?->load(['items']),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses PO',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ── Helper generate proforma number ───────────────────────────────────
    private function generateProformaNumber(int $companyId): string
    {
        $company = DB::table('companies')->where('company_id', $companyId)->first();
        $code = $company->company_code ?? 'UNK';
        $year = date('Y');
        $month = date('m');

        $last = ProformaInvoice::where('company_id', $companyId)
            ->whereYear('proforma_date', $year)
            ->whereMonth('proforma_date', $month)
            ->orderByDesc('proforma_id')
            ->lockForUpdate()
            ->first();

        $num = $last ? (int) substr($last->proforma_number, -5) + 1 : 1;
        return "PI/{$code}/{$year}/{$month}/" . str_pad($num, 5, '0', STR_PAD_LEFT);
    }


    /* ================================================================
     * CHECK STOCK
     * ================================================================ */
    public function checkStock(Request $request)
    {
        // ✅ company_id dari session — tidak wajib dari request
        $companyId = $this->getCompanyId($request);

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,product_id',
            'items.*.product_name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validation = $this->validateStockForItems($request->items, $companyId);

        return response()->json([
            'success' => true,
            'message' => 'Stock validation completed',
            'data' => $validation,
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
            'items.product',
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
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* ================================================================
     * UPLOAD / DOWNLOAD PO CUSTOMER FILE
     * ================================================================ */
    public function uploadPoCustomerFile(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);
        $po = PurchaseOrder::where('company_id', $companyId)->find($id);

        if (!$po) {
            return response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
        }

        if ($po->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only approved purchase orders can upload customer PO file',
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
                'errors' => $validator->errors(),
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
        $po = PurchaseOrder::where('company_id', $companyId)->find($id);

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
            $subtotal = floatval($item['quantity']) * floatval($item['unit_price']);
            $discountAmount = $subtotal * (floatval($item['discount_percent'] ?? 0) / 100);
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
                'is_valid' => true,
                'total_items' => count($items),
                'insufficient_items' => 0,
                'issues' => [],
                'note' => 'Semua item adalah item custom (tanpa product)',
            ];
        }

        $stock = StockHelper::getAvailableByProducts(
            $productItems->pluck('product_id')->unique(),
            $companyId
        );
        $issues = [];
        $allSufficient = true;

        foreach ($items as $item) {
            if (empty($item['product_id']))
                continue;

            $available = (float) $stock->get($item['product_id'], 0);
            $required = (float) $item['quantity'];

            if ($available < $required) {
                $allSufficient = false;
                $issues[] = [
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'required' => $required,
                    'available' => $available,
                    'shortage' => $required - $available,
                    'status' => $available > 0 ? 'low' : 'out_of_stock',
                    'recommendation' => 'Buat PO Supplier untuk menambah stok',
                ];
            }
        }

        return [
            'is_valid' => $allSufficient,
            'total_items' => count($items),
            'insufficient_items' => count($issues),
            'issues' => $issues,
            'warning' => !$allSufficient ? 'Beberapa produk stok tidak mencukupi.' : null,
        ];
    }

    // ✅ SESUDAH — hanya generate PI + Surat Jalan, TIDAK generate Invoice
    private function handlePOApproval(PurchaseOrder $po, string $paymentType = 'full', bool $usePpn = true): void
    {
        if ($po->is_tender)
            $this->createTenderProject($po);
        $this->autoGenerateProformaInvoice($po, $paymentType, $usePpn);
        $this->createDeliveryNote($po);
    }


  private function autoGenerateProformaInvoice(
    PurchaseOrder $po,
    string $paymentType = 'full',
    bool $usePpn = true
): ?ProformaInvoice {
    $sudahAdaPI = ProformaInvoice::where('po_id', $po->po_id)
        ->whereNotIn('status', ['cancelled', 'rejected'])
        ->exists();

    if ($sudahAdaPI || $po->items->isEmpty())
        return null;

    try {
        // ── 1. Subtotal net (setelah diskon per item) ────────────────────
        $subtotal = $po->items->reduce(function (float $carry, $item): float {
            $gross    = (float) $item->quantity * (float) $item->unit_price;
            $discount = $gross * ((float) ($item->discount_percent ?? 0) / 100);
            return $carry + ($gross - $discount);
        }, 0.0);

        // ── 2. Diskon header ─────────────────────────────────────────────
        $grossTotal        = $po->items->sum(fn($i) => (float)$i->quantity * (float)$i->unit_price);
        $totalItemDiscount = $grossTotal - $subtotal;

        $headerDiscount    = (float) ($po->discount_amount    ?? 0);
        $headerDiscountPct = (float) ($po->discount_percentage ?? 0);

        if ($headerDiscount > 0) {
            // Prioritas 1: nominal dari header PO
            $discountAmount     = round($headerDiscount, 2);
            $discountPercentage = $grossTotal > 0
                ? round($discountAmount / $grossTotal * 100, 2)
                : 0;
        } elseif ($headerDiscountPct > 0) {
            // Prioritas 2: persen dari header PO
            $discountAmount     = round($subtotal * $headerDiscountPct / 100, 2);
            $discountPercentage = $headerDiscountPct;
        } else {
            // Fallback: akumulasi diskon per item
            $discountAmount     = round($totalItemDiscount, 2);
            $discountPercentage = $grossTotal > 0
                ? round($discountAmount / $grossTotal * 100, 2)
                : 0;
        }

        // ── 3. Base untuk PPN (subtotal setelah diskon header) ───────────
        $baseForTax = round($subtotal - $discountAmount, 2);

        // ── 4. Hitung PPN — DPP Nilai Lain ──────────────────────────────
        // Rumus: DPP  = Subtotal × tax/(tax+1)   → contoh 11%: × 11/12
        //        PPN  = DPP × tax%
        //        Total = Subtotal + PPN           ← bukan DPP + PPN
        if ($usePpn) {
            $taxPercentage = 11; // TODO: ganti dengan config/setting jika rate berubah
            $dpp           = round($baseForTax * ($taxPercentage / ($taxPercentage + 1)), 2);
            $taxAmount     = round($dpp * ($taxPercentage / 100), 2);
        } else {
            $taxPercentage = 0;
            $dpp           = $baseForTax;
            $taxAmount     = 0;
        }

        // ── 5. Total ─────────────────────────────────────────────────────
        $totalAmount = round($baseForTax + $taxAmount, 2);
        $grandTotal  = $totalAmount;

        // ── 6. Generate nomor PI ─────────────────────────────────────────
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

        // ── 7. Notes ─────────────────────────────────────────────────────
        $paymentNote = $paymentType === 'dp'
            ? 'Pembayaran: Down Payment (DP). Invoice akan diterbitkan setelah pelunasan.'
            : 'Pembayaran: Full Payment.';

        $ppnNote = $usePpn
            ? " [PPN {$taxPercentage}% - DPP Nilai Lain]"
            : ' [Non-PPN]';

        // ── 8. Buat Proforma Invoice ─────────────────────────────────────
        $pi = ProformaInvoice::create([
            'company_id'          => $po->company_id,
            'customer_id'         => $po->customer_id,
            'po_id'               => $po->po_id,
            'proforma_number'     => $proformaNumber,
            'proforma_date'       => now()->format('Y-m-d'),
            'valid_until'         => now()->addDays(30)->format('Y-m-d'),
            'subtotal'            => $subtotal,          // gross net per item
            'tax_percentage'      => $taxPercentage,     // 0 jika non-PPN
            'tax_amount'          => $taxAmount,         // PPN = DPP × tax%
            'dpp_adjustment'      => $dpp,               // DPP Nilai Lain
            'discount_amount'     => $discountAmount,    // dari PO
            'discount_percentage' => $discountPercentage,// dari PO
            'total_amount'        => $totalAmount,       // base + PPN
            'use_ppn'             => $usePpn,
            'payment_terms'       => $paymentType === 'dp'
                ? 'DP terlebih dahulu, pelunasan menyusul'
                : 'Full Payment',
            'delivery_terms'      => 'FOB Destination',
            'status'              => 'draft',
            'notes'               => "Auto-generated dari PO {$po->po_number}. {$paymentNote}{$ppnNote}",
            'created_by'          => Auth::id(),
        ]);

        // ── 9. Buat Items PI ─────────────────────────────────────────────
        foreach ($po->items as $poItem) {
            $itemGross    = (float) $poItem->quantity * (float) $poItem->unit_price;
            $itemDiscount = $itemGross * ((float) ($poItem->discount_percent ?? 0) / 100);
            $itemSubtotal = round($itemGross - $itemDiscount, 2);

            ProformaInvoiceItem::create([
                'proforma_id'         => $pi->proforma_id,
                'product_id'          => $poItem->product_id,
                'product_name'        => $poItem->product_name,
                'product_description' => $poItem->specification ?? null,
                'product_code'        => $poItem->product?->product_code ?? null,
                'brand'               => $poItem->product?->brand ?? null,
                'quantity'            => $poItem->quantity,
                'unit'                => $poItem->unit,
                'unit_price'          => $poItem->unit_price,
                'discount_percent'    => $poItem->discount_percent ?? 0,
                'subtotal'            => $itemSubtotal, // net per item
                'notes'               => $poItem->notes ?? null,
            ]);
        }

        // ── 10. Log ──────────────────────────────────────────────────────
        \Illuminate\Support\Facades\Log::info(
            "Auto-generated PI {$pi->proforma_number} dari PO {$po->po_number} " .
            "[payment={$paymentType}] " .
            "[use_ppn=" . ($usePpn ? 'true' : 'false') . "] " .
            "[subtotal={$subtotal}] " .
            "[discount={$discountAmount} ({$discountPercentage}%)] " .
            "[base={$baseForTax}] " .
            "[dpp={$dpp}] " .
            "[tax={$taxAmount}] " .
            "[total={$totalAmount}]"
        );

        return $pi;

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error(
            "Gagal auto-generate PI untuk PO {$po->po_number}: " . $e->getMessage() .
            " | Line: " . $e->getLine()
        );
        return null;
    }
}


    // private function autoGenerateInvoice(
    //     PurchaseOrder $po,
    //     ProformaInvoice $pi,
    //     bool $usePpn = true   // ✅ TAMBAH parameter
    // ): void {
    //     $sudahAda = \App\Models\Invoice::where('po_id', $po->po_id)
    //         ->orWhere('proforma_invoice_id', $pi->proforma_id)
    //         ->exists();

    //     if ($sudahAda)
    //         return;

    //     try {
    //         $companyCode = $po->company?->company_code ?? 'XXX';
    //         $year = date('Y');
    //         $month = date('m');
    //         $last = \App\Models\Invoice::where('company_id', $po->company_id)
    //             ->whereYear('invoice_date', $year)
    //             ->whereMonth('invoice_date', $month)
    //             ->orderByDesc('invoice_id')
    //             ->lockForUpdate()
    //             ->first();
    //         $num = $last ? ((int) substr($last->invoice_number, -5) + 1) : 1;
    //         $invoiceNumber = "INV/{$companyCode}/{$year}/{$month}/" . str_pad($num, 5, '0', STR_PAD_LEFT);

    //         $invoice = \App\Models\Invoice::create([
    //             'company_id' => $po->company_id,
    //             'customer_id' => $po->customer_id,
    //             'po_id' => $po->po_id,
    //             'proforma_invoice_id' => $pi->proforma_id,
    //             'invoice_number' => $invoiceNumber,
    //             'invoice_date' => now()->format('Y-m-d'),
    //             'due_date' => now()->addDays(30)->format('Y-m-d'),
    //             'subtotal' => $pi->subtotal,
    //             'tax_percentage' => $pi->tax_percentage,
    //             'tax_amount' => $pi->tax_amount,
    //             'discount_amount' => $pi->discount_amount,
    //             'total_amount' => $pi->total_amount,
    //             'use_ppn' => $usePpn,   // ✅ SIMPAN
    //             'payment_status' => 'unpaid',
    //             'payment_terms' => 'Full Payment',
    //             'delivery_terms' => 'FOB Destination',
    //             'currency' => 'IDR',
    //             'notes' => "Auto-generated dari PO {$po->po_number} (Full Payment)",
    //             'created_by' => Auth::id(),
    //         ]);

    //         foreach ($pi->items as $piItem) {
    //             \App\Models\InvoiceItem::create([
    //                 'invoice_id' => $invoice->invoice_id,
    //                 'product_id' => $piItem->product_id,
    //                 'product_name' => $piItem->product_name,
    //                 'product_description' => $piItem->product_description,
    //                 'quantity' => $piItem->quantity,
    //                 'unit' => $piItem->unit,
    //                 'unit_price' => $piItem->unit_price,
    //                 'discount_percent' => $piItem->discount_percent ?? 0,
    //                 'notes' => $piItem->notes,
    //             ]);
    //         }

    //         $pi->update([
    //             'status' => 'converted',
    //             'converted_to_invoice_id' => $invoice->invoice_id,
    //             'converted_at' => now(),
    //         ]);

    //         \Illuminate\Support\Facades\Log::info(
    //             "Auto-generated Invoice {$invoice->invoice_number} dari PO {$po->po_number} [use_ppn=" . ($usePpn ? 'true' : 'false') . "]"
    //         );

    //     } catch (\Exception $e) {
    //         \Illuminate\Support\Facades\Log::error("Gagal auto-generate Invoice untuk PO {$po->po_number}: " . $e->getMessage());
    //     }
    // }

    private function createTenderProject(PurchaseOrder $po): void
    {
        if (TenderProjectDetail::where('po_id', $po->po_id)->exists())
            return;

        $contractEndDate = $po->valid_until
            ?? \Carbon\Carbon::parse($po->po_date)->addDays(30);

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

    private function createDeliveryNote(PurchaseOrder $po): void
    {
        if (DeliveryNote::where('po_id', $po->po_id)->exists())
            return;

        $companyCode = $po->company?->company_code ?? 'XXX';
        $year = date('Y');
        $sequence = DeliveryNote::whereYear('created_at', $year)->count() + 1;
        $dnNumber = "DN/{$companyCode}/{$year}/" . str_pad($sequence, 4, '0', STR_PAD_LEFT);

        $deliveryNote = DeliveryNote::create([
            'company_id' => $po->company_id,
            'po_id' => $po->po_id,
            'delivery_note_number' => $dnNumber,
            'delivery_date' => now(),
            'recipient_name' => $po->customer?->customer_name,
            'recipient_address' => $po->customer?->address,
            'recipient_phone' => $po->customer?->phone,
            'delivery_status' => 'pending',
            'notes' => 'Auto-created from PO approval',
            'created_by' => Auth::id(),
        ]);

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
        // ── Sudah ada product_id → langsung pakai
        if (!empty($item['product_id'])) {
            return (int) $item['product_id'];
        }
        if (!empty($item['product_name'])) {
            $productCode = !empty($item['product_code'])
                ? $item['product_code']
                : 'PO-' . strtoupper(\Illuminate\Support\Str::random(8));
            while (Product::where('product_code', $productCode)->exists()) {
                $productCode = 'PO-' . strtoupper(\Illuminate\Support\Str::random(8));
            }
            $productType = $item['product_type'] ?? null;
            $isPrecursor = in_array($productType, ['prekursor', 'bbo', 'ppi']);

            $product = Product::create([
                'product_code' => $productCode,
                'product_name' => $item['product_name'],
                'product_type' => $productType,
                'brand' => $item['brand'] ?? 'Unknown',
                'category' => $item['category'] ?? null,
                'unit' => $item['unit'] ?? 'pcs',
                'selling_price' => (int) ($item['unit_price'] ?? 0),
                'purchase_price' => 0,
                'supplier_id' => !empty($item['supplier_id']) ? (int) $item['supplier_id'] : null,
                'is_precursor' => $isPrecursor,
                'description' => 'Auto-created from Purchase Order (manual input)',
            ]);

            return $product->product_id;
        }

        return null;
    }
}
