<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;


use App\Models\SupplierPurchaseOrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\AutoProcurementService;
use Barryvdh\DomPDF\Facade\Pdf;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class SupplierPurchaseOrderController extends Controller
{

     public function __construct(
        private readonly AutoProcurementService $procurementService
    ) {}
    /**
     * Get all Supplier POs with filters
     */
    public function index(Request $request)
    {
        $query = SupplierPurchaseOrder::with([
            'supplier', 'company', 'createdByUser', 'issuedByUser', 'items', 'purchaseOrder',
        ]);

        if ($request->filled('company_id'))     $query->where('company_id',  $request->company_id);
        if ($request->filled('supplier_id'))    $query->where('supplier_id', $request->supplier_id);
        if ($request->filled('po_id'))          $query->where('po_id',       $request->po_id);
        if ($request->filled('status'))         $query->where('status',      $request->status);
        if ($request->filled('payment_status')) $query->where('payment_status', $request->payment_status);

        // ✅ NEW: filter by supplier_name
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
        // ✅ Resolve supplier_id dari supplier_name jika dikirim
        if ($request->filled('supplier_name') && !$request->filled('supplier_id')) {
            $supplier = Supplier::findByName($request->supplier_name);
            if (!$supplier) {
                return response()->json([
                    'success' => false,
                    'message' => "Supplier '{$request->supplier_name}' tidak ditemukan",
                    'suggestions' => Supplier::searchByName($request->supplier_name)->pluck('supplier_name', 'supplier_id'),
                ], 422);
            }
            $request->merge(['supplier_id' => $supplier->supplier_id]);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id'            => 'required|exists:suppliers,supplier_id',
            'company_id'             => 'required|exists:companies,company_id',
            'po_id'                  => 'nullable|exists:purchase_orders,po_id',
            'po_date'                => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:po_date',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,product_id',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit_price'     => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'tax_amount'             => 'nullable|numeric|min:0',
            'discount_amount'        => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            [$subtotal, $items] = $this->calcTotals($request->items);
            $tax      = $request->input('tax_amount', 0);
            $disc     = $request->input('discount_amount', 0);
            $total    = $subtotal + $tax - $disc;

            $spo = SupplierPurchaseOrder::create([
                'po_number'              => SupplierPurchaseOrder::generatePoNumber(),
                'po_id'                  => $request->po_id,
                'supplier_id'            => $request->supplier_id,
                'company_id'             => $request->company_id,
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
                SupplierPurchaseOrderItem::create(array_merge($item, ['supplier_po_id' => $spo->supplier_po_id]));
            }

            DB::commit();
            return response()->json(['success' => true, 'data' => $spo->load(['supplier', 'company', 'items'])], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

   public function checkShortage(Request $request, int $id)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer|exists:companies,company_id',
        // ✅ UPDATE validation rule: ganti 'priority' jadi 'last'
        'strategy'   => 'nullable|in:last,cheapest,most_frequent',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors(),
        ], 422);
    }

    try {
        $po = PurchaseOrder::findOrFail($id);

        $result = $this->procurementService->handleStockShortage(
            $po,
            $request->company_id,
            autoCreate: false,  // dry-run
            strategy: $request->input('strategy', 'last')  // ✅ default 'last'
        );

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to check shortage: ' . $e->getMessage(),
        ], 500);
    }
}

public function autoProcure(Request $request, int $id)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer|exists:companies,company_id',
        // ✅ UPDATE validation rule
        'strategy'   => 'nullable|in:last,cheapest,most_frequent',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors(),
        ], 422);
    }

    try {
        $po = PurchaseOrder::findOrFail($id);

        $result = $this->procurementService->handleStockShortage(
            $po,
            $request->company_id,
            autoCreate: true,   // create supplier PO
            strategy: $request->input('strategy', 'last')  // ✅ default 'last'
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


   public function suppliersForProduct(Request $request, int $productId)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|exists:companies,company_id',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $suppliers = $this->procurementService->getSuppliersForProduct(
        $productId,
        (int) $request->company_id
    );

    return response()->json([
        'success' => true,
        'data'    => $suppliers,
        'source'  => 'stock_in_history', // ✅ informasi ke FE: data dari histori
    ]);
}

    /**
     * Show specific Supplier PO
     */
    public function show($id)
    {
        $supplierPo = SupplierPurchaseOrder::with([
            'supplier',
            'company',
            'items.product',
            'createdByUser',
            'issuedByUser',
            'purchaseOrder',
        ])->find($id);

        if (!$supplierPo) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier PO not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $supplierPo
        ], 200);
    }

    /**
     * Update Supplier PO (only if draft)
     */
    public function update(Request $request, $id)
    {
        $supplierPo = SupplierPurchaseOrder::find($id);

        if (!$supplierPo) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier PO not found'
            ], 404);
        }

        if ($supplierPo->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft POs can be updated'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'sometimes|exists:suppliers,supplier_id',
             'po_id' => 'sometimes|nullable|exists:purchase_orders,po_id',
            'company_id' => 'sometimes|exists:companies,company_id',
            'po_date' => 'sometimes|date',
            'expected_delivery_date' => 'nullable|date',
            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,product_id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
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
            // Update items if provided
            if ($request->has('items')) {
                // Delete old items
                SupplierPurchaseOrderItem::where('supplier_po_id', $supplierPo->supplier_po_id)->delete();

                // Calculate new totals
                $subtotal = 0;
                foreach ($request->items as $item) {
                    $product = Product::find($item['product_id']);
                    
                    $itemSubtotal = $item['quantity'] * $item['unit_price'];
                    $discountPercent = $item['discount_percent'] ?? 0;
                    $itemDiscountAmount = ($itemSubtotal * $discountPercent) / 100;
                    $itemTotal = $itemSubtotal - $itemDiscountAmount;
                    $subtotal += $itemTotal;

                    SupplierPurchaseOrderItem::create([
                        'supplier_po_id' => $supplierPo->supplier_po_id,
                        'product_id' => $product->product_id,
                        'product_name' => $product->product_name,
                        'product_code' => $product->product_code,
                        'quantity' => $item['quantity'],
                        'unit' => $product->unit ?? 'pcs',
                        'unit_price' => $item['unit_price'],
                        'discount_percent' => $discountPercent,
                        'discount_amount' => $itemDiscountAmount,
                        'subtotal' => $itemSubtotal,
                        'total' => $itemTotal,
                        'received_quantity' => 0,
                    ]);
                }

                $taxAmount = $request->input('tax_amount', $supplierPo->tax_amount);
                $discountAmount = $request->input('discount_amount', $supplierPo->discount_amount);
                $totalAmount = $subtotal + $taxAmount - $discountAmount;

                $supplierPo->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'total_amount' => $totalAmount,
                ]);
            }

            // Update other fields
            $supplierPo->update($request->except(['items', 'status']));

            DB::commit();

            $supplierPo->load(['supplier', 'company', 'items.product', 'createdByUser']);

            return response()->json([
                'success' => true,
                'message' => 'Supplier PO updated successfully',
                'data' => $supplierPo
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update supplier PO',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Issue Supplier PO (mark as issued with signature)
     */
    public function issue(Request $request, $id)
    {
        $supplierPo = SupplierPurchaseOrder::find($id);

        if (!$supplierPo) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier PO not found'
            ], 404);
        }

        if ($supplierPo->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft POs can be issued'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'signed_name' => 'required|string',
            'signed_position' => 'required|string',
            'signed_city' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $supplierPo->update([
                'status' => 'issued',
                'signed_name' => $request->signed_name,
                'signed_position' => $request->signed_position,
                'signed_city' => $request->signed_city,
                'issued_at' => now(),
                'issued_by' => Auth::id(),
            ]);

            $supplierPo->load(['supplier', 'company', 'items.product', 'issuedByUser']);

            return response()->json([
                'success' => true,
                'message' => 'Supplier PO issued successfully',
                'data' => $supplierPo
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to issue supplier PO',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update status
     */
    public function updateStatus(Request $request, $id)
    {
        $supplierPo = SupplierPurchaseOrder::find($id);

        if (!$supplierPo) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier PO not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,issued,partial,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $supplierPo->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully',
            'data' => $supplierPo
        ], 200);
    }

    /**
     * Delete Supplier PO (only if draft)
     */
    public function destroy($id)
    {
        $supplierPo = SupplierPurchaseOrder::find($id);

        if (!$supplierPo) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier PO not found'
            ], 404);
        }

        if ($supplierPo->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft POs can be deleted'
            ], 422);
        }

        try {
            $supplierPo->delete();

            return response()->json([
                'success' => true,
                'message' => 'Supplier PO deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete supplier PO',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
 * Export PDF
 */
public function exportPDF($id)
{
    $supplierPo = SupplierPurchaseOrder::with([
        'supplier',
        'company',
        'items.product',
        'issuedByUser'
    ])->find($id);

    if (!$supplierPo) {
        return response()->json([
            'success' => false,
            'message' => 'Supplier PO not found'
        ], 404);
    }

    // Validasi: tidak bisa export jika masih draft
    if ($supplierPo->status === 'draft') {
        return response()->json([
            'success' => false,
            'message' => 'Cannot export PDF. PO is still in draft status'
        ], 422);
    }

    try {
        // Calculate totals
        $subtotal = $supplierPo->subtotal;
        $tax = $supplierPo->tax_amount;
        $discount = $supplierPo->discount_amount;
        $grand_total = $supplierPo->total_amount;

        $pdf = PDF::loadView('pdf.supplier-purchase-order', [
            'po' => $supplierPo,
            'supplier' => $supplierPo->supplier,
            'company' => $supplierPo->company,
            'items' => $supplierPo->items,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'grand_total' => $grand_total,
        ]);

        return $pdf->download('Supplier-PO-' . $supplierPo->po_number . '.pdf');
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to generate PDF',
            'error' => $e->getMessage()
        ], 500);
    }
}

 private function calcTotals(array $rawItems): array
    {
        $subtotal    = 0;
        $preparedItems = [];

        foreach ($rawItems as $item) {
            $product        = Product::findOrFail($item['product_id']);
            $itemSubtotal   = $item['quantity'] * $item['unit_price'];
            $discPct        = $item['discount_percent'] ?? 0;
            $discAmt        = $itemSubtotal * $discPct / 100;
            $itemTotal      = $itemSubtotal - $discAmt;
            $subtotal      += $itemTotal;

            $preparedItems[] = [
                'product_id'       => $product->product_id,
                'product_name'     => $product->product_name,
                'product_code'     => $product->product_code ?? null,
                'quantity'         => $item['quantity'],
                'unit'             => $product->unit ?? 'pcs',
                'unit_price'       => $item['unit_price'],
                'discount_percent' => $discPct,
                'discount_amount'  => $discAmt,
                'subtotal'         => $itemSubtotal,
                'total'            => $itemTotal,
                'received_quantity' => 0,
            ];
        }

        return [$subtotal, $preparedItems];
    }

}
