<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
class ReceiptController extends Controller
{
    /**
     * Display a listing of receipts
     */
    public function index(Request $request)
    {
        $query = Receipt::with([
            'company:company_id,company_name',
            'invoice.customer',
            'payment',
            'createdByUser:user_id,full_name'
        ]);

        // Filter by company
        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Filter by invoice
        if ($request->has('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        // Filter by payment
        if ($request->has('payment_id')) {
            $query->where('payment_id', $request->payment_id);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('receipt_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('receipt_date', '<=', $request->end_date);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('received_from', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'receipt_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $receipts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Receipts retrieved successfully',
            'data' => $receipts
        ], 200);
    }

    /**
     * Store a newly created receipt
     */
   /**
 * Store a newly created receipt
 */
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'nullable|exists:companies,company_id',
        'invoice_id' => 'required|exists:invoices,invoice_id',
        'payment_id' => 'required|exists:payments,payment_id',
        'receipt_number' => 'required|string|max:100|unique:receipts,receipt_number',
        'receipt_date' => 'required|date',
        'amount' => 'required|numeric|min:0.01',
        'received_from' => 'required|string|max:255',
        'payment_for' => 'required|string|max:500',
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
        DB::beginTransaction();

        // Get company_id from invoice if not provided
        $companyId = $request->company_id;
        if (!$companyId) {
            $invoice = Invoice::find($request->invoice_id);
            $companyId = $invoice->company_id ?? null;
        }

        // ✅ FIX: Ganti issued_by jadi created_by
        $receipt = Receipt::create([
            'company_id' => $companyId,
            'invoice_id' => $request->invoice_id,
            'payment_id' => $request->payment_id,
            'receipt_number' => $request->receipt_number,
            'receipt_date' => $request->receipt_date,
            'amount' => $request->amount,
            'received_from' => $request->received_from,
            'payment_for' => $request->payment_for,
            'notes' => $request->notes,
            'created_by' => Auth::id(), // ✅ CHANGE: issued_by → created_by
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Receipt created successfully',
            'data' => $receipt->load(['invoice', 'payment', 'createdByUser'])
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Failed to create receipt',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * ✅ Generate receipt from payment
 */



    /**
     * Display the specified receipt
     */
    public function show($id)
    {
        $receipt = Receipt::with([
            'company',
            'invoice.customer',
            'payment',
            'createdByUser'
        ])->find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Receipt retrieved successfully',
            'data' => $receipt
        ], 200);
    }

     public function downloadPdf($id)
    {
        try {
            $receipt = Receipt::with([
                'company',
                'invoice.customer',
                'payment',
                'createdByUser'
            ])->find($id);

            if (!$receipt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kwitansi tidak ditemukan'
                ], 404);
            }

            // Load PDF view
            $pdf = Pdf::loadView('pdf.receipt', [
                'receipt' => $receipt
            ]);

            // Set paper size & orientation
            $pdf->setPaper('A4', 'portrait');

            // Filename
            $filename = 'Kwitansi-' . $receipt->receipt_number . '.pdf';

            // Download
            return $pdf->download($filename);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal generate PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Update the specified receipt
     */
    public function update(Request $request, $id)
    {
        $receipt = Receipt::find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'receipt_date' => 'sometimes|required|date',
            'received_from' => 'sometimes|required|string|max:255',
            'payment_for' => 'sometimes|required|string|max:500',
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
            $receipt->update($request->only([
                'receipt_date',
                'received_from',
                'payment_for',
                'notes'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Receipt updated successfully',
                'data' => $receipt
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified receipt
     */
    public function destroy($id)
    {
        $receipt = Receipt::find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found'
            ], 404);
        }

        try {
            $receipt->delete();

            return response()->json([
                'success' => true,
                'message' => 'Receipt deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }



/**
 * ✅ Generate receipt from payment
 */
public function generateFromPayment($paymentId)
{
    $payment = Payment::with('invoice.customer')->find($paymentId);

    if (!$payment) {
        return response()->json([
            'success' => false,
            'message' => 'Payment tidak ditemukan'
        ], 404);
    }

    // Check if receipt already exists
    if ($payment->receipt()->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Kwitansi sudah ada untuk payment ini'
        ], 409);
    }

    // Check payment status
    if ($payment->status !== 'success') {
        return response()->json([
            'success' => false,
            'message' => 'Payment harus berstatus success untuk generate kwitansi'
        ], 422);
    }

    try {
        DB::beginTransaction();

        // Auto-generate receipt number
        $lastReceipt = Receipt::whereYear('created_at', date('Y'))
            ->whereMonth('created_at', date('m'))
            ->orderBy('receipt_id', 'desc')
            ->first();

        $nextNumber = $lastReceipt ? (int)substr($lastReceipt->receipt_number, -4) + 1 : 1;
        $receiptNumber = 'KWT-' . date('Ym') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        // ✅ FIX: Ganti issued_by jadi created_by
        $receipt = Receipt::create([
            'company_id' => $payment->invoice->company_id ?? null,
            'invoice_id' => $payment->invoice_id,
            'payment_id' => $payment->payment_id,
            'receipt_number' => $receiptNumber,
            'receipt_date' => $payment->payment_date,
            'amount' => $payment->amount,
            'received_from' => $payment->invoice->customer->customer_name ?? 'N/A',
            'payment_for' => 'Pembayaran Invoice ' . $payment->invoice->invoice_number,
            'notes' => $payment->notes,
            'created_by' => Auth::id(), // ✅ CHANGE: issued_by → created_by
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Kwitansi berhasil di-generate',
            'data' => $receipt->load(['invoice', 'payment', 'createdByUser'])
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Gagal generate kwitansi',
            'error' => $e->getMessage()
        ], 500);
    }
}

}
