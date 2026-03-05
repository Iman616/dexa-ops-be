<?php

namespace App\Http\Controllers\Api;

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

class StockReturnController extends BaseController  // ✅ extends BaseController
{
    /* =========================
     * INDEX
     * ========================= */
    public function index(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $query = StockReturn::with([
            'company', 'customer', 'supplier', 'product', 'batch',
            'deliveryNote', 'stockOut', 'stockIn',
            'createdByUser', 'approvedByUser', 'rejectedByUser',
            'processedByUser', 'stockMovements',
        ])
        ->where('company_id', $companyId); // ✅ filter company aktif

        if ($request->has('return_type'))  $query->where('return_type', $request->return_type);
        if ($request->has('status'))       $query->where('status', $request->status);
        if ($request->has('customer_id'))  $query->where('customer_id', $request->customer_id);
        if ($request->has('supplier_id'))  $query->where('supplier_id', $request->supplier_id);
        if ($request->has('product_id'))   $query->where('product_id', $request->product_id);
        if ($request->has('return_reason')) $query->where('return_reason', $request->return_reason);
        if ($request->has('start_date'))   $query->where('return_date', '>=', $request->start_date);
        if ($request->has('end_date'))     $query->where('return_date', '<=', $request->end_date);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'like', "%{$search}%")
                  ->orWhere('return_notes', 'like', "%{$search}%")
                  ->orWhereHas('product', fn($pq) =>
                      $pq->where('product_name', 'like', "%{$search}%")
                         ->orWhere('product_code', 'like', "%{$search}%")
                  )
                  ->orWhereHas('customer', fn($cq) =>
                      $cq->where('customer_name', 'like', "%{$search}%")
                  )
                  ->orWhereHas('supplier', fn($sq) =>
                      $sq->where('supplier_name', 'like', "%{$search}%")
                  );
            });
        }

        if ($request->boolean('customer_returns_only')) $query->customerReturns();
        if ($request->boolean('supplier_returns_only')) $query->supplierReturns();
        if ($request->boolean('pending_only'))          $query->pending();
        if ($request->boolean('approved_only'))         $query->approved();

        $query->orderBy($request->get('sort_by', 'return_date'), $request->get('sort_order', 'desc'));

        return response()->json([
            'success' => true,
            'message' => 'Stock returns retrieved successfully',
            'data'    => $query->paginate($request->get('per_page', 15)),
        ], 200);
    }

    /* =========================
     * STORE
     * ========================= */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'return_type'      => 'required|in:customer_return,supplier_return',
            'delivery_note_id' => 'nullable|exists:delivery_notes,delivery_note_id',
            'stock_out_id'     => 'nullable|exists:stock_out,stock_out_id',
            'stock_in_id'      => 'nullable|exists:stock_in,stock_in_id',
            'customer_id'      => 'required_if:return_type,customer_return|exists:customers,customer_id',
            'supplier_id'      => 'required_if:return_type,supplier_return|exists:suppliers,supplier_id',
            'product_id'       => 'required|exists:products,product_id',
            'batch_id'         => 'nullable|exists:stock_batches,batch_id',
            'return_date'      => 'required|date',
            'quantity'         => 'required|numeric|min:0.01',
            'unit'             => 'required|string|max:50',
            'return_reason'    => 'required|in:damaged,expired,wrong_item,quality_issue,overstocked,customer_request,other',
            'return_notes'     => 'nullable|string',
            'return_value'     => 'nullable|numeric|min:0',
            'refund_method'    => 'nullable|in:cash,transfer,credit_note,replacement,none',
            'refund_amount'    => 'nullable|numeric|min:0',
            'proof_file'       => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'signed_by'        => 'nullable|string|max:255',
            'signed_position'  => 'nullable|string|max:100',
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

            $companyId = $this->getCompanyId($request); // ✅

            // ✅ Ambil company dari companyId dinamis, bukan dari request
            $company = Company::find($companyId);
            $returnNumber = StockReturn::generateReturnNumber(
                $company->company_code,
                $request->return_type
            );

            $returnValue = $request->return_value;
            if (!$returnValue) {
                if ($request->return_type === 'customer_return' && $request->stock_out_id) {
                    $stockOut    = StockOut::find($request->stock_out_id);
                    $returnValue = $stockOut->selling_price * $request->quantity;
                } elseif ($request->return_type === 'supplier_return' && $request->stock_in_id) {
                    $stockIn     = StockIn::find($request->stock_in_id);
                    $returnValue = $stockIn->purchase_price * $request->quantity;
                }
            }

            $return = StockReturn::create([
                'company_id'       => $companyId, // ✅ dari BaseController
                'return_type'      => $request->return_type,
                'delivery_note_id' => $request->delivery_note_id,
                'stock_out_id'     => $request->stock_out_id,
                'stock_in_id'      => $request->stock_in_id,
                'customer_id'      => $request->customer_id,
                'supplier_id'      => $request->supplier_id,
                'product_id'       => $request->product_id,
                'batch_id'         => $request->batch_id,
                'return_number'    => $returnNumber,
                'return_date'      => $request->return_date,
                'quantity'         => $request->quantity,
                'unit'             => $request->unit,
                'return_reason'    => $request->return_reason,
                'return_notes'     => $request->return_notes,
                'return_value'     => $returnValue ?? 0,
                'refund_method'    => $request->refund_method,
                'refund_amount'    => $request->refund_amount ?? 0,
                'signed_by'        => $request->signed_by,
                'signed_position'  => $request->signed_position,
                'status'           => 'draft',
                'created_by'       => Auth::id(),
            ]);

            if ($request->hasFile('proof_file')) {
                $return->uploadProofFile($request->file('proof_file'));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock return created successfully',
                'data'    => $return->load([
                    'company', 'customer', 'supplier', 'product', 'batch',
                    'deliveryNote', 'stockOut', 'stockIn',
                ]),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock return',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * SHOW
     * ========================= */
    public function show(Request $request, $id)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::with([
            'company', 'customer', 'supplier', 'product', 'batch',
            'deliveryNote.items', 'stockOut', 'stockIn',
            'createdByUser', 'approvedByUser', 'rejectedByUser',
            'processedByUser', 'stockMovements',
        ])
        ->where('company_id', $companyId)
        ->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock return retrieved successfully',
            'data'    => $return,
        ], 200);
    }

    /* =========================
     * UPDATE
     * ========================= */
    public function update(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        if (!in_array($return->status, ['draft', 'pending'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update return in current status: ' . $return->status,
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'return_date'    => 'sometimes|required|date',
            'quantity'       => 'sometimes|required|numeric|min:0.01',
            'return_reason'  => 'sometimes|required|in:damaged,expired,wrong_item,quality_issue,overstocked,customer_request,other',
            'return_notes'   => 'nullable|string',
            'return_value'   => 'nullable|numeric|min:0',
            'refund_method'  => 'nullable|in:cash,transfer,credit_note,replacement,none',
            'refund_amount'  => 'nullable|numeric|min:0',
            'proof_file'     => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'signed_by'      => 'nullable|string|max:255',
            'signed_position'=> 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false, 'message' => 'Validation error', 'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $return->update($request->except(['proof_file']));

            if ($request->hasFile('proof_file')) {
                $return->uploadProofFile($request->file('proof_file'));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock return updated successfully',
                'data'    => $return->fresh(['company', 'customer', 'supplier', 'product', 'batch']),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 'message' => 'Failed to update stock return', 'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * DESTROY
     * ========================= */
    public function destroy(Request $request, $id)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        if ($return->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete return that is not in draft status',
            ], 409);
        }

        try {
            $return->delete();
            return response()->json(['success' => true, 'message' => 'Stock return deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'message' => 'Failed to delete stock return', 'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * SUBMIT
     * ========================= */
    public function submit(Request $request, $id)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        try {
            $return->submit();
            return response()->json([
                'success' => true, 'message' => 'Stock return submitted for approval', 'data' => $return->fresh(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /* =========================
     * APPROVE
     * ========================= */
    public function approve(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        $validator = Validator::make($request->all(), ['approval_notes' => 'nullable|string']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $return->approve($request->approval_notes);
            return response()->json([
                'success' => true, 'message' => 'Stock return approved successfully',
                'data'    => $return->fresh(['approvedByUser']),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /* =========================
     * REJECT
     * ========================= */
    public function reject(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        $validator = Validator::make($request->all(), ['rejection_reason' => 'required|string']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $return->reject($request->rejection_reason);
            return response()->json([
                'success' => true, 'message' => 'Stock return rejected',
                'data'    => $return->fresh(['rejectedByUser']),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /* =========================
     * PROCESS
     * ========================= */
    public function process(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        if ($return->status !== 'approved') {
            return response()->json(['success' => false, 'message' => 'Only approved returns can be processed'], 409);
        }

        $validator = Validator::make($request->all(), ['processing_notes' => 'nullable|string']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $movementType       = 'RETURN';
            $quantityAdjustment = $return->return_type === 'customer_return'
                ? $return->quantity
                : -$return->quantity;

            StockMovement::create([
                'product_id'     => $return->product_id,
                'batch_id'       => $return->batch_id,
                'movement_type'  => $movementType,
                'quantity'       => abs($return->quantity),
                'unit_cost'      => $return->return_value / $return->quantity,
                'reference_id'   => $return->return_id,
                'reference_type' => 'stock_return',
                'notes'          => $return->return_type === 'customer_return'
                    ? "Customer return: {$return->return_number}"
                    : "Supplier return: {$return->return_number}",
                'created_by'     => Auth::id(),
            ]);

            if ($return->batch_id) {
                $batch = StockBatch::find($return->batch_id);
                if ($batch) {
                    $batch->quantity_available += $quantityAdjustment;
                    $batch->status = $batch->quantity_available <= 0
                        ? 'depleted'
                        : ($batch->expiry_date && $batch->expiry_date < now() ? 'expired' : 'active');
                    $batch->save();
                }
            }

            $return->update([
                'status'           => 'completed',
                'processed_by'     => Auth::id(),
                'processed_at'     => now(),
                'processing_notes' => $request->processing_notes,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock return processed successfully and stock adjusted',
                'data'    => $return->load(['product', 'batch', 'customer', 'supplier', 'processedByUser']),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 'message' => 'Failed to process stock return', 'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * CANCEL
     * ========================= */
    public function cancel(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        $validator = Validator::make($request->all(), ['cancellation_reason' => 'required|string']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $return->cancel($request->cancellation_reason);
            return response()->json([
                'success' => true, 'message' => 'Stock return cancelled', 'data' => $return->fresh(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /* =========================
     * CREATE FROM DELIVERY NOTE
     * ========================= */
    public function createFromDeliveryNote(Request $request, $deliveryNoteId)
    {
        $companyId = $this->getCompanyId($request); // ✅

        // ✅ Pastikan delivery note milik company aktif
        $deliveryNote = DeliveryNote::with(['items', 'company', 'purchaseOrder.customer'])
            ->where('company_id', $companyId)
            ->find($deliveryNoteId);

        if (!$deliveryNote) {
            return response()->json(['success' => false, 'message' => 'Delivery note not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'item_id'       => 'required|exists:delivery_note_items,delivery_note_item_id',
            'quantity'      => 'required|numeric|min:0.01',
            'return_reason' => 'required|in:damaged,expired,wrong_item,quality_issue,overstocked,customer_request,other',
            'return_notes'  => 'nullable|string',
            'batch_id'      => 'nullable|exists:stock_batches,batch_id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $item = $deliveryNote->items()->find($request->item_id);

            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Delivery note item not found'], 404);
            }

            if ($request->quantity > $item->quantity) {
                return response()->json([
                    'success' => false, 'message' => 'Return quantity cannot exceed delivered quantity',
                ], 400);
            }

            $stockOut = StockOut::where('delivery_note_id', $deliveryNoteId)
                ->where('product_id', $item->product_id)
                ->first();

            $return = $deliveryNote->createReturn([
                'product_id'   => $item->product_id,
                'batch_id'     => $request->batch_id ?? $stockOut->batch_id ?? null,
                'stock_out_id' => $stockOut->stock_out_id ?? null,
                'quantity'     => $request->quantity,
                'unit'         => $item->unit,
                'return_value' => $request->quantity * ($item->unit_price ?? 0),
            ], $request->return_reason, $request->return_notes);

            return response()->json([
                'success' => true,
                'message' => 'Return created from delivery note',
                'data'    => $return->load(['company', 'customer', 'product', 'deliveryNote']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'message' => 'Failed to create return', 'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * CREATE FROM STOCK IN
     * ========================= */
    public function createFromStockIn(Request $request, $stockInId)
    {
        $companyId = $this->getCompanyId($request); // ✅

        // ✅ Pastikan stock in milik company aktif
        $stockIn = StockIn::with(['company', 'product', 'batch', 'supplierPo.supplier'])
            ->where('company_id', $companyId)
            ->find($stockInId);

        if (!$stockIn) {
            return response()->json(['success' => false, 'message' => 'Stock in record not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'quantity'      => 'required|numeric|min:0.01',
            'return_reason' => 'required|in:damaged,expired,wrong_item,quality_issue,overstocked,customer_request,other',
            'return_notes'  => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            if ($request->quantity > $stockIn->quantity) {
                return response()->json([
                    'success' => false, 'message' => 'Return quantity cannot exceed received quantity',
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
                'data'    => $return->load(['company', 'supplier', 'product', 'stockIn']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'message' => 'Failed to create return', 'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * UPLOAD PROOF
     * ========================= */
    public function uploadProof(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'proof_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $return->uploadProofFile($request->file('proof_file'));
            return response()->json([
                'success' => true,
                'message' => 'Proof file uploaded successfully',
                'data'    => [
                    'proof_file_path' => $return->proof_file_path,
                    'proof_file_url'  => $return->getProofFileUrl(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'message' => 'Failed to upload proof file', 'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * DOWNLOAD PROOF
     * ========================= */
    public function downloadProof(Request $request, $id)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        if (!$return->proof_file_path) {
            return response()->json(['success' => false, 'message' => 'Proof file not found'], 404);
        }

        if (!Storage::disk('public')->exists($return->proof_file_path)) {
            return response()->json(['success' => false, 'message' => 'File does not exist'], 404);
        }

        return response()->download(
            Storage::disk('public')->path($return->proof_file_path),
            basename($return->proof_file_path)
        );
    }

    /* =========================
     * DELETE PROOF
     * ========================= */
    public function deleteProof(Request $request, $id)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $return = StockReturn::where('company_id', $companyId)->find($id);

        if (!$return) {
            return response()->json(['success' => false, 'message' => 'Stock return not found'], 404);
        }

        try {
            $return->deleteProofFile();
            $return->update(['proof_file_path' => null]);
            return response()->json(['success' => true, 'message' => 'Proof file deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'message' => 'Failed to delete proof file', 'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * GET BY DELIVERY NOTE
     * ========================= */
    public function getByDeliveryNote(Request $request, $deliveryNoteId)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $returns = StockReturn::with(['product', 'batch', 'customer', 'createdByUser', 'approvedByUser', 'processedByUser'])
            ->where('company_id', $companyId)
            ->where('delivery_note_id', $deliveryNoteId)
            ->get();

        return response()->json([
            'success' => true, 'message' => 'Returns retrieved successfully', 'data' => $returns,
        ], 200);
    }

    /* =========================
     * GET BY STOCK OUT
     * ========================= */
    public function getByStockOut(Request $request, $stockOutId)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $returns = StockReturn::with(['product', 'batch', 'customer', 'deliveryNote'])
            ->where('company_id', $companyId)
            ->where('stock_out_id', $stockOutId)
            ->get();

        return response()->json([
            'success' => true, 'message' => 'Returns retrieved successfully', 'data' => $returns,
        ], 200);
    }

    /* =========================
     * GET BY STOCK IN
     * ========================= */
    public function getByStockIn(Request $request, $stockInId)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $returns = StockReturn::with(['product', 'batch', 'supplier', 'stockIn'])
            ->where('company_id', $companyId)
            ->where('stock_in_id', $stockInId)
            ->get();

        return response()->json([
            'success' => true, 'message' => 'Returns retrieved successfully', 'data' => $returns,
        ], 200);
    }

    /* =========================
     * SUMMARY
     * ========================= */
    public function summary(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $base = StockReturn::where('company_id', $companyId); // ✅ hapus manual filter company_id

        if ($request->has('start_date') && $request->has('end_date')) {
            $base->whereBetween('return_date', [$request->start_date, $request->end_date]);
        }

        $summary = [
            'total_returns'       => (clone $base)->count(),
            'customer_returns'    => (clone $base)->customerReturns()->count(),
            'supplier_returns'    => (clone $base)->supplierReturns()->count(),
            'pending_approval'    => (clone $base)->pending()->count(),
            'approved'            => (clone $base)->approved()->count(),
            'completed'           => (clone $base)->completed()->count(),
            'total_return_value'  => (clone $base)->sum('return_value'),
            'total_refund_amount' => (clone $base)->where('status', 'completed')->sum('refund_amount'),
            'by_reason'           => (clone $base)->select('return_reason', DB::raw('count(*) as count'))
                ->groupBy('return_reason')->get(),
            'by_status'           => (clone $base)->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')->get(),
        ];

        return response()->json([
            'success' => true, 'message' => 'Return summary retrieved successfully', 'data' => $summary,
        ], 200);
    }

    /* =========================
     * GENERATE NUMBER
     * ========================= */
    public function generateNumber(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'return_type' => 'required|in:customer_return,supplier_return',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $companyId = $this->getCompanyId($request); // ✅ hapus company_id dari validator

            $company      = Company::find($companyId);
            $returnNumber = StockReturn::generateReturnNumber(
                $company->company_code,
                $request->return_type
            );

            return response()->json([
                'success' => true,
                'message' => 'Return number generated successfully',
                'data'    => ['return_number' => $returnNumber],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'message' => 'Failed to generate return number', 'error' => $e->getMessage(),
            ], 500);
        }
    }
}
