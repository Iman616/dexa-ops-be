<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupplierInvoiceService;
use Illuminate\Http\Request;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class SupplierInvoiceController extends Controller
{
    protected $supplierInvoiceService;

    public function __construct(SupplierInvoiceService $supplierInvoiceService)
    {
        $this->supplierInvoiceService = $supplierInvoiceService;
    }

    // ========== SUPPLIER INVOICES ==========

    /**
     * Get all supplier invoices with filters
     */
    public function indexInvoices(Request $request)
    {
        try {
            $filters = $request->only([
                'search',
                'payment_status',
                'supplier_id',
                'overdue',
                'date_from',
                'date_to',
                'sort_by',
                'sort_order',
                'per_page',
                'page'
            ]);

            $invoices = $this->supplierInvoiceService->getInvoices($filters);

            return response()->json([
                'success' => true,
                'data' => $invoices->items(),
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data invoice supplier: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single supplier invoice
     */
    public function showInvoice($id)
    {
        try {
            $invoice = $this->supplierInvoiceService->getInvoice($id);

            return response()->json([
                'success' => true,
                'data' => $invoice
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan'
            ], 404);
        }
    }

    /**
     * Create new supplier invoice
     */
    public function storeInvoice(Request $request)
    {
        // ✅ FIX: Sesuaikan dengan database snake_case
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,supplier_id',
            'supplier_po_id' => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'invoice_number' => 'required|string|unique:supplierinvoices,invoice_number|max:100',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'payment_terms' => 'nullable|in:cash,net7,net14,net30,net60,custom',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:50',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'invoice_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Upload file jika ada
            $invoiceFilePath = null;
            if ($request->hasFile('invoice_file')) {
                $file = $request->file('invoice_file');
                $fileName = 'invoice_' . time() . '_' . $file->getClientOriginalName();
                $invoiceFilePath = $file->storeAs('supplierinvoices', $fileName, 'public');
            }

            // Hitung total
            $totalAmount = 0;
            foreach ($request->items as $item) {
                $subtotal = $item['quantity'] * $item['unit_price'];
                $totalAmount += $subtotal;
            }

            // ✅ Buat invoice dengan snake_case
            $invoice = SupplierInvoice::create([
                'supplier_id' => $request->supplier_id,
                'supplier_po_id' => $request->supplier_po_id,
                'invoice_number' => $request->invoice_number,
                'invoice_date' => $request->invoice_date,
                'due_date' => $request->due_date,
                'payment_terms' => $request->payment_terms ?? 'net30',
                'total_amount' => $totalAmount,
                'paid_amount' => 0,
                'payment_status' => 'unpaid',
                'invoice_file_path' => $invoiceFilePath,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
            ]);

            // Buat items
            foreach ($request->items as $item) {
                SupplierInvoiceItem::create([
                    'supplier_invoice_id' => $invoice->supplier_invoice_id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            // Load relationships
            $invoice->load(['supplier', 'items', 'supplierPO', 'createdByUser']);

            return response()->json([
                'success' => true,
                'message' => 'Invoice berhasil dibuat',
                'data' => $invoice
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat invoice: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update supplier invoice
     */
    public function updateInvoice(Request $request, $id)
    {
        // ✅ FIX validation
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,supplier_id',
            'supplier_po_id' => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'invoice_number' => 'required|string|max:100|unique:supplierinvoices,invoice_number,' . $id . ',supplier_invoice_id',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'payment_terms' => 'nullable|in:cash,net7,net14,net30,net60,custom',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:50',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'invoice_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $invoice = SupplierInvoice::findOrFail($id);

            // Cek apakah sudah ada pembayaran
            if ($invoice->paid_amount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice tidak dapat diubah karena sudah ada pembayaran'
                ], 400);
            }

            // Upload file baru jika ada
            $invoiceFilePath = $invoice->invoice_file_path;
            if ($request->hasFile('invoice_file')) {
                if ($invoiceFilePath) {
                    Storage::disk('public')->delete($invoiceFilePath);
                }
                $file = $request->file('invoice_file');
                $fileName = 'invoice_' . time() . '_' . $file->getClientOriginalName();
                $invoiceFilePath = $file->storeAs('supplierinvoices', $fileName, 'public');
            }

            // Hitung total baru
            $totalAmount = 0;
            foreach ($request->items as $item) {
                $subtotal = $item['quantity'] * $item['unit_price'];
                $totalAmount += $subtotal;
            }

            // ✅ Update invoice dengan snake_case
            $invoice->update([
                'supplier_id' => $request->supplier_id,
                'supplier_po_id' => $request->supplier_po_id,
                'invoice_number' => $request->invoice_number,
                'invoice_date' => $request->invoice_date,
                'due_date' => $request->due_date,
                'payment_terms' => $request->payment_terms,
                'total_amount' => $totalAmount,
                'invoice_file_path' => $invoiceFilePath,
                'notes' => $request->notes,
            ]);

            // Hapus items lama
            SupplierInvoiceItem::where('supplier_invoice_id', $invoice->supplier_invoice_id)->delete();

            // Buat items baru
            foreach ($request->items as $item) {
                SupplierInvoiceItem::create([
                    'supplier_invoice_id' => $invoice->supplier_invoice_id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $invoice->load(['supplier', 'items', 'supplierPO', 'createdByUser']);

            return response()->json([
                'success' => true,
                'message' => 'Invoice berhasil diupdate',
                'data' => $invoice
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate invoice: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete supplier invoice
     */
    public function destroyInvoice($id)
    {
        try {
            $this->supplierInvoiceService->deleteInvoice($id);

            return response()->json([
                'success' => true,
                'message' => 'Invoice berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // ========== SUPPLIER PAYMENTS ==========

    /**
     * Get all supplier payments with filters
     */
    public function indexPayments(Request $request)
    {
        try {
            $filters = $request->only([
                'search',
                'status',
                'payment_type',
                'date_from',
                'date_to',
                'sort_by',
                'sort_order',
                'per_page',
                'page'
            ]);

            $payments = $this->supplierInvoiceService->getPayments($filters);

            return response()->json([
                'success' => true,
                'data' => $payments->items(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pembayaran: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single payment
     */
    public function showPayment($id)
    {
        try {
            $payment = $this->supplierInvoiceService->getPayment($id);

            return response()->json([
                'success' => true,
                'data' => $payment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pembayaran tidak ditemukan'
            ], 404);
        }
    }

    /**
     * Create new payment
     */
    public function storePayment(Request $request)
    {
        // ✅ FIX validation
        $validator = Validator::make($request->all(), [
            'supplier_invoice_id' => 'required|exists:supplierinvoices,supplier_invoice_id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_type' => 'required|in:dp,partial,full',
            'status' => 'required|in:pending,success,failed,cancelled',
            'payment_method' => 'required|in:cash,transfer,giro,cek,other',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $invoice = SupplierInvoice::findOrFail($request->supplier_invoice_id);

            // Calculate remaining
            $remainingAmount = $invoice->total_amount - $invoice->paid_amount;

            // Validasi amount tidak melebihi sisa
            if ($request->amount > $remainingAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jumlah pembayaran melebihi sisa tagihan'
                ], 400);
            }

            // Upload proof jika ada
            $proofFilePath = null;
            if ($request->hasFile('proof_file')) {
                $file = $request->file('proof_file');
                $fileName = 'payment_' . time() . '_' . $file->getClientOriginalName();
                $proofFilePath = $file->storeAs('supplier_payments', $fileName, 'public');
            }

            // Generate payment number
            $lastPayment = SupplierPayment::latest('supplier_payment_id')->first();
            $nextNumber = $lastPayment ? (int) substr($lastPayment->payment_number, -4) + 1 : 1;
            $paymentNumber = 'SPAY-' . date('Ym') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            // ✅ Buat payment dengan snake_case
            $payment = SupplierPayment::create([
                'supplier_invoice_id' => $request->supplier_invoice_id,
                'payment_number' => $paymentNumber,
                'payment_type' => $request->payment_type,
                'amount' => $request->amount,
                'payment_date' => $request->payment_date,
                'status' => $request->status,
                'payment_method' => $request->payment_method,
                'bank_name' => $request->bank_name,
                'account_number' => $request->account_number,
                'reference_number' => $request->reference_number,
                'proof_file_path' => $proofFilePath,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
            ]);

            // Update invoice jika status payment success
            if ($request->status === 'success') {
                $this->updateInvoicePaymentStatus($invoice, $request->amount);
            }

            DB::commit();

            $payment->load(['invoice.supplier', 'createdByUser']);

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil dicatat',
                'data' => $payment
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencatat pembayaran: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update invoice payment status (HELPER METHOD)
     */
    private function updateInvoicePaymentStatus($invoice, $paymentAmount)
    {
        $newPaidAmount = $invoice->paid_amount + $paymentAmount;
        $newRemainingAmount = $invoice->total_amount - $newPaidAmount;

        // Tentukan status pembayaran
        $paymentStatus = 'unpaid';
        if ($newRemainingAmount <= 0) {
            $paymentStatus = 'paid';
            $newPaidAmount = $invoice->total_amount;
        } elseif ($newPaidAmount > 0) {
            $paymentStatus = 'partial';
        }

        // ✅ Update invoice dengan snake_case
        $invoice->update([
            'paid_amount' => $newPaidAmount,
            'payment_status' => $paymentStatus,
        ]);

        return $invoice;
    }

    /**
     * Approve payment
     */
    public function approvePayment($id)
    {
        try {
            $payment = $this->supplierInvoiceService->approvePayment($id, Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil diapprove',
                'data' => $payment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Cancel payment
     */
    public function cancelPayment(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Alasan pembatalan harus diisi',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $payment = $this->supplierInvoiceService->cancelPayment($id, $request->reason, Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil dibatalkan',
                'data' => $payment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Delete payment
     */
    public function destroyPayment($id)
    {
        try {
            $this->supplierInvoiceService->deletePayment($id);

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // ========== DASHBOARD & REPORTS ==========

    /**
     * Get dashboard statistics
     */
    public function getDashboardStats()
    {
        try {
            $stats = $this->supplierInvoiceService->getDashboardStats();

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download invoice file
     */
    public function downloadInvoiceFile($id)
    {
        try {
            $invoice = $this->supplierInvoiceService->getInvoice($id);

            if (!$invoice->invoice_file_path || !Storage::disk('public')->exists($invoice->invoice_file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File invoice tidak ditemukan'
                ], 404);
            }

            return Storage::disk('public')->download($invoice->invoice_file_path);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal download file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download payment proof
     */
    public function downloadPaymentProof($id)
    {
        try {
            $payment = $this->supplierInvoiceService->getPayment($id);

            if (!$payment->proof_file_path || !Storage::disk('public')->exists($payment->proof_file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File bukti pembayaran tidak ditemukan'
                ], 404);
            }

            return Storage::disk('public')->download($payment->proof_file_path);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal download file: ' . $e->getMessage()
            ], 500);
        }
    }
}
