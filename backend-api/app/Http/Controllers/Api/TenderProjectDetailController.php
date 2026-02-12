<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\TenderProjectDetailService;
use App\Models\TenderProjectDetail;
use App\Models\PurchaseOrder;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TenderProjectDetailController extends Controller
{
    public function __construct(
        protected TenderProjectDetailService $service
    ) {}

    /**
     * Get all tender projects with filters
     * GET /api/tender-projects
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'company_id'         => $request->company_id,
            'project_status'     => $request->project_status,
            'has_bank_guarantee' => $request->has_bank_guarantee,
            'search'             => $request->search,
            'start_date'         => $request->start_date,
            'end_date'           => $request->end_date,
            'per_page'           => $request->per_page ?? 15,
        ];

        $data = $this->service->getAll($filters);

        return response()->json([
            'success' => true,
            'message' => 'Tender projects retrieved successfully',
            'data'    => $data,
        ]);
    }

    public function getTenderPOs(Request $request): JsonResponse
{
    try {
        $companyId = $request->company_id ?? Auth::user()?->company_id;
        
        $pos = PurchaseOrder::with([
            'customer:customer_id,customer_name',
            'activityType:activity_type_id,type_name'
        ])
        ->whereIn('activity_type_id', [1, 7, 8, 9]) // Tender types
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->select('po_id', 'po_number', 'customer_id', 'activity_type_id', 'company_id')
        ->orderBy('po_number', 'desc')
        ->get()
        ->map(fn($po) => [
            'po_id' => $po->po_id,
            'po_number' => $po->po_number,
            'customer_name' => $po->customer->customer_name ?? '-',
            'type_name' => $po->activityType->type_name ?? '-',
            'company_id' => $po->company_id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $pos,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch tender POs',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Create tender project from PO
     * POST /api/tender-projects/from-po/{poId}
     */
    public function storeFromPO(Request $request, int $poId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'contract_number'      => 'nullable|string|max:100',
            'contract_start_date'  => 'nullable|date',
            'contract_end_date'    => 'nullable|date|after_or_equal:contract_start_date',
            'notes'                => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $detail = $this->service->createFromPO($poId, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Tender project detail created from PO',
                'data'    => $detail,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create tender project',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tender project by ID
     * GET /api/tender-projects/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $detail = $this->service->getById($id);

            return response()->json([
                'success' => true,
                'message' => 'Tender project retrieved successfully',
                'data'    => $detail,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tender project not found',
                'error'   => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get tender project by PO ID
     * GET /api/tender-projects/by-po/{poId}
     */
    public function showByPO(int $poId): JsonResponse
    {
        $detail = $this->service->getByPO($poId);

        if (!$detail) {
            return response()->json([
                'success' => false,
                'message' => 'Tender project not found for this PO'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tender project retrieved successfully',
            'data'    => $detail,
        ]);
    }

    /**
     * Update tender project
     * PUT /api/tender-projects/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'contract_number'      => 'nullable|string|max:100',
            'contract_start_date'  => 'nullable|date',
            'contract_end_date'    => 'nullable|date|after_or_equal:contract_start_date',
            'notes'                => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $detail = $this->service->update($id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Tender project updated successfully',
                'data'    => $detail,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update tender project',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NEW: Update specific document status
     * PATCH /api/tender-projects/{id}/documents/{type}
     * 
     * Document types: ba_uji_fungsi, bahp, bast, sp2d
     */
    public function updateDocumentStatus(Request $request, int $id, string $type): JsonResponse
    {
        // Validate document type
        $validTypes = ['ba_uji_fungsi', 'bahp', 'bast', 'sp2d'];
        if (!in_array($type, $validTypes)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid document type. Must be one of: ' . implode(', ', $validTypes)
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|boolean',
            'date'   => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $detail = TenderProjectDetail::findOrFail($id);
            
            $detail->updateDocumentStatus(
                $type,
                $request->boolean('status'),
                $request->date
            );

            return response()->json([
                'success' => true,
                'message' => "Document {$type} status updated successfully",
                'data'    => $detail->fresh([
                    'purchaseOrder',
                    'bankGuarantees',
                    'documents'
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update document status',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tender statistics
     * GET /api/tender-projects/statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $companyId = $request->company_id ?? Auth::user()?->company_id ?? 3;
        
        try {
            $stats = $this->service->getStatistics($companyId);

            return response()->json([
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data'    => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NEW: Mark project as completed
     * POST /api/tender-projects/{id}/complete
     */
    public function complete(int $id): JsonResponse
    {
        try {
            $detail = TenderProjectDetail::findOrFail($id);

            // Check if all documents are completed
            if (!$detail->has_ba_uji_fungsi || !$detail->has_bahp || 
                !$detail->has_bast || !$detail->has_sp2d) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot complete project. All documents must be uploaded and verified first.'
                ], 422);
            }

            $detail->update([
                'project_status' => 'completed'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Project marked as completed successfully',
                'data'    => $detail->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete project',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}