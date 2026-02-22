<?php
// app/Http/Controllers/Api/AgentPaymentController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentPaymentService;
use App\Http\Requests\StoreAgentPaymentRequest;
use App\Http\Requests\UpdateAgentPaymentRequest;
use App\Http\Requests\RecordAgentPaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AgentPaymentController extends Controller
{
    protected AgentPaymentService $agentPaymentService;

    public function __construct(AgentPaymentService $agentPaymentService)
    {
        $this->agentPaymentService = $agentPaymentService;
    }

    /**
     * GET /api/agent-payments
     * List dengan filter
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'company_id', 'supplier_id', 'status',
                'start_due_date', 'end_due_date', 'search', 'per_page',
            ]);

            $payments = $this->agentPaymentService->getAll($filters);

            return response()->json([
                'success' => true,
                'data'    => $payments,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data agent payment',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
public function prefillFromPayment(int $paymentId): JsonResponse
{
    try {
        $payment = \App\Models\Payment::with([
            'invoice.purchaseOrder.company',
            'invoice.items',
        ])->findOrFail($paymentId);

        // Hanya bisa buat agent payment jika payment sudah success
        if ($payment->status !== 'success') {
            return response()->json([
                'success' => false,
                'message' => 'Agent payment hanya bisa dibuat setelah payment customer berstatus success',
            ], 422);
        }

        $invoice = $payment->invoice;
        $po      = $invoice?->purchaseOrder;

        // Cek apakah sudah ada agent payment untuk payment ini
        $existingAgentPayment = \App\Models\AgentPayment::where('payment_id', $paymentId)->first();

        return response()->json([
            'success' => true,
            'data'    => [
                // Pre-fill data untuk form
                'payment_id'         => $payment->payment_id,
                'payment_number'     => $payment->payment_number,
                'invoice_number'     => $invoice?->invoice_number,
                'po_number'          => $po?->po_number,
                'company_id'         => $payment->company_id,
                'contract_value'     => (float) $invoice?->total_amount ?? 0,  // Default: total invoice
                'supplier_po_id'     => null,  // User isi manual jika ada SPO
                'notes'              => "Komisi agent untuk Invoice {$invoice?->invoice_number} / Payment {$payment->payment_number}",

                // Info tambahan untuk ditampilkan di modal
                'invoice_total'      => (float) $invoice?->total_amount ?? 0,
                'payment_amount'     => (float) $payment->amount,
                'customer_name'      => $invoice?->customer?->customer_name,

                // Apakah sudah pernah dibuat agent payment untuk payment ini
                'already_created'    => $existingAgentPayment !== null,
                'existing_payment_number' => $existingAgentPayment?->payment_number,
            ],
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data pre-fill',
            'error'   => $e->getMessage(),
        ], 500);
    }
}
    /**
     * POST /api/agent-payments
     * Create manual (tanpa SPO)
     */
    public function store(StoreAgentPaymentRequest $request): JsonResponse
    {
        try {
            $payment = $this->agentPaymentService->createManual($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Agent payment berhasil dibuat',
                'data'    => $payment,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat agent payment',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/agent-payments/from-supplier-po/{supplierPoId}
     * Create dari Supplier PO
     */
    public function storeFromSupplierPO(int $supplierPoId, StoreAgentPaymentRequest $request): JsonResponse
    {
        try {
            $payment = $this->agentPaymentService->createFromSupplierPO($supplierPoId, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Agent payment berhasil dibuat dari Supplier PO',
                'data'    => $payment,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat agent payment dari Supplier PO',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/agent-payments/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $payment = $this->agentPaymentService->getById($id);

            return response()->json([
                'success' => true,
                'data'    => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Agent payment tidak ditemukan',
                'error'   => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * PUT /api/agent-payments/{id}
     * Update info umum (non-payment fields)
     */
    public function update(UpdateAgentPaymentRequest $request, int $id): JsonResponse
    {
        try {
            $payment = $this->agentPaymentService->update($id, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Agent payment berhasil diupdate',
                'data'    => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate agent payment',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ FIX: POST /api/agent-payments/{id}/approve
     * Step 26: Manager approve
     */
    public function approve(int $id): JsonResponse
    {
        try {
            $payment = $this->agentPaymentService->approve($id);

            return response()->json([
                'success' => true,
                'message' => "Agent payment {$payment->payment_number} berhasil di-approve",
                'data'    => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal approve agent payment',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/agent-payments/{id}/pay
     * Step 26: Finance transfer (hanya bisa setelah approved)
     */
    public function recordPayment(RecordAgentPaymentRequest $request, int $id): JsonResponse
    {
        try {
            $data = $request->validated();

            if ($request->hasFile('transfer_proof_file')) {
                $data['transfer_proof_file'] = $request->file('transfer_proof_file');
            }

            $payment = $this->agentPaymentService->recordPayment($id, $data);

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil dicatat',
                'data'    => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencatat pembayaran',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/agent-payments/{id}/upload-invoice
     * Upload agent invoice file
     */
    public function uploadAgentInvoice(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        try {
            $payment = $this->agentPaymentService->uploadAgentInvoiceFile($id, $request->file('file'));

            return response()->json([
                'success' => true,
                'message' => 'File invoice agent berhasil diupload',
                'data'    => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal upload file invoice agent',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/agent-payments/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->agentPaymentService->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Agent payment berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus agent payment',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/agent-payments/statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            // Ambil company_id dari request atau dari session user
            $companyId = $request->company_id ?? Auth::user()->default_company_id;

            if (!$companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'company_id diperlukan',
                ], 422);
            }

            $stats = $this->agentPaymentService->getStatistics((int) $companyId);

            return response()->json([
                'success' => true,
                'data'    => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}