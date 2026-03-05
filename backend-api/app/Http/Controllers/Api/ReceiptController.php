<?php

namespace App\Http\Controllers\Api;

use App\Models\Receipt;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class ReceiptController extends BaseController  // ✅ extends BaseController
{
    /* =========================
     * INDEX
     * ========================= */
    public function index(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $query = Receipt::with([
            'company:company_id,company_name',
            'invoice.customer',
            'payment',
            'createdByUser:user_id,full_name',
        ])
        ->where('company_id', $companyId); // ✅ filter company aktif, hapus manual filter company_id dari request

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->payment_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('receipt_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('receipt_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('received_from', 'like', "%{$search}%");
            });
        }

        $allowedSortBy = ['receipt_date', 'amount', 'receipt_number', 'created_at'];
        $sortBy    = in_array($request->get('sort_by'), $allowedSortBy) ? $request->get('sort_by') : 'receipt_date';
        $sortOrder = $request->get('sort_order') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        $receipts = $query->paginate((int) $request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Receipts retrieved successfully',
            'data'    => $receipts,
        ], 200);
    }

    /* =========================
     * STORE
     * ========================= */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invoice_id'     => 'required|exists:invoices,invoice_id',
            'payment_id'     => 'required|exists:payments,payment_id',
            'receipt_number' => 'required|string|max:100|unique:receipts,receipt_number',
            'receipt_date'   => 'required|date',
            'amount'         => 'required|numeric|min:0.01',
            'received_from'  => 'required|string|max:255',
            'payment_for'    => 'required|string|max:500',
            'notes'          => 'nullable|string',
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

            // ✅ Validasi invoice milik company aktif
            $invoice = Invoice::where('company_id', $companyId)
                ->find($request->invoice_id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice tidak ditemukan atau bukan milik company aktif',
                ], 404);
            }

            $receipt = Receipt::create([
                'company_id'     => $companyId, // ✅ dari BaseController
                'invoice_id'     => $request->invoice_id,
                'payment_id'     => $request->payment_id,
                'receipt_number' => $request->receipt_number,
                'receipt_date'   => $request->receipt_date,
                'amount'         => $request->amount,
                'received_from'  => $request->received_from,
                'payment_for'    => $request->payment_for,
                'notes'          => $request->notes,
                'created_by'     => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Receipt created successfully',
                'data'    => $receipt->load(['invoice', 'payment', 'createdByUser']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create receipt',
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

        $receipt = Receipt::with([
            'company',
            'invoice.customer',
            'payment',
            'createdByUser',
        ])
        ->where('company_id', $companyId)
        ->find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Receipt retrieved successfully',
            'data'    => $receipt,
        ], 200);
    }

    /* =========================
     * DOWNLOAD PDF
     * ========================= */
    public function downloadPdf(Request $request, $id)  // ✅ tambah Request
    {
        try {
            $companyId = $this->getCompanyId($request); // ✅

            $receipt = Receipt::with([
                'company',
                'invoice.customer',
                'payment',
                'createdByUser',
            ])
            ->where('company_id', $companyId)
            ->find($id);

            if (!$receipt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kwitansi tidak ditemukan',
                ], 404);
            }

            $pdf = Pdf::loadView('pdf.receipt', ['receipt' => $receipt])
                ->setPaper('A4', 'portrait');

            $filename = 'Kwitansi-' . $receipt->receipt_number . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal generate PDF',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * UPDATE
     * ========================= */
    public function update(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $receipt = Receipt::where('company_id', $companyId)->find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'receipt_date'  => 'sometimes|required|date',
            'received_from' => 'sometimes|required|string|max:255',
            'payment_for'   => 'sometimes|required|string|max:500',
            'notes'         => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $receipt->update($request->only([
                'receipt_date',
                'received_from',
                'payment_for',
                'notes',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Receipt updated successfully',
                'data'    => $receipt,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update receipt',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * DESTROY
     * ========================= */
    public function destroy(Request $request, $id)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $receipt = Receipt::where('company_id', $companyId)->find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found',
            ], 404);
        }

        try {
            $receipt->delete();

            return response()->json([
                'success' => true,
                'message' => 'Receipt deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete receipt',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * GENERATE FROM PAYMENT
     * ========================= */
    public function generateFromPayment(Request $request, $paymentId)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        // ✅ Pastikan payment milik company aktif via invoice
        $payment = Payment::with('invoice.customer')
            ->whereHas('invoice', fn($q) => $q->where('company_id', $companyId))
            ->find($paymentId);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan atau bukan milik company aktif',
            ], 404);
        }

        if ($payment->receipt()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Kwitansi sudah ada untuk payment ini',
            ], 409);
        }

        if ($payment->status !== 'success') {
            return response()->json([
                'success' => false,
                'message' => 'Payment harus berstatus success untuk generate kwitansi',
            ], 422);
        }

        try {
            DB::beginTransaction();

            $lastReceipt = Receipt::where('company_id', $companyId) // ✅ scope per company
                ->whereYear('created_at', date('Y'))
                ->whereMonth('created_at', date('m'))
                ->orderBy('receipt_id', 'desc')
                ->first();

            $nextNumber    = $lastReceipt ? (int) substr($lastReceipt->receipt_number, -4) + 1 : 1;
            $receiptNumber = 'KWT-' . date('Ym') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            $receipt = Receipt::create([
                'company_id'     => $companyId, // ✅ dari BaseController
                'invoice_id'     => $payment->invoice_id,
                'payment_id'     => $payment->payment_id,
                'receipt_number' => $receiptNumber,
                'receipt_date'   => $payment->payment_date,
                'amount'         => $payment->amount,
                'received_from'  => $payment->invoice->customer->customer_name ?? 'N/A',
                'payment_for'    => 'Pembayaran Invoice ' . $payment->invoice->invoice_number,
                'notes'          => $payment->notes,
                'created_by'     => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Kwitansi berhasil di-generate',
                'data'    => $receipt->load(['invoice', 'payment', 'createdByUser']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal generate kwitansi',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
