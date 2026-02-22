<?php

namespace App\Http\Controllers\Api;

use App\Models\Quotation;
use App\Models\Product;

use App\Models\QuotationItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class QuotationController extends BaseController  // ✅ Extend BaseController
{
    public function index(Request $request)
    {
        $companyId = $this->getCompanyId($request);

        $query = Quotation::with([
            'company',
            'customer',
            'activityType',
            'items',
            'createdByUser',
            'issuedByUser',
        ])
            ->where('company_id', $companyId);

        // ✅ FIXED: filled() bukan has() — skip jika value kosong
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('activity_type_id')) {
            $query->where('activity_type_id', $request->activity_type_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'like', "%{$search}%")
                    ->orWhereHas(
                        'customer',
                        fn($cq) =>
                        $cq->where('customer_name', 'like', "%{$search}%")
                    )
                    ->orWhereHas(
                        'activityType',
                        fn($atq) =>
                        $atq->where('type_name', 'like', "%{$search}%")
                    );
            });
        }

        $sortBy    = $request->get('sort_by', 'quotation_id');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        if ($sortBy !== 'quotation_id') {
            $query->orderBy('quotation_id', 'desc');
        }

       

        $perPage    = $request->get('per_page', 15);
        $quotations = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Quotations retrieved successfully',
            'data'    => $quotations,
        ], 200);
    }

    // GET /api/quotations/check-product?product_id=16&company_id=3
    public function checkProduct(Request $request)
    {
        $productId = $request->product_id;
        $companyId = $this->getCompanyId($request);

        // Cek stok tersedia
        $availableStock = \App\Models\StockBatch::where('product_id', $productId)
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('quantity_available', '>', 0)
            ->sum('quantity_available');

        $isReady = $availableStock > 0;

        // Ambil daftar supplier untuk produk ini
        $suppliers = \App\Models\ProductSupplier::with('supplier')
            ->where('product_id', $productId)
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->get()
            ->map(fn($ps) => [
                'supplier_id'   => $ps->supplier_id,
                'supplier_name' => $ps->supplier->supplier_name,
                'purchase_price' => $ps->purchase_price,
                'priority'      => $ps->priority,
            ]);

        return response()->json([
            'success'         => true,
            'is_ready'        => $isReady,
            'available_stock' => $availableStock,
            'suggested_status' => $isReady ? 'ready' : 'indent',
            'suppliers'       => $suppliers,
        ]);
    }

    // GET /api/suppliers?company_id=3 (untuk manual input, ambil semua supplier)
    // ini biasanya sudah ada



    public function store(Request $request)
    {
        // ✅ Auto-resolve — tidak perlu kirim company_id dari frontend
        $companyId = $this->getCompanyId($request);

        $validator = Validator::make($request->all(), [
            'customer_id'      => 'required|exists:customers,customer_id',
            'quotation_number' => 'required|string|max:100|unique:quotations,quotation_number',
            'activity_type_id' => 'required|exists:activity_types,activity_type_id',
            'quotation_date'   => 'required|date',
            'valid_until'      => 'required|date|after_or_equal:quotation_date',
            'status'           => 'nullable|in:draft,sent,issued,approved,rejected,expired',
            'notes'            => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.product_id'   => 'nullable|exists:products,product_id',
            'items.*.product_name' => 'required|string',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit_price'   => 'required|numeric|min:0',
            'items.*.notes'        => 'nullable|string',
            'items.*.item_status' => 'required|in:ready,indent',
            'items.*.brand'         => 'nullable|string|max:200',
            'items.*.category'      => 'nullable|string|max:100',
            'items.*.unit'          => 'nullable|string|max:50',
            'items.*.product_code' => 'nullable|string|max:100',
          
            // Rule: kalau indent, supplier_id wajib
            'items.*.supplier_id' => [
                'nullable',
                'exists:suppliers,supplier_id',
                function ($attribute, $value, $fail) use ($request) {
                    $index = explode('.', $attribute)[1];
                    $status = $request->items[$index]['item_status'] ?? 'ready';
                    if ($status === 'indent' && empty($value)) {
                        $fail('Supplier wajib dipilih jika item berstatus indent.');
                    }
                },
            ],
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

            $quotation = Quotation::create([
                'company_id'       => $companyId,  // ✅ Dari session user
                'customer_id'      => $request->customer_id,
                'quotation_number' => $request->quotation_number,
                'activity_type_id' => $request->activity_type_id,
                'quotation_date'   => $request->quotation_date,
                'valid_until'      => $request->valid_until,
                'status'           => $request->status ?? 'draft',
                'notes'            => $request->notes,
                'created_by'       => Auth::id(),
            ]);

            foreach ($request->items as $item) {
                $productId = $this->resolveProductId($item); // ← auto-create jika manual

                QuotationItem::create([
                    'quotation_id' => $quotation->quotation_id,
                    'product_id'   => $productId,             // ← selalu ada sekarang
                    'product_name' => $item['product_name'],
                    'quantity'     => $item['quantity'],
                    'unit_price'   => $item['unit_price'],
                    'item_status'  => $item['item_status'],
                    'supplier_id'  => $item['supplier_id'] ?? null,
                    'notes'        => $item['notes'] ?? null,
                ]);
            }

            $quotation->updateTotalAmount();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quotation created successfully',
                'data'    => $quotation->load(['items', 'activityType']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create quotation',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $quotation = Quotation::with([
            'company',
            'customer',
            'items.product',
            'items.supplier',   // ← TAMBAHKAN INI
            'activityType',
            'purchaseOrders',
            'createdByUser',
            'issuedByUser',
        ])
            ->where('company_id', $companyId)
            ->find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Quotation retrieved successfully',
            'data'    => $quotation,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $quotation = Quotation::where('company_id', $companyId)->find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found',
            ], 404);
        }

        if ($quotation->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft quotations can be updated',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'customer_id'      => 'required|exists:customers,customer_id',
            'quotation_number' => 'required|string|max:100|unique:quotations,quotation_number,' . $id . ',quotation_id',
            'activity_type_id' => 'required|exists:activity_types,activity_type_id',
            'quotation_date'   => 'required|date',
            'valid_until'      => 'required|date|after_or_equal:quotation_date',
            'notes'            => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.product_id'   => 'nullable|exists:products,product_id',
            'items.*.product_name' => 'required|string',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit_price'   => 'required|numeric|min:0',
            'items.*.notes'        => 'nullable|string',
            'items.*.item_status' => 'required|in:ready,indent',
            'items.*.brand'         => 'nullable|string|max:200',
            'items.*.category'      => 'nullable|string|max:100',
            'items.*.unit'          => 'nullable|string|max:50',
            'items.*.product_code' => 'nullable|string|max:100',
         
            'items.*.supplier_id' => [
                'nullable',
                'exists:suppliers,supplier_id',
                function ($attribute, $value, $fail) use ($request) {
                    $index = explode('.', $attribute)[1];
                    $status = $request->items[$index]['item_status'] ?? 'ready';
                    if ($status === 'indent' && empty($value)) {
                        $fail('Supplier wajib dipilih jika item berstatus indent.');
                    }
                },
            ],
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

            $quotation->update([
                'customer_id'      => $request->customer_id,
                'quotation_number' => $request->quotation_number,
                'activity_type_id' => $request->activity_type_id,
                'quotation_date'   => $request->quotation_date,
                'valid_until'      => $request->valid_until,
                'notes'            => $request->notes,
                // ✅ company_id tidak bisa diubah via update
            ]);

            QuotationItem::where('quotation_id', $quotation->quotation_id)->delete();

            foreach ($request->items as $item) {
                $productId = $this->resolveProductId($item); // ← auto-create jika manual

                QuotationItem::create([
                    'quotation_id' => $quotation->quotation_id,
                    'product_id'   => $productId,             // ← selalu ada sekarang
                    'product_name' => $item['product_name'],
                    'quantity'     => $item['quantity'],
                    'unit_price'   => $item['unit_price'],
                    'item_status'  => $item['item_status'],
                    'supplier_id'  => $item['supplier_id'] ?? null,
                    'notes'        => $item['notes'] ?? null,
                ]);
            }

            $quotation->updateTotalAmount();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quotation updated successfully',
                'data'    => $quotation->load(['items', 'activityType']),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update quotation',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $quotation = Quotation::where('company_id', $companyId)->find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found',
            ], 404);
        }

        if ($quotation->purchaseOrders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete quotation with existing purchase orders',
            ], 409);
        }

        try {
            $quotation->delete();
            return response()->json([
                'success' => true,
                'message' => 'Quotation deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete quotation',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);
        $quotation = Quotation::where('company_id', $companyId)->find($id);

        if (!$quotation) {
            return response()->json(['success' => false, 'message' => 'Quotation not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,sent,issued,approved,rejected,expired',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $quotation->update(['status' => $request->status]);
            return response()->json([
                'success' => true,
                'message' => 'Quotation status updated successfully',
                'data'    => $quotation,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function issue(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);
        $quotation = Quotation::with(['company', 'customer', 'items'])
            ->where('company_id', $companyId)
            ->find($id);

        if (!$quotation) {
            return response()->json(['success' => false, 'message' => 'Quotation not found'], 404);
        }

        if (!in_array($quotation->status, ['draft', 'sent'])) {
            return response()->json([
                'success'        => false,
                'message'        => 'Only draft or sent quotations can be issued',
                'current_status' => $quotation->status,
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'signed_name'      => 'required|string|max:100',
            'signed_position'  => 'required|string|max:100',
            'signed_city'      => 'required|string|max:50',
            'signature_image'  => 'required|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $quotation->issue(
                $request->signed_name,
                $request->signed_position,
                $request->signed_city,
                $request->file('signature_image'),
                Auth::id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Quotation issued successfully',
                'data'    => $quotation->load(['issuedByUser']),    
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to issue quotation',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function convertToPurchaseOrder(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);
        $quotation = Quotation::with(['items'])
            ->where('company_id', $companyId)
            ->find($id);

        if (!$quotation) {
            return response()->json(['success' => false, 'message' => 'Quotation not found'], 404);
        }

        if ($quotation->status !== 'approved') {
            return response()->json([
                'success'        => false,
                'message'        => 'Only approved quotations can be converted to purchase order',
                'current_status' => $quotation->status,
            ], 422);
        }
         $existingPO = PurchaseOrder::where('quotation_id', $id)->first();
    if ($existingPO) {
        return response()->json([
            'success'     => false,
            'message'     => "Quotation ini sudah dikonversi ke Purchase Order #{$existingPO->po_number}",
            'existing_po' => [
                'po_id'     => $existingPO->po_id,
                'po_number' => $existingPO->po_number,
                'status'    => $existingPO->status,
            ],
        ], 422);
    }

        $validator = Validator::make($request->all(), [
            'po_number'         => 'required|string|max:100|unique:purchase_orders,po_number',
            'po_date'           => 'required|date',
            'po_customer_file'  => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
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
            $totalAmount = $quotation->items->sum(
                fn($item) => $item->quantity * $item->unit_price
            );

            $po = PurchaseOrder::create([
                'company_id'       => $companyId,  // ✅ Dari session
                'customer_id'      => $quotation->customer_id,
                'quotation_id'     => $quotation->quotation_id,
                'activity_type_id' => $quotation->activity_type_id,
                'po_number'        => $request->po_number,
                'po_date'          => $request->po_date,
                'valid_until'      => $quotation->valid_until ?? $request->po_date,
                'status'           => 'draft',
                'notes'            => $quotation->notes,
                'total_amount'     => $totalAmount,
                'created_by'       => Auth::id(),
            ]);

            foreach ($quotation->items as $quotItem) {
                PurchaseOrderItem::create([
                    'po_id'            => $po->po_id,
                    'product_id'       => $quotItem->product_id,
                    'product_name'     => $quotItem->product_name,
                    'specification'    => null,
                    'quantity'         => $quotItem->quantity,
                    'unit'             => 'pcs',
                    'unit_price'       => $quotItem->unit_price,
                    'discount_percent' => 0,
                    'notes'            => $quotItem->notes,
                ]);
            }

            if ($request->hasFile('po_customer_file')) {
                $po->uploadPoCustomerFile($request->file('po_customer_file'));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quotation converted to purchase order successfully',
                'data'    => $po->load(['company', 'customer', 'items']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to convert quotation to purchase order',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function generateNumber()
    {
        $year = date('Y');

        $last = Quotation::whereYear('created_at', $year)
            ->orderBy('quotation_id', 'desc')
            ->first();

        $nextNumber = 1;

        if ($last) {
            preg_match('/QT\/\d{4}\/(\d+)/', $last->quotation_number, $matches);
            $nextNumber = intval($matches[1]) + 1;
        }

        $quotationNumber = 'QT/' . $year . '/' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        return response()->json([
            'quotation_number' => $quotationNumber
        ]);
    }


    public function generatePDF($id)
    {
        return app(QuotationPDFController::class)->generate($id);
    }

    public function downloadPDF($id)
    {
        return app(QuotationPDFController::class)->download($id);
    }
    /**
     * Auto-create product jika belum ada, return product_id
     */
    private function resolveProductId(array $item): ?int
    {
        if (!empty($item['product_id'])) {
            return (int) $item['product_id'];
        }

        if (!empty($item['product_name'])) {
            $product = Product::create([
                'product_code'  => !empty($item['product_code'])
                    ? $item['product_code']           // ← pakai input user
                    : 'MNL-' . strtoupper(uniqid()), // ← fallback auto
                'product_name'  => $item['product_name'],
                'brand'         => $item['brand'] ?? '-',
                'category'      => $item['category'] ?? null,
                'unit'          => $item['unit'] ?? 'pcs',
                'selling_price' => $item['unit_price'] ?? 0,
                'purchase_price' => 0,
                'supplier_id'   => $item['supplier_id'] ?? null,
                'is_precursor'  => false,
            ]);
            return $product->product_id;
        }

        return null;
    }
}
