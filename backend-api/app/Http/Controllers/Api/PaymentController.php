<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
     /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $query = Payment::with([
                'invoice.customer',
                'receipt',
                'createdByUser:user_id,full_name',
                'approvedByUser:user_id,full_name',
                'cancelledByUser:user_id,full_name'
            ]);

            // Filters
            if ($request->search) {
                $query->where(function($q) use ($request) {
                    $q->where('payment_number', 'LIKE', "%{$request->search}%")
                      ->orWhere('reference_number', 'LIKE', "%{$request->search}%")
                      ->orWhereHas('invoice', function($q2) use ($request) {
                          $q2->where('invoice_number', 'LIKE', "%{$request->search}%");
                      });
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

            // Sorting
            $sortBy = $request->sort_by ?? 'payment_date';
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->per_page ?? 15;
            $payments = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $payments
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // ✅ FIX: Update validation rules
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|exists:invoices,invoice_id',
            'payment_type' => 'nullable|in:dp,installment,full',
            'amount' => 'required|numeric|min:1', // ✅ CHANGE: amount_paid → amount
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:cash,transfer,va,ewallet,credit_card,debit_card,cheque,other', // ✅ ADD: valid methods
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:100',
            'account_holder' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'gateway_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'proof_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', // Max 5MB
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
            // Get invoice
            $invoice = Invoice::find($request->invoice_id);
            
            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice tidak ditemukan'
                ], 404);
            }

            // Check amount
            if ($request->amount > $invoice->remaining_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jumlah pembayaran melebihi sisa tagihan',
                    'data' => [
                        'remaining_amount' => $invoice->remaining_amount,
                        'requested_amount' => $request->amount
                    ]
                ], 422);
            }

            // Handle file upload
            $proofFilePath = null;
            if ($request->hasFile('proof_file')) {
                $file = $request->file('proof_file');
                $filename = time() . '_' . $file->getClientOriginalName();
                $proofFilePath = $file->storeAs('payment_proofs', $filename, 'public');
            }

            // ✅ Auto-generate payment number
            $lastPayment = Payment::whereNotNull('payment_number')
                ->orderBy('payment_id', 'desc')
                ->first();
            
            $nextNumber = $lastPayment ? (int)substr($lastPayment->payment_number, -4) + 1 : 1;
            $paymentNumber = 'PAY-' . date('Ym') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            // Create payment
            $payment = Payment::create([
                'invoice_id' => $request->invoice_id,
                'payment_number' => $paymentNumber, // ✅ AUTO-GENERATED
                'payment_type' => $request->payment_type,
                'amount' => $request->amount, // ✅ CHANGE: amount_paid → amount
                'payment_date' => $request->payment_date,
                'status' => 'pending', // ✅ Default pending
                'payment_method' => $request->payment_method,
                'bank_name' => $request->bank_name,
                'account_number' => $request->account_number,
                'account_holder' => $request->account_holder,
                'reference_number' => $request->reference_number,
                'gateway_reference' => $request->gateway_reference,
                'notes' => $request->notes,
                'proof_file_path' => $proofFilePath,
                'created_by' => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment berhasil dibuat',
                'data' => $payment->load(['invoice.customer', 'createdByUser'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $payment = Payment::with([
                'invoice.customer',
                'receipt',
                'createdByUser',
                'approvedByUser',
                'cancelledByUser'
            ])->find($id);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment tidak ditemukan'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $payment
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan'
            ], 404);
        }

        // Only pending payments can be updated
        if ($payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya payment dengan status pending yang bisa diupdate'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'payment_type' => 'nullable|in:dp,installment,full',
            'amount' => 'nullable|numeric|min:1',
            'payment_date' => 'nullable|date',
            'payment_method' => 'nullable|in:cash,transfer,va,ewallet,credit_card,debit_card,cheque,other',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:100',
            'account_holder' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'gateway_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'proof_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updateData = $request->except(['proof_file', 'invoice_id', 'payment_number']);

            // Handle file upload
            if ($request->hasFile('proof_file')) {
                // Delete old file if exists
                if ($payment->proof_file_path && Storage::disk('public')->exists($payment->proof_file_path)) {
                    Storage::disk('public')->delete($payment->proof_file_path);
                }

                $file = $request->file('proof_file');
                $filename = time() . '_' . $file->getClientOriginalName();
                $updateData['proof_file_path'] = $file->storeAs('payment_proofs', $filename, 'public');
            }

            $payment->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Payment berhasil diupdate',
                'data' => $payment->load(['invoice', 'receipt'])
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal update payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan'
            ], 404);
        }

        // Cannot delete if receipt exists
        if ($payment->receipt()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa menghapus payment yang sudah memiliki kwitansi'
            ], 409);
        }

        try {
            // Delete proof file if exists
            if ($payment->proof_file_path && Storage::disk('public')->exists($payment->proof_file_path)) {
                Storage::disk('public')->delete($payment->proof_file_path);
            }

            $payment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Payment berhasil dihapus'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve payment (change status to success)
     */
    public function approve(Request $request, $id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan'
            ], 404);
        }

        if ($payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya payment pending yang bisa di-approve'
            ], 422);
        }

        try {
            $payment->update([
                'status' => 'success',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'notes' => $request->notes ? $payment->notes . "\n\nApproval Note: " . $request->notes : $payment->notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment berhasil di-approve',
                'data' => $payment->load(['invoice', 'receipt'])
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal approve payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel payment
     */
    public function cancel(Request $request, $id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan'
            ], 404);
        }

        if (in_array($payment->status, ['cancelled', 'failed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Payment sudah dibatalkan atau gagal'
            ], 422);
        }

        if ($payment->receipt()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa membatalkan payment yang sudah memiliki kwitansi'
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'required|string|min:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $payment->update([
                'status' => 'cancelled',
                'cancelled_by' => Auth::id(),
                'cancelled_at' => now(),
                'cancellation_reason' => $request->cancellation_reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment berhasil dibatalkan',
                'data' => $payment->load(['invoice'])
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate receipt from payment
     */
    public function generateReceipt($id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment tidak ditemukan'
            ], 404);
        }

        $result = $payment->generateReceipt();

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Kwitansi berhasil di-generate',
            'data' => $result['data']
        ], 201);
    }

    /**
     * Get payment summary
     */
    public function summary(Request $request)
    {
        try {
            $query = Payment::where('status', 'success');

            if ($request->start_date && $request->end_date) {
                $query->whereBetween('payment_date', [$request->start_date, $request->end_date]);
            }

            $summary = [
                'total_transactions' => $query->count(),
                'total_amount' => $query->sum('amount'),
                'by_method' => $query->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                    ->groupBy('payment_method')
                    ->get()
            ];

            return response()->json([
                'success' => true,
                'data' => $summary
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
