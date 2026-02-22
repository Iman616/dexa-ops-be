<?php

namespace App\Http\Controllers\Api;

use App\Models\SupplierPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPurchaseOrderItem;
use App\Models\Product;
use App\Models\SupplierDeliveryNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\AutoProcurementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class SupplierPurchaseOrderController extends BaseController
{
    public function __construct(
        private readonly AutoProcurementService $procurementService
    ) {}

    /**
     * Get all Supplier POs with filters
     */
    public function index(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $query = SupplierPurchaseOrder::with([
            'supplier', 'company', 'createdByUser', 'issuedByUser', 'items', 'purchaseOrder',
        ])->where('company_id', $companyId); // ✅ selalu filter by company

        if ($request->filled('supplier_id'))    $query->where('supplier_id',    $request->supplier_id);
        if ($request->filled('po_id'))          $query->where('po_id',          $request->po_id);
        if ($request->filled('status'))         $query->where('status',         $request->status);
        if ($request->filled('payment_status')) $query->where('payment_status', $request->payment_status);

        if ($request->filled('supplier_name')) {
            $query->whereHas('supplier', fn($q) =>
                $q->where('supplier_name', 'like', '%' . $request->supplier_name . '%')
            );
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('po_number', 'like', "%$s%")
                  ->orWhereHas('supplier', fn($sq) => $sq->where('supplier_name', 'like', "%$s%"))
            );
        }

        $query->orderBy($request->input('sort_by', 'po_date'), $request->input('sort_order', 'desc'));

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->input('per_page', 15)),
        ]);
    }

    /**
     * Store new Supplier PO
     */
    public function store(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        // Resolve supplier_id dari supplier_name jika dikirim
        if ($request->filled('supplier_name') && !$request->filled('supplier_id')) {
            $supplier = Supplier::findByName($request->supplier_name);
            if (!$supplier) {
                return response()->json([
                    'success'     => false,
                    'message'     => "Supplier '{$request->supplier_name}' tidak ditemukan",
                    'suggestions' => Supplier::searchByName($request->supplier_name)->pluck('supplier_name', 'supplier_id'),
                ], 422);
            }
            $request->merge(['supplier_id' => $supplier->supplier_id]);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id'              => 'required|exists:suppliers,supplier_id',
            'po_id'                    => 'nullable|exists:purchase_orders,po_id',
            'po_date'                  => 'required|date',
            'expected_delivery_date'   => 'nullable|date|after_or_equal:po_date',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,product_id',
            'items.*.quantity'         => 'required|numeric|min:0.01',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'tax_amount'               => 'nullable|numeric|min:0',
            'discount_amount'          => 'nullable|numeric|min:0',
            // ✅ company_id tidak perlu divalidasi — sudah dihandle BaseController
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            [$subtotal, $items] = $this->calcTotals($request->items);
            $tax   = $request->input('tax_amount', 0);
            $disc  = $request->input('discount_amount', 0);
            $total = $subtotal + $tax - $disc;

            $spo = SupplierPurchaseOrder::create([
                'po_number'              => SupplierPurchaseOrder::generatePoNumber(),
                'po_id'                  => $request->po_id,
                'supplier_id'            => $request->supplier_id,
                'company_id'             => $companyId, // ✅
                'po_date'                => $request->po_date,
                'expected_delivery_date' => $request->expected_delivery_date,
                'status'                 => 'draft',
                'payment_status'         => 'unpaid',
                'subtotal'               => $subtotal,
                'tax_amount'             => $tax,
                'discount_amount'        => $disc,
                'total_amount'           => $total,
                'notes'                  => $request->notes,
                'terms'                  => $request->terms,
                'created_by'             => Auth::id(),
            ]);

            foreach ($items as $item) {
                SupplierPurchaseOrderItem::create(
                    array_merge($item, ['supplier_po_id' => $spo->supplier_po_id])
                );
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'data'    => $spo->load(['supplier', 'company', 'items']),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check shortage
     */
    public function checkShortage(Request $request, int $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $validator = Validator::make($request->all(), [
            'strategy'                       => 'nullable|in:last,cheapest,most_frequent',
            'manual_suppliers'               => 'nullable|array',
            'manual_suppliers.*.product_id'  => 'required|integer|exists:products,product_id',
            'manual_suppliers.*.supplier_id' => 'required|integer|exists:suppliers,supplier_id',
            'manual_suppliers.*.unit_price'  => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $po = PurchaseOrder::findOrFail($id);

            $result = $this->procurementService->handleStockShortage(
                $po,
                $companyId, // ✅
                autoCreate:      false,
                strategy:        $request->input('strategy', 'last'),
                manualSuppliers: $request->input('manual_suppliers', [])
            );

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check shortage: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto procure
     */
    public function autoProcure(Request $request, int $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $validator = Validator::make($request->all(), [
            'strategy'                       => 'nullable|in:last,cheapest,most_frequent',
            'manual_suppliers'               => 'nullable|array',
            'manual_suppliers.*.product_id'  => 'required|integer|exists:products,product_id',
            'manual_suppliers.*.supplier_id' => 'required|integer|exists:suppliers,supplier_id',
            'manual_suppliers.*.unit_price'  => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $po = PurchaseOrder::findOrFail($id);

            $result = $this->procurementService->handleStockShortage(
                $po,
                $companyId, // ✅
                autoCreate:      true,
                strategy:        $request->input('strategy', 'last'),
                manualSuppliers: $request->input('manual_suppliers', [])
            );

            return response()->json([
                'success' => true,
                'data'    => $result,
                'message' => 'Auto-procurement completed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to auto-procure: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get suppliers for product
     */
    public function suppliersForProduct(Request $request, int $productId)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $suppliers = $this->procurementService->getSuppliersForProduct(
            $productId,
            $companyId // ✅
        );

        return response()->json([
            'success' => true,
            'data'    => $suppliers,
            'source'  => 'stock_in_history',
        ]);
    }

    /**
     * Show specific Supplier PO
     */
    public function show($id)
    {
        $supplierPo = SupplierPurchaseOrder::with([
            'supplier', 'company', 'items.product', 'createdByUser', 'issuedByUser', 'purchaseOrder',
        ])->find($id);

        if (!$supplierPo) {
            return response()->json(['success' => false, 'message' => 'Supplier PO not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $supplierPo]);
    }

    /**
     * Update Supplier PO (only if draft)
     */
    public function update(Request $request, $id)
    {
        $companyId  = $this->getCompanyId($request); // ✅
        $supplierPo = SupplierPurchaseOrder::find($id);

        if (!$supplierPo) {
            return response()->json(['success' => false, 'message' => 'Supplier PO not found'], 404);
        }

        if ($supplierPo->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only draft POs can be updated'], 422);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id'            => 'sometimes|exists:suppliers,supplier_id',
            'po_id'                  => 'sometimes|nullable|exists:purchase_orders,po_id',
            'po_date'                => 'sometimes|date',
            'expected_delivery_date' => 'nullable|date',
            'items'                  => 'sometimes|array|min:1',
            'items.*.product_id'     => 'required_with:items|exists:products,product_id',
            'items.*.quantity'       => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price'     => 'required_with:items|numeric|min:0',
            // ✅ company_id tidak divalidasi — tidak boleh diubah via request
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
            if ($request->has('items')) {
                SupplierPurchaseOrderItem::where('supplier_po_id', $supplierPo->supplier_po_id)->delete();

                $subtotal = 0;
                foreach ($request->items as $item) {
                    $product            = Product::findOrFail($item['product_id']);
                    $itemSubtotal       = $item['quantity'] * $item['unit_price'];
                    $discountPercent    = $item['discount_percent'] ?? 0;
                    $itemDiscountAmount = $itemSubtotal * $discountPercent / 100;
                    $itemTotal          = $itemSubtotal - $itemDiscountAmount;
                    $subtotal          += $itemTotal;

                    SupplierPurchaseOrderItem::create([
                        'supplier_po_id'   => $supplierPo->supplier_po_id,
                        'product_id'       => $product->product_id,
                        'product_name'     => $product->product_name,
                        'product_code'     => $product->product_code,
                        'quantity'         => $item['quantity'],
                        'unit'             => $product->unit ?? 'pcs',
                        'unit_price'       => $item['unit_price'],
                        'discount_percent' => $discountPercent,
                        'discount_amount'  => $itemDiscountAmount,
                        'subtotal'         => $itemSubtotal,
                        'total'            => $itemTotal,
                        'received_quantity' => 0,
                    ]);
                }

                $taxAmount      = $request->input('tax_amount', $supplierPo->tax_amount);
                $discountAmount = $request->input('discount_amount', $supplierPo->discount_amount);

                $supplierPo->update([
                    'subtotal'        => $subtotal,
                    'tax_amount'      => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'total_amount'    => $subtotal + $taxAmount - $discountAmount,
                ]);
            }

            // ✅ Exclude company_id dari mass update — tidak boleh berubah
            $supplierPo->update($request->except(['items', 'status', 'company_id']));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Supplier PO updated successfully',
                'data'    => $supplierPo->load(['supplier', 'company', 'items.product', 'createdByUser']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to update supplier PO', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Issue Supplier PO
     */
    public function issue(Request $request, $id)
    {
        $supplierPo = SupplierPurchaseOrder::find($id);

        if (!$supplierPo) {
            return response()->json(['success' => false, 'message' => 'Supplier PO not found'], 404);
        }

        if ($supplierPo->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only draft POs can be issued'], 422);
        }

        $validator = Validator::make($request->all(), [
            'signed_name'     => 'required|string',
            'signed_position' => 'required|string',
            'signed_city'     => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $supplierPo->update([
                'status'          => 'issued',
                'signed_name'     => $request->signed_name,
                'signed_position' => $request->signed_position,
                'signed_city'     => $request->signed_city,
                'issued_at'       => now(),
                'issued_by'       => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Supplier PO issued successfully',
                'data'    => $supplierPo->load(['supplier', 'company', 'items.product', 'issuedByUser']),
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to issue supplier PO', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update status
     */
    public function updateStatus(Request $request, $id)
    {
        $supplierPo = SupplierPurchaseOrder::with(['items.product', 'supplier', 'company'])->find($id);

        if (!$supplierPo) {
            return response()->json(['success' => false, 'message' => 'Supplier PO not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,issued,partial,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $supplierPo->status;
            $newStatus = $request->status;

            $supplierPo->update(['status' => $newStatus]);

            $draftDeliveryNote = null;
            if ($newStatus === 'completed' && $oldStatus !== 'completed') {
                $draftDeliveryNote = $this->createDraftDeliveryNote($supplierPo);
            }

            DB::commit();

            return response()->json([
                'success'             => true,
                'message'             => 'Status updated successfully' . ($draftDeliveryNote ? '. Draft Surat Jalan telah dibuat.' : ''),
                'data'                => $supplierPo,
                'draft_delivery_note' => $draftDeliveryNote,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to update status', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete Supplier PO (only if draft)
     */
    public function destroy($id)
    {
        $supplierPo = SupplierPurchaseOrder::find($id);

        if (!$supplierPo) {
            return response()->json(['success' => false, 'message' => 'Supplier PO not found'], 404);
        }

        if ($supplierPo->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only draft POs can be deleted'], 422);
        }

        try {
            $supplierPo->delete();
            return response()->json(['success' => true, 'message' => 'Supplier PO deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete supplier PO', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export PDF
     */
    public function exportPDF($id)
    {
        $supplierPo = SupplierPurchaseOrder::with([
            'supplier', 'company', 'items.product', 'issuedByUser',
        ])->find($id);

        if (!$supplierPo) {
            return response()->json(['success' => false, 'message' => 'Supplier PO not found'], 404);
        }

        if ($supplierPo->status === 'draft') {
            return response()->json(['success' => false, 'message' => 'Cannot export PDF. PO is still in draft status'], 422);
        }

        try {
            $pdf = PDF::loadView('pdf.supplier-purchase-order', [
                'po'          => $supplierPo,
                'supplier'    => $supplierPo->supplier,
                'company'     => $supplierPo->company,
                'items'       => $supplierPo->items,
                'subtotal'    => $supplierPo->subtotal,
                'tax'         => $supplierPo->tax_amount,
                'discount'    => $supplierPo->discount_amount,
                'grand_total' => $supplierPo->total_amount,
            ]);

            return $pdf->download('Supplier-PO-' . $supplierPo->po_number . '.pdf');
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to generate PDF', 'error' => $e->getMessage()], 500);
        }
    }

    /* ================= PRIVATE HELPERS ================= */

    private function createDraftDeliveryNote(SupplierPurchaseOrder $spo): SupplierDeliveryNote
    {
        $year     = date('Y');
        $sequence = \App\Models\SupplierDeliveryNote::whereYear('created_at', $year)->count() + 1;
        $dnNumber = 'SDN/' . ($spo->company->company_code ?? 'XXX') . '/' . $year . '/' . str_pad($sequence, 4, '0', STR_PAD_LEFT);

        $deliveryNote = \App\Models\SupplierDeliveryNote::create([
            'company_id'           => $spo->company_id,
            'supplier_id'          => $spo->supplier_id,
            'supplier_po_id'       => $spo->supplier_po_id,
            'delivery_note_number' => $dnNumber,
            'delivery_note_date'   => now()->toDateString(),
            'status'               => 'pending',
            'notes'                => 'Auto-generated dari Supplier PO #' . $spo->po_number,
            'created_by'           => Auth::id(),
        ]);

        foreach ($spo->items as $item) {
            \App\Models\SupplierDeliveryNoteItem::create([
                'supplier_delivery_note_id' => $deliveryNote->supplier_delivery_note_id,
                'product_id'                => $item->product_id,
                'batch_number'              => 'BATCH-' . strtoupper(uniqid()),
                'quantity'                  => (int) $item->quantity,
                'purchase_price'            => $item->unit_price,
                'manufacture_date'          => null,
                'expiry_date'               => null,
                'notes'                     => $item->product_name,
            ]);
        }

        return $deliveryNote->load(['items.product', 'supplier']);
    }

    private function calcTotals(array $rawItems): array
    {
        $subtotal      = 0;
        $preparedItems = [];

        foreach ($rawItems as $item) {
            $product      = Product::findOrFail($item['product_id']);
            $itemSubtotal = $item['quantity'] * $item['unit_price'];
            $discPct      = $item['discount_percent'] ?? 0;
            $discAmt      = $itemSubtotal * $discPct / 100;
            $itemTotal    = $itemSubtotal - $discAmt;
            $subtotal    += $itemTotal;

            $preparedItems[] = [
                'product_id'        => $product->product_id,
                'product_name'      => $product->product_name,
                'product_code'      => $product->product_code ?? null,
                'quantity'          => $item['quantity'],
                'unit'              => $product->unit ?? 'pcs',
                'unit_price'        => $item['unit_price'],
                'discount_percent'  => $discPct,
                'discount_amount'   => $discAmt,
                'subtotal'          => $itemSubtotal,
                'total'             => $itemTotal,
                'received_quantity' => 0,
            ];
        }

        return [$subtotal, $preparedItems];
    }
}
