<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class QuotationController extends Controller
{
    public function index(Request $request)
    {
        $query = Quotation::with(['company', 'customer', 'activityType', 'items', 'createdByUser', 'issuedByUser']);

        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // ✅ FIXED: Filter by activity_type_id
        if ($request->has('activity_type_id')) {
            $query->where('activity_type_id', $request->activity_type_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('quotation_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function($cq) use ($search) {
                      $cq->where('customer_name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('activityType', function($atq) use ($search) {
                      $atq->where('type_name', 'like', "%{$search}%");
                  });
            });
        }

        $sortBy = $request->get('sort_by', 'quotation_id');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        if ($sortBy !== 'quotation_id') {
            $query->orderBy('quotation_id', 'desc');
        }

        $perPage = $request->get('per_page', 15);
        $quotations = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Quotations retrieved successfully',
            'data' => $quotations
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
            'customer_id' => 'required|exists:customers,customer_id',
            'quotation_number' => 'required|string|max:100|unique:quotations,quotation_number',
            'activity_type_id' => 'required|exists:activity_types,activity_type_id', // ✅ REQUIRED
            'quotation_date' => 'required|date',
            'valid_until' => 'required|date|after_or_equal:quotation_date',
            'status' => 'nullable|in:draft,sent,issued,approved,rejected,expired',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.product_name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $quotation = Quotation::create([
                'company_id' => $request->company_id,
                'customer_id' => $request->customer_id,
                'quotation_number' => $request->quotation_number,
                'activity_type_id' => $request->activity_type_id, // ✅ ONLY THIS
                'quotation_date' => $request->quotation_date,
                'valid_until' => $request->valid_until,
                'status' => $request->status ?? 'draft',
                'notes' => $request->notes,
                'created_by' => Auth::id(),
            ]);

            foreach ($request->items as $item) {
                QuotationItem::create([
                    'quotation_id' => $quotation->quotation_id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $quotation->updateTotalAmount();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quotation created successfully',
                'data' => $quotation->load(['items', 'activityType'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create quotation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $quotation = Quotation::with([
            'company',
            'customer',
            'items.product',
            'activityType', // ✅ ADDED
            'purchaseOrders',
            'createdByUser',
            'issuedByUser'
        ])->find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Quotation retrieved successfully',
            'data' => $quotation
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found'
            ], 404);
        }

        // ✅ Only draft can be updated
        if ($quotation->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft quotations can be updated'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
            'customer_id' => 'required|exists:customers,customer_id',
            'quotation_number' => 'required|string|max:100|unique:quotations,quotation_number,' . $id . ',quotation_id',
            'activity_type_id' => 'required|exists:activity_types,activity_type_id', // ✅ REQUIRED
            'quotation_date' => 'required|date',
            'valid_until' => 'required|date|after_or_equal:quotation_date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.product_name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $quotation->update([
                'company_id' => $request->company_id,
                'customer_id' => $request->customer_id,
                'quotation_number' => $request->quotation_number,
                'activity_type_id' => $request->activity_type_id, // ✅ ONLY THIS
                'quotation_date' => $request->quotation_date,
                'valid_until' => $request->valid_until,
                'notes' => $request->notes,
            ]);

            // Delete existing items
            QuotationItem::where('quotation_id', $quotation->quotation_id)->delete();

            // Create new items
            foreach ($request->items as $item) {
                QuotationItem::create([
                    'quotation_id' => $quotation->quotation_id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $quotation->updateTotalAmount();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quotation updated successfully',
                'data' => $quotation->load(['items', 'activityType'])
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update quotation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $quotation = Quotation::find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found'
            ], 404);
        }

        if ($quotation->purchaseOrders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete quotation with existing purchase orders'
            ], 409);
        }

        try {
            $quotation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Quotation deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete quotation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generatePDF($id)
    {
        return app(QuotationPDFController::class)->generate($id);
    }

    public function downloadPDF($id)
    {
        return app(QuotationPDFController::class)->download($id);
    }

    public function updateStatus(Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,sent,issued,approved,rejected,expired',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $quotation->update(['status' => $request->status]);

            return response()->json([
                'success' => true,
                'message' => 'Quotation status updated successfully',
                'data' => $quotation
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function issue(Request $request, $id)
    {
        $quotation = Quotation::with(['company', 'customer', 'items'])->find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found'
            ], 404);
        }

        if (!in_array($quotation->status, ['draft', 'sent'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft or sent quotations can be issued',
                'current_status' => $quotation->status
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
                'data' => $quotation->load(['issuedByUser'])
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to issue quotation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function convertToPurchaseOrder(Request $request, $id)
    {
        $quotation = Quotation::with(['items'])->find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found'
            ], 404);
        }

        if ($quotation->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only approved quotations can be converted to purchase order',
                'current_status' => $quotation->status
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'po_number' => 'required|string|max:100|unique:purchase_orders,po_number',
            'po_date' => 'required|date',
            'po_customer_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
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
            $totalAmount = $quotation->items->sum(function($item) {
                return $item->quantity * $item->unit_price;
            });

           $po = PurchaseOrder::create([
    'company_id'      => $quotation->company_id,
    'customer_id'     => $quotation->customer_id,
    'quotation_id'    => $quotation->quotation_id,
    'activity_type_id'=> $quotation->activity_type_id,     // ⬅️ ikutkan kegiatan
    'po_number'       => $request->po_number,
    'po_date'         => $request->po_date,
    'valid_until'     => $quotation->valid_until ?? $request->po_date, // ⬅️ fix utama
    'status'          => 'draft',
    'notes'           => $quotation->notes,
    'total_amount'    => $totalAmount,
    'created_by'      => Auth::id(),
]);


            foreach ($quotation->items as $quotItem) {
                PurchaseOrderItem::create([
                    'po_id' => $po->po_id,
                    'product_id' => $quotItem->product_id,
                    'product_name' => $quotItem->product_name,
                    'specification' => null,
                    'quantity' => $quotItem->quantity,
                    'unit' => 'pcs',
                    'unit_price' => $quotItem->unit_price,
                    'discount_percent' => 0,
                    'notes' => $quotItem->notes,
                ]);
            }

            if ($request->hasFile('po_customer_file')) {
                $po->uploadPoCustomerFile($request->file('po_customer_file'));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quotation converted to purchase order successfully',
                'data' => $po->load(['company', 'customer', 'items'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to convert quotation to purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
