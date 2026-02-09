<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockReturn;
use App\Models\DeliveryNote;
use App\Models\StockOut;
use App\Models\StockMovement;
use App\Models\StockBatch;


use App\Models\StockIn;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class StockReturnController extends Controller
{
    /**
     * Display a listing of stock returns
     * GET /api/stock-returns
     */
    public function index(Request $request)
    {
        $query = StockReturn::with([
            'company',
            'customer',
            'supplier',
            'product',
            'batch',
            'deliveryNote',
            'stockOut',
            'stockIn',
            'createdByUser',
            'approvedByUser',
            'rejectedByUser',
            'processedByUser',
            'stockMovements'
        ]);

        // Filter by company
        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Filter by return type
        if ($request->has('return_type')) {
            $query->where('return_type', $request->return_type);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by supplier
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by return reason
        if ($request->has('return_reason')) {
            $query->where('return_reason', $request->return_reason);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('return_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('return_date', '<=', $request->end_date);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('return_number', 'like', "%{$search}%")
                    ->orWhere('return_notes', 'like', "%{$search}%")
                    ->orWhereHas('product', function($pq) use ($search) {
                        $pq->where('product_name', 'like', "%{$search}%")
                           ->orWhere('product_code', 'like', "%{$search}%");
                    })
                    ->orWhereHas('customer', function($cq) use ($search) {
                        $cq->where('customer_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('supplier', function($sq) use ($search) {
                        $sq->where('supplier_name', 'like', "%{$search}%");
                    });
            });
        }

        // Scope shortcuts
        if ($request->boolean('customer_returns_only')) {
            $query->customerReturns();
        }

        if ($request->boolean('supplier_returns_only')) {
            $query->supplierReturns();
        }

        if ($request->boolean('pending_only')) {
            $query->pending();
        }

        if ($request->boolean('approved_only')) {
            $query->approved();
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'return_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $returns = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Stock returns retrieved successfully',
            'data' => $returns
        ], 200);
    }

    /**
     * Store a newly created stock return (Customer Return)
     * POST /api/stock-returns
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
            'return_type' => 'required|in:customer_return,supplier_return',
            
            // Reference
            'delivery_note_id' => 'nullable|exists:delivery_notes,delivery_note_id',
            'stock_out_id' => 'nullable|exists:stock_out,stock_out_id',
            'stock_in_id' => 'nullable|exists:stock_in,stock_in_id',
            
            // Customer/Supplier
            'customer_id' => 'required_if:return_type,customer_return|exists:customers,customer_id',
            'supplier_id' => 'required_if:return_type,supplier_return|exists:suppliers,supplier_id',
            
            // Product & Batch
            'product_id' => 'required|exists:products,product_id',
            'batch_id' => 'nullable|exists:stock_batches,batch_id',
            
            // Return details
            'return_date' => 'required|date',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'required|string|max:50',
            'return_reason' => 'required|in:damaged,expired,wrong_item,quality_issue,overstocked,customer_request,other',
            'return_notes' => 'nullable|string',
            
            // Financial
            'return_value' => 'nullable|numeric|min:0',
            'refund_method' => 'nullable|in:cash,transfer,credit_note,replacement,none',
            'refund_amount' => 'nullable|numeric|min:0',
            
            // Proof
            'proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'signed_by' => 'nullable|string|max:255',
            'signed_position' => 'nullable|string|max:100',
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

            // Get company for return number generation
            $company = Company::find($request->company_id);
            $returnNumber = StockReturn::generateReturnNumber(
                $company->company_code,
                $request->return_type
            );

            // Calculate return value if not provided
            $returnValue = $request->return_value;
            if (!$returnValue) {
                if ($request->return_type === 'customer_return' && $request->stock_out_id) {
                    $stockOut = StockOut::find($request->stock_out_id);
                    $returnValue = $stockOut->selling_price * $request->quantity;
                } elseif ($request->return_type === 'supplier_return' && $request->stock_in_id) {
                    $stockIn = StockIn::find($request->stock_in_id);
                    $returnValue = $stockIn->purchase_price * $request->quantity;
                }
            }

            // Create return
            $return = StockReturn::create([
                'company_id' => $request->company_id,
                'return_type' => $request->return_type,
                'delivery_note_id' => $request->delivery_note_id,
                'stock_out_id' => $request->stock_out_id,
                'stock_in_id' => $request->stock_in_id,
                'customer_id' => $request->customer_id,
                'supplier_id' => $request->supplier_id,
                'product_id' => $request->product_id,
                'batch_id' => $request->batch_id,
                'return_number' => $returnNumber,
                'return_date' => $request->return_date,
                'quantity' => $request->quantity,
                'unit' => $request->unit,
                'return_reason' => $request->return_reason,
                'return_notes' => $request->return_notes,
                'return_value' => $returnValue ?? 0,
                'refund_method' => $request->refund_method,
                'refund_amount' => $request->refund_amount ?? 0,
                'signed_by' => $request->signed_by,
                'signed_position' => $request->signed_position,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            // Upload proof file if exists
            if ($request->hasFile('proof_file')) {
                $return->uploadProofFile($request->file('proof_file'));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock return created successfully',
                'data' => $return->load([
                    'company', 'customer', 'supplier', 'product', 'batch',
                    'deliveryNote', 'stockOut', 'stockIn'
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified stock return
     * GET /api/stock-returns/{id}
     */
    public function show($id)
    {
        $return = StockReturn::with([
            'company',
            'customer',
            'supplier',
            'product',
            'batch',
            'deliveryNote.items',
            'stockOut',
            'stockIn',
            'createdByUser',
            'approvedByUser',
            'rejectedByUser',
            'processedByUser',
            'stockMovements'
        ])->find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock return retrieved successfully',
            'data' => $return
        ], 200);
    }

    /**
     * Update the specified stock return
     * PUT /api/stock-returns/{id}
     */
    public function update(Request $request, $id)
    {
        $return = StockReturn::find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        // Only allow update if status is draft or pending
        if (!in_array($return->status, ['draft', 'pending'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update return in current status: ' . $return->status
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'return_date' => 'sometimes|required|date',
            'quantity' => 'sometimes|required|numeric|min:0.01',
            'return_reason' => 'sometimes|required|in:damaged,expired,wrong_item,quality_issue,overstocked,customer_request,other',
            'return_notes' => 'nullable|string',
            'return_value' => 'nullable|numeric|min:0',
            'refund_method' => 'nullable|in:cash,transfer,credit_note,replacement,none',
            'refund_amount' => 'nullable|numeric|min:0',
            'proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'signed_by' => 'nullable|string|max:255',
            'signed_position' => 'nullable|string|max:100',
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

            $return->update($request->except(['proof_file']));

            // Upload new proof file if exists
            if ($request->hasFile('proof_file')) {
                $return->uploadProofFile($request->file('proof_file'));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock return updated successfully',
                'data' => $return->fresh([
                    'company', 'customer', 'supplier', 'product', 'batch'
                ])
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified stock return
     * DELETE /api/stock-returns/{id}
     */
    public function destroy($id)
    {
        $return = StockReturn::find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        // Only allow delete if status is draft
        if ($return->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete return that is not in draft status'
            ], 409);
        }

        try {
            $return->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock return deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit return for approval
     * POST /api/stock-returns/{id}/submit
     */
    public function submit($id)
    {
        $return = StockReturn::find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        try {
            $return->submit();

            return response()->json([
                'success' => true,
                'message' => 'Stock return submitted for approval',
                'data' => $return->fresh()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Approve return
     * POST /api/stock-returns/{id}/approve
     */
    public function approve(Request $request, $id)
    {
        $return = StockReturn::find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'approval_notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $return->approve($request->approval_notes);

            return response()->json([
                'success' => true,
                'message' => 'Stock return approved successfully',
                'data' => $return->fresh(['approvedByUser'])
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Reject return
     * POST /api/stock-returns/{id}/reject
     */
    public function reject(Request $request, $id)
    {
        $return = StockReturn::find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $return->reject($request->rejection_reason);

            return response()->json([
                'success' => true,
                'message' => 'Stock return rejected',
                'data' => $return->fresh(['rejectedByUser'])
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Process return - adjust stock
     * POST /api/stock-returns/{id}/process
     */
  public function process(Request $request, $id)
    {
        $return = StockReturn::find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        // Only approved returns can be processed
        if ($return->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only approved returns can be processed'
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'processing_notes' => 'nullable|string',
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

            // ✅ FIX: Tentukan movement_type yang benar sesuai ENUM
            if ($return->return_type === 'customer_return') {
                // Customer return = barang masuk kembali ke warehouse
                $movementType = 'RETURN_IN'; // ✅ UBAH DARI 'RETURN_IN' ke nilai ENUM yang valid
            } else {
                // Supplier return = barang keluar kembali ke supplier
                $movementType = 'RETURN_OUT'; // ✅ UBAH DARI 'RETURN_OUT' ke nilai ENUM yang valid
            }

            // ✅ CEGAH ERROR: Pastikan movement_type sesuai dengan ENUM di database
            // Jika ENUM di database hanya: 'IN', 'OUT', 'ADJUSTMENT', 'RETURN'
            // Maka gunakan:
            if ($return->return_type === 'customer_return') {
                $movementType = 'RETURN'; // Barang return masuk
                $quantityAdjustment = $return->quantity; // Tambah stock
            } else {
                $movementType = 'RETURN'; // Barang return keluar
                $quantityAdjustment = -$return->quantity; // Kurangi stock
            }

            // Create stock movement record
            StockMovement::create([
                'product_id' => $return->product_id,
                'batch_id' => $return->batch_id,
                'movement_type' => $movementType, // ✅ Sudah benar sekarang
                'quantity' => abs($return->quantity),
                'unit_cost' => $return->return_value / $return->quantity,
                'reference_id' => $return->return_id,
                'reference_type' => 'stock_return', // ✅ Ubah dari 'StockReturn' ke 'stock_return'
                'notes' => $return->return_type === 'customer_return' 
                    ? "Customer return: {$return->return_number}" 
                    : "Supplier return: {$return->return_number}",
                'created_by' => Auth::id(),
            ]);

            // Update batch quantity if batch_id exists
            if ($return->batch_id) {
                $batch = StockBatch::find($return->batch_id);
                if ($batch) {
                    if ($return->return_type === 'customer_return') {
                        // Customer return: increase stock
                        $batch->quantity_available += $return->quantity;
                    } else {
                        // Supplier return: decrease stock
                        $batch->quantity_available -= $return->quantity;
                    }

                    // Update batch status
                    if ($batch->quantity_available <= 0) {
                        $batch->status = 'depleted';
                    } elseif ($batch->expiry_date && $batch->expiry_date < now()) {
                        $batch->status = 'expired';
                    } else {
                        $batch->status = 'active';
                    }

                    $batch->save();
                }
            }

            // Update return status
            $return->update([
                'status' => 'completed',
                'processed_by' => Auth::id(),
                'processed_at' => now(),
                'processing_notes' => $request->processing_notes,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock return processed successfully and stock adjusted',
                'data' => $return->load(['product', 'batch', 'customer', 'supplier', 'processedByUser'])
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to process stock return',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Cancel return
     * POST /api/stock-returns/{id}/cancel
     */
    public function cancel(Request $request, $id)
    {
        $return = StockReturn::find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $return->cancel($request->cancellation_reason);

            return response()->json([
                'success' => true,
                'message' => 'Stock return cancelled',
                'data' => $return->fresh()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Create return from delivery note
     * POST /api/stock-returns/from-delivery-note/{delivery_note_id}
     */
    public function createFromDeliveryNote(Request $request, $deliveryNoteId)
    {
        $deliveryNote = DeliveryNote::with(['items', 'company', 'purchaseOrder.customer'])->find($deliveryNoteId);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'item_id' => 'required|exists:delivery_note_items,delivery_note_item_id',
            'quantity' => 'required|numeric|min:0.01',
            'return_reason' => 'required|in:damaged,expired,wrong_item,quality_issue,overstocked,customer_request,other',
            'return_notes' => 'nullable|string',
            'batch_id' => 'nullable|exists:stock_batches,batch_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $item = $deliveryNote->items()->find($request->item_id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery note item not found'
                ], 404);
            }

            // Validate quantity
            if ($request->quantity > $item->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Return quantity cannot exceed delivered quantity'
                ], 400);
            }

            // Get stock_out_id if exists
            $stockOut = StockOut::where('delivery_note_id', $deliveryNoteId)
                ->where('product_id', $item->product_id)
                ->first();

            $return = $deliveryNote->createReturn([
                'product_id' => $item->product_id,
                'batch_id' => $request->batch_id ?? $stockOut->batch_id ?? null,
                'stock_out_id' => $stockOut->stock_out_id ?? null,
                'quantity' => $request->quantity,
                'unit' => $item->unit,
                'return_value' => $request->quantity * ($item->unit_price ?? 0),
            ], $request->return_reason, $request->return_notes);

            return response()->json([
                'success' => true,
                'message' => 'Return created from delivery note',
                'data' => $return->load(['company', 'customer', 'product', 'deliveryNote'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create return to supplier from stock in
     * POST /api/stock-returns/from-stock-in/{stock_in_id}
     */
    public function createFromStockIn(Request $request, $stockInId)
    {
        $stockIn = StockIn::with(['company', 'product', 'batch', 'supplierPo.supplier'])->find($stockInId);

        if (!$stockIn) {
            return response()->json([
                'success' => false,
                'message' => 'Stock in record not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|numeric|min:0.01',
            'return_reason' => 'required|in:damaged,expired,wrong_item,quality_issue,overstocked,customer_request,other',
            'return_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Validate quantity
            if ($request->quantity > $stockIn->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Return quantity cannot exceed received quantity'
                ], 400);
            }

            $return = $stockIn->createSupplierReturn(
                $request->quantity,
                $request->return_reason,
                $request->return_notes
            );

            return response()->json([
                'success' => true,
                'message' => 'Supplier return created successfully',
                'data' => $return->load(['company', 'supplier', 'product', 'stockIn'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload proof file
     * POST /api/stock-returns/{id}/upload-proof
     */
    public function uploadProof(Request $request, $id)
    {
        $return = StockReturn::find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'proof_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $return->uploadProofFile($request->file('proof_file'));

            return response()->json([
                'success' => true,
                'message' => 'Proof file uploaded successfully',
                'data' => [
                    'proof_file_path' => $return->proof_file_path,
                    'proof_file_url' => $return->getProofFileUrl()
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload proof file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download proof file
     * GET /api/stock-returns/{id}/download-proof
     */
public function downloadProof($id)
{
    $return = StockReturn::find($id);

    if (!$return) {
        return response()->json([
            'success' => false,
            'message' => 'Stock return not found'
        ], 404);
    }

    if (!$return->proof_file_path) {
        return response()->json([
            'success' => false,
            'message' => 'Proof file not found'
        ], 404);
    }

    if (!Storage::disk('public')->exists($return->proof_file_path)) {
        return response()->json([
            'success' => false,
            'message' => 'File does not exist'
        ], 404);
    }

    // ✅ PERBAIKAN: Gunakan response()->download() dengan path lengkap
    $filePath = Storage::disk('public')->path($return->proof_file_path);
    
    // Get original filename
    $originalName = basename($return->proof_file_path);
    
    return response()->download($filePath, $originalName);
}

    /**
     * Delete proof file
     * DELETE /api/stock-returns/{id}/proof
     */
    public function deleteProof($id)
    {
        $return = StockReturn::find($id);

        if (!$return) {
            return response()->json([
                'success' => false,
                'message' => 'Stock return not found'
            ], 404);
        }

        try {
            $return->deleteProofFile();
            $return->update(['proof_file_path' => null]);

            return response()->json([
                'success' => true,
                'message' => 'Proof file deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete proof file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get returns by delivery note
     * GET /api/stock-returns/by-delivery-note/{delivery_note_id}
     */
    public function getByDeliveryNote($deliveryNoteId)
    {
        $returns = StockReturn::with([
            'product',
            'batch',
            'customer',
            'createdByUser',
            'approvedByUser',
            'processedByUser'
        ])
        ->where('delivery_note_id', $deliveryNoteId)
        ->get();

        return response()->json([
            'success' => true,
            'message' => 'Returns retrieved successfully',
            'data' => $returns
        ], 200);
    }

    /**
     * Get returns by stock out
     * GET /api/stock-returns/by-stock-out/{stock_out_id}
     */
    public function getByStockOut($stockOutId)
    {
        $returns = StockReturn::with([
            'product',
            'batch',
            'customer',
            'deliveryNote'
        ])
        ->where('stock_out_id', $stockOutId)
        ->get();

        return response()->json([
            'success' => true,
            'message' => 'Returns retrieved successfully',
            'data' => $returns
        ], 200);
    }

    /**
     * Get returns by stock in
     * GET /api/stock-returns/by-stock-in/{stock_in_id}
     */
    public function getByStockIn($stockInId)
    {
        $returns = StockReturn::with([
            'product',
            'batch',
            'supplier',
            'stockIn'
        ])
        ->where('stock_in_id', $stockInId)
        ->get();

        return response()->json([
            'success' => true,
            'message' => 'Returns retrieved successfully',
            'data' => $returns
        ], 200);
    }

    /**
     * Get summary/statistics
     * GET /api/stock-returns/summary
     */
    public function summary(Request $request)
    {
        $query = StockReturn::query();

        // Filter by company
        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('return_date', [$request->start_date, $request->end_date]);
        }

        $summary = [
            'total_returns' => $query->count(),
            'customer_returns' => (clone $query)->customerReturns()->count(),
            'supplier_returns' => (clone $query)->supplierReturns()->count(),
            'pending_approval' => (clone $query)->pending()->count(),
            'approved' => (clone $query)->approved()->count(),
            'completed' => (clone $query)->completed()->count(),
            'total_return_value' => (clone $query)->sum('return_value'),
            'total_refund_amount' => (clone $query)->where('status', 'completed')->sum('refund_amount'),
            'by_reason' => (clone $query)->select('return_reason', DB::raw('count(*) as count'))
                ->groupBy('return_reason')
                ->get(),
            'by_status' => (clone $query)->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Return summary retrieved successfully',
            'data' => $summary
        ], 200);
    }

    /**
     * Generate return number
     * GET /api/stock-returns/generate-number
     */
    public function generateNumber(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
            'return_type' => 'required|in:customer_return,supplier_return'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $company = Company::find($request->company_id);
            $returnNumber = StockReturn::generateReturnNumber(
                $company->company_code,
                $request->return_type
            );

            return response()->json([
                'success' => true,
                'message' => 'Return number generated successfully',
                'data' => ['return_number' => $returnNumber]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate return number',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
