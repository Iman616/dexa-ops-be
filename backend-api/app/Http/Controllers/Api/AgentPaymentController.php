<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentPaymentService;
use App\Http\Requests\StoreAgentPaymentRequest;
use App\Http\Requests\UpdateAgentPaymentRequest;
use App\Http\Requests\RecordAgentPaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


use Illuminate\Http\JsonResponse;

class AgentPaymentController extends Controller
{
    protected $agentPaymentService;

    public function __construct(AgentPaymentService $agentPaymentService)
    {
        $this->agentPaymentService = $agentPaymentService;
    }

    /**
     * List agent payments
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'company_id' => $request->company_id,
                'supplier_id' => $request->supplier_id,
                'status' => $request->status,
                'start_due_date' => $request->start_due_date,
                'end_due_date' => $request->end_due_date,
                'search' => $request->search,
                'per_page' => $request->per_page ?? 15,
            ];

            $payments = $this->agentPaymentService->getAll($filters);

            return response()->json([
                'success' => true,
                'data' => $payments,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch agent payments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create manual agent payment
     */
    public function store(StoreAgentPaymentRequest $request): JsonResponse
    {
        try {
            $payment = $this->agentPaymentService->createManual($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Agent payment created successfully',
                'data' => $payment,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create agent payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create payment from Supplier PO
     */
    public function storeFromSupplierPO(int $supplierPoId, StoreAgentPaymentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $payment = $this->agentPaymentService->createFromSupplierPO($supplierPoId, $data);

            return response()->json([
                'success' => true,
                'message' => 'Agent payment created from Supplier PO',
                'data' => $payment,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create agent payment from Supplier PO',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show single payment
     */
    public function show(int $id): JsonResponse
    {
        try {
            $payment = $this->agentPaymentService->getById($id);

            return response()->json([
                'success' => true,
                'data' => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Agent payment not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Update payment (non-payment fields)
     */
    public function update(UpdateAgentPaymentRequest $request, int $id): JsonResponse
    {
        try {
            $payment = $this->agentPaymentService->update($id, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Agent payment updated successfully',
                'data' => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update agent payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Record payment (bayar ke agent)
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
                'message' => 'Payment recorded successfully',
                'data' => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
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
                'message' => 'Agent invoice file uploaded successfully',
                'data' => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload agent invoice file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete payment
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->agentPaymentService->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Agent payment deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete agent payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $companyId = $request->company_id ??  Auth::user()->company_id;
            $stats = $this->agentPaymentService->getStatistics($companyId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
