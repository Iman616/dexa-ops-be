<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PaymentController extends BaseController  // ✅ extends BaseController
{
    /* =========================
     * INDEX
     * ========================= */
    public function index(Request $request)
    {
        try {
            $companyId = $this->getCompanyId($request); // ✅

            $query = Payment::with([
                'invoice.customer',
                'receipt',
                'createdByUser:user_id,full_name',
                'approvedByUser:user_id,full_name',
                'cancelledByUser:user_id,full_name',
            ])
            // ✅ Filter hanya payment milik company aktif via invoice
            ->whereHas('invoice', fn($q) => $q->where('company_id', $companyId));

            if ($request->search) {
                $query->where(function ($q) use ($request) {
                    $q->where('payment_number', 'LIKE', "%{$request->search}%")
                      ->orWhere('reference_number', 'LIKE', "%{$request->search}%")
                      ->orWhereHas('invoice', fn($q2) =>
                          $q2->where('invoice_number', 'LIKE', "%{$request->search}%")
                      );
                });
            }

            if ($request->invoice_id) {
                $query->where('invoice_id', $request->invoice_id);
            }

            if ($request->status) {
                $query->where('status', $request->status);
            }

            if ($request->payment_method) {
                $query->where('payment_method', $request->payment_method);
            }

            if ($request->start_date && $request->end_date) {
                $query->whereBetween('payment_date', [$request->start_date, $request->end_date]);
            }

            $query->orderByRaw('COALESCE(updated_at, created_at) DESC');

            $payments = $query->paginate($request->per_page ?? 15);

            return response()->json(['success' => true, 'data' => $payments], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payments',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * STORE
     * ========================= */
  public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'invoice_id'        => 'required|exists:invoices,invoice_id',
        'payment_type'      => 'nullable|in:dp,installment,full',
        'amount'            => 'required|numeric|min:1',
        'payment_date'      => 'required|date',
        'payment_method'    => 'required|in:cash,transfer,va,ewallet,credit_card,debit_card,cheque,other',
        'bank_name'         => 'nullable|string|max:100',
        'account_number'    => 'nullable|string|max:100',
        'account_holder'    => 'nullable|string|max:255',
        'reference_number'  => 'nullable|string|max:100',
        'gateway_reference' => 'nullable|string|max:255',
        'notes'             => 'nullable|string',
        'proof_file'        => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',

        // ✅ NEW: Bukti potong & PPN
        'withholding_file'    => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        'withholding_amount'  => 'nullable|numeric|min:0',
        'invoice_amount'      => 'nullable|numeric|min:0',
        'payment_amount_net'  => 'nullable|numeric|min:0', // jumlah bayar bersih setelah potong
        'ppn_file'            => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        'ppn_amount'          => 'nullable|numeric|min:0',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();
    try {
        $companyId = $this->getCompanyId($request);
        $invoice   = Invoice::with('payments')
            ->where('company_id', $companyId)
            ->find($request->invoice_id);

        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan'], 404);
        }

        $totalPaidSuccess = $invoice->payments->where('status', 'success')->sum('amount');
        $remainingAmount  = max(0, (float) $invoice->total_amount - $totalPaidSuccess);

        if ((float) $request->amount > $remainingAmount + 0.01) {
            return response()->json([
                'success' => false,
                'message' => 'Jumlah pembayaran melebihi sisa tagihan',
                'data'    => ['remaining_amount' => $remainingAmount, 'requested_amount' => $request->amount],
            ], 422);
        }

        // Upload proof file
        $proofFilePath = null;
        if ($request->hasFile('proof_file')) {
            $file          = $request->file('proof_file');
            $proofFilePath = $file->storeAs('payment_proofs', time() . '_' . $file->getClientOriginalName(), 'public');
        }

        // ✅ Upload bukti potong
        $withholdingFilePath = null;
        if ($request->hasFile('withholding_file')) {
            $file                = $request->file('withholding_file');
            $withholdingFilePath = $file->storeAs('payment_withholding', time() . '_' . $file->getClientOriginalName(), 'public');
        }

        // ✅ Upload PPN
        $ppnFilePath = null;
        if ($request->hasFile('ppn_file')) {
            $file        = $request->file('ppn_file');
            $ppnFilePath = $file->storeAs('payment_ppn', time() . '_' . $file->getClientOriginalName(), 'public');
        }

        // Generate payment number
        $lastPayment   = Payment::orderBy('payment_id', 'desc')->lockForUpdate()->first();
        $nextNumber    = $lastPayment ? (int) substr($lastPayment->payment_number, -4) + 1 : 1;
        $paymentNumber = 'PAY-' . date('Ym') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        $payment = Payment::create([
            'invoice_id'          => $request->invoice_id,
            'payment_number'      => $paymentNumber,
            'payment_type'        => $request->payment_type,
            'amount'              => $request->amount,
            'payment_date'        => $request->payment_date,
            'status'              => 'pending',
            'payment_method'      => $request->payment_method,
            'bank_name'           => $request->bank_name,
            'account_number'      => $request->account_number,
            'account_holder'      => $request->account_holder,
            'reference_number'    => $request->reference_number,
            'gateway_reference'   => $request->gateway_reference,
            'notes'               => $request->notes,
            'proof_file_path'     => $proofFilePath,
            // ✅ NEW
            'withholding_file_path' => $withholdingFilePath,
            'withholding_amount'    => $request->withholding_amount,
            'invoice_amount'        => $request->invoice_amount,
            'payment_amount_net'    => $request->payment_amount_net,
            'ppn_file_path'         => $ppnFilePath,
            'ppn_amount'            => $request->ppn_amount,
            'created_by'            => Auth::id(),
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Payment berhasil dibuat, menunggu approval',
            'data'    => $payment->load(['invoice.customer', 'createdByUser']),
        ], 201);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => 'Gagal membuat payment', 'error' => $e->getMessage()], 500);
    }
}


    /* =========================
     * SHOW
     * ========================= */
    public function show(Request $request, $id)  // ✅ tambah Request
    {
        try {
            $companyId = $this->getCompanyId($request); // ✅

            $payment = Payment::with([
                'invoice.customer',
                'receipt',
                'createdByUser',
                'approvedByUser',
                'cancelledByUser',
            ])
            ->whereHas('invoice', fn($q) => $q->where('company_id', $companyId))
            ->find($id);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment tidak ditemukan',
                ], 404);
            }

            return response()->json(['success' => true, 'data' => $payment], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment',
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

        $payment = Payment::whereHas('invoice', fn($q) => $q->where('company_id', $companyId))
            ->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan',
            ], 404);
        }

        if ($payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya payment dengan status pending yang bisa diupdate',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'payment_type'      => 'nullable|in:dp,installment,full',
            'amount'            => 'nullable|numeric|min:1',
            'payment_date'      => 'nullable|date',
            'payment_method'    => 'nullable|in:cash,transfer,va,ewallet,credit_card,debit_card,cheque,other',
            'bank_name'         => 'nullable|string|max:100',
            'account_number'    => 'nullable|string|max:100',
            'account_holder'    => 'nullable|string|max:255',
            'reference_number'  => 'nullable|string|max:100',
            'gateway_reference' => 'nullable|string|max:255',
            'notes'             => 'nullable|string',
            'proof_file'        => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $updateData = $request->except(['proof_file', 'invoice_id', 'payment_number', 'status']);

            if ($request->hasFile('proof_file')) {
                if ($payment->proof_file_path && Storage::disk('public')->exists($payment->proof_file_path)) {
                    Storage::disk('public')->delete($payment->proof_file_path);
                }
                $file                          = $request->file('proof_file');
                $filename                      = time() . '_' . $file->getClientOriginalName();
                $updateData['proof_file_path'] = $file->storeAs('payment_proofs', $filename, 'public');
            }

            $payment->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Payment berhasil diupdate',
                'data'    => $payment->load(['invoice', 'receipt']),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal update payment',
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

        $payment = Payment::whereHas('invoice', fn($q) => $q->where('company_id', $companyId))
            ->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan',
            ], 404);
        }

        if ($payment->receipt()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa menghapus payment yang sudah memiliki kwitansi',
            ], 409);
        }

        try {
            if ($payment->proof_file_path && Storage::disk('public')->exists($payment->proof_file_path)) {
                Storage::disk('public')->delete($payment->proof_file_path);
            }

            $payment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Payment berhasil dihapus',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus payment',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * APPROVE
     * ========================= */
    public function approve(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $payment = Payment::with('invoice.payments')
            ->whereHas('invoice', fn($q) => $q->where('company_id', $companyId))
            ->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan',
            ], 404);
        }

        if ($payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya payment pending yang bisa di-approve',
            ], 422);
        }

        try {
            DB::beginTransaction();

            $payment->update([
                'status'      => 'success',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'notes'       => $request->notes
                    ? $payment->notes . "\n\nApproval Note: " . $request->notes
                    : $payment->notes,
            ]);

            if ($payment->invoice) {
                $payment->invoice->updatePaymentStatus();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment berhasil di-approve',
                'data'    => $payment->fresh(['invoice.customer', 'receipt']),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal approve payment',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * CANCEL
     * ========================= */
    public function cancel(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $payment = Payment::with('invoice.payments')
            ->whereHas('invoice', fn($q) => $q->where('company_id', $companyId))
            ->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan',
            ], 404);
        }

        if (in_array($payment->status, ['cancelled', 'failed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Payment sudah dibatalkan atau gagal',
            ], 422);
        }

        if ($payment->receipt()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa membatalkan payment yang sudah memiliki kwitansi',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'required|string|min:10',
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

            $payment->update([
                'status'              => 'cancelled',
                'cancelled_by'        => Auth::id(),
                'cancelled_at'        => now(),
                'cancellation_reason' => $request->cancellation_reason,
            ]);

            if ($payment->invoice) {
                $payment->invoice->updatePaymentStatus();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment berhasil dibatalkan',
                'data'    => $payment->fresh(['invoice']),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan payment',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================
     * GENERATE RECEIPT
     * ========================= */
    public function generateReceipt(Request $request, $id)  // ✅ tambah Request
    {
        $companyId = $this->getCompanyId($request); // ✅

        $payment = Payment::whereHas('invoice', fn($q) => $q->where('company_id', $companyId))
            ->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan',
            ], 404);
        }

        $result = $payment->generateReceipt();

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Kwitansi berhasil di-generate',
            'data'    => $result['data'],
        ], 201);
    }

    /* =========================
     * SUMMARY
     * ========================= */
    public function summary(Request $request)
    {
        try {
            $companyId = $this->getCompanyId($request); // ✅

            // ✅ Filter via invoice.company_id
            $base = Payment::where('status', 'success')
                ->whereHas('invoice', fn($q) => $q->where('company_id', $companyId));

            if ($request->start_date && $request->end_date) {
                $base->whereBetween('payment_date', [$request->start_date, $request->end_date]);
            }

            $summary = [
                'total_transactions' => (clone $base)->count(),
                'total_amount'       => (clone $base)->sum('amount'),
                'by_method'          => (clone $base)
                    ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                    ->groupBy('payment_method')
                    ->get(),
            ];

            return response()->json(['success' => true, 'data' => $summary], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get summary',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
