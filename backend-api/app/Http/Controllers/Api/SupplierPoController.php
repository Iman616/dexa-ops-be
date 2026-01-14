<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierPo;
use App\Models\SupplierPoItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SupplierPoController extends Controller
{
    /**
     * Display a listing of purchase orders
     */
public function index(Request $request)
{
    $query = SupplierPo::with('supplier');

    // Search
    if ($request->filled('search')) {
        $search = $request->search;
        $query->where(function ($q) use ($search) {
            $q->where('po_number', 'like', "%{$search}%")
              ->orWhereHas('supplier', function ($sq) use ($search) {
                  $sq->where('supplier_name', 'like', "%{$search}%");
              });
        });
    }

    // Multiple status (sent,approved,received)
    if ($request->filled('status')) {
        $statuses = explode(',', $request->status);
        $query->whereIn('status', $statuses);
    }

    if ($request->filled('supplier_id')) {
        $query->where('supplier_id', $request->supplier_id);
    }

    if ($request->filled('start_date')) {
        $query->whereDate('po_date', '>=', $request->start_date);
    }

    if ($request->filled('end_date')) {
        $query->whereDate('po_date', '<=', $request->end_date);
    }

    $query->orderBy(
        $request->get('sort_by', 'po_date'),
        $request->get('sort_order', 'desc')
    );

    $supplierPos = $query->paginate(
        (int) $request->get('per_page', 15)
    );

    return response()->json([
        'success' => true,
        'data' => $supplierPos
    ]);
}


    /**
     * Store a newly created purchase order
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,supplier_id',
            'po_number' => 'required|string|max:50|unique:supplier_po,po_number',
            'po_date' => 'required|date',
            'status' => 'nullable|in:draft,sent,approved,received,cancelled',
            'total_amount' => 'nullable|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
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

            $po = SupplierPo::create([
                'supplier_id' => $request->supplier_id,
                'po_number' => $request->po_number,
                'po_date' => $request->po_date,
                'status' => $request->status ?? 'draft',
                'total_amount' => $request->total_amount ?? 0,
            ]);

            // Create PO items if provided
            if ($request->has('items')) {
                $totalAmount = 0;
                foreach ($request->items as $item) {
                    $subtotal = $item['quantity'] * $item['unit_price'];
                    $totalAmount += $subtotal;
                    
                    // Assuming you have supplier_po_items table
                    // You'll need to create this model and migration
                }
                
                // Update total amount
                $po->update(['total_amount' => $totalAmount]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase order created successfully',
                'data' => $po->load('supplier')
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
     * Display the specified purchase order
     */
    public function show($id)
    {
        $po = SupplierPo::with(['supplier', 'stockIns.product'])->find($id);

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

    /**
     * Update the specified purchase order
     */
    public function update(Request $request, $id)
    {
        $po = SupplierPo::find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'sometimes|required|exists:suppliers,supplier_id',
            'po_number' => 'sometimes|required|string|max:50|unique:supplier_po,po_number,' . $id . ',supplier_po_id',
            'po_date' => 'sometimes|required|date',
            'status' => 'sometimes|required|in:draft,sent,approved,received,cancelled',
            'total_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $po->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Purchase order updated successfully',
                'data' => $po->load('supplier')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified purchase order
     */
    public function destroy($id)
    {
        $po = SupplierPo::find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        // Only allow deletion of draft or cancelled POs
        if (!in_array($po->status, ['draft', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft or cancelled purchase orders can be deleted'
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
     * Update PO status
     */
    public function updateStatus(Request $request, $id)
    {
        $po = SupplierPo::find($id);

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,sent,approved,received,cancelled',
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
}
