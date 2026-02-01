<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class QuotationController extends Controller
{
    public function index(Request $request)
    {
        $query = Quotation::with(['company', 'customer', 'items', 'createdByUser', 'issuedByUser']);

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
                $q->where('quotation_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function($cq) use ($search) {
                      $cq->where('customer_name', 'like', "%{$search}%");
                  });
            });
        }

        $sortBy = $request->get('sort_by', 'quotation_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

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
                'data' => $quotation->load('items')
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

        $validator = Validator::make($request->all(), [
            'customer_id' => 'sometimes|required|exists:customers,customer_id',
            'quotation_date' => 'sometimes|required|date',
            'valid_until' => 'sometimes|required|date',
            'status' => 'nullable|in:draft,sent,issued,approved,rejected,expired',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $quotation->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Quotation updated successfully',
                'data' => $quotation->load('items')
            ], 200);

        } catch (\Exception $e) {
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

    /**
     * Generate PDF for quotation
     */
    public function generatePDF($id)
    {
        return app(QuotationPDFController::class)->generate($id);
    }

    /**
     * Download PDF for quotation
     */
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

    /**
     * Issue quotation dengan tanda tangan digital
     */
    public function issue(Request $request, $id)
    {
        $quotation = Quotation::with(['company', 'customer', 'items'])->find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found'
            ], 404);
        }

        // Validasi hanya bisa issue jika status draft atau sent
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
}
