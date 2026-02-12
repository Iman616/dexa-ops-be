<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenderDocumentService;
use App\Http\Requests\StoreTenderDocumentRequest;
use App\Http\Requests\UpdateTenderDocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TenderDocumentController extends Controller
{
    protected $service;

    public function __construct(TenderDocumentService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a listing of documents
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'po_id' => $request->po_id,
                'company_id' => $request->company_id,
                'document_type' => $request->document_type,
                'status' => $request->status,
                'search' => $request->search,
                'per_page' => $request->per_page ?? 15,
            ];

            $documents = $this->service->getAll($filters);

            return response()->json([
                'success' => true,
                'data' => $documents,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch documents',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get documents by PO
     */
    public function getByPO(int $poId): JsonResponse
    {
        try {
            $documents = $this->service->getByPO($poId);

            return response()->json([
                'success' => true,
                'data' => $documents,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch documents',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created document
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'po_id' => 'required|exists:purchase_orders,po_id',
            'company_id' => 'required|exists:companies,company_id',
            'document_type' => 'required|in:ba_uji_fungsi,bahp,bast,sp2d,kontrak_asli,kontrak_soft,bukti_ppn,bukti_pph',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'required|date',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:draft,submitted',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $document = $this->service->create(
                $request->all(),
                $request->file('file')
            );

            return response()->json([
                'success' => true,
                'message' => 'Dokumen berhasil diupload',
                'data' => $document,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal upload dokumen',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified document
     */
    public function show(int $documentId): JsonResponse
    {
        try {
            $document = $this->service->getById($documentId);

            return response()->json([
                'success' => true,
                'data' => $document,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Update the specified document
     */
    public function update(UpdateTenderDocumentRequest $request, int $documentId): JsonResponse
    {
        try {
            $document = $this->service->update(
                $documentId,
                $request->validated(),
                $request->file('file')
            );

            return response()->json([
                'success' => true,
                'message' => 'Document updated successfully',
                'data' => $document->load(['purchaseOrder', 'company']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit document for verification
     */
    public function submit(int $documentId): JsonResponse
    {
        try {
            $document = $this->service->submit($documentId);

            return response()->json([
                'success' => true,
                'message' => 'Document submitted successfully',
                'data' => $document,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify document
     */
    public function verify(int $documentId): JsonResponse
    {
        try {
            $document = $this->service->verify($documentId);

            return response()->json([
                'success' => true,
                'message' => 'Document verified successfully',
                'data' => $document,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve document
     */
    public function approve(int $documentId): JsonResponse
    {
        try {
            $document = $this->service->approve($documentId);

            return response()->json([
                'success' => true,
                'message' => 'Document approved successfully',
                'data' => $document,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject document
     */
    public function reject(Request $request, int $documentId): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $document = $this->service->reject($documentId, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Document rejected',
                'data' => $document,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload and auto-approve document
     */
    public function uploadAndApprove(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'po_id' => 'required|exists:purchase_orders,po_id',
            'company_id' => 'required|exists:companies,company_id',
            'document_type' => 'required|in:ba_uji_fungsi,bahp,bast,sp2d,kontrak_asli,kontrak_soft,bukti_ppn,bukti_pph',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'required|date',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // 10MB
            'notes' => 'nullable|string',
            'auto_approve' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $autoApprove = $request->boolean('auto_approve', true); // Default true
            
            $document = $this->service->uploadAndApprove(
                $request->all(),
                $request->file('file'),
                $autoApprove
            );

            $message = $autoApprove 
                ? "✅ {$document->type_label} berhasil diupload & approved! Progress updated."
                : "Dokumen {$document->type_label} berhasil diupload. Menunggu approval.";

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $document,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal upload dokumen',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified document
     */
    public function destroy(int $documentId): JsonResponse
    {
        try {
            $this->service->delete($documentId);

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get document statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $poId = $request->po_id;
            
            if (!$poId) {
                return response()->json([
                    'success' => false,
                    'message' => 'PO ID is required',
                ], 400);
            }

            $statistics = $this->service->getStatistics($poId);

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get tender progress
     */
    public function progress(int $poId): JsonResponse
    {
        try {
            $progress = $this->service->getTenderProgress($poId);

            return response()->json([
                'success' => true,
                'data' => $progress,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch progress',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download document file
     */
    public function download(int $documentId): mixed
    {
        try {
            $document = $this->service->getById($documentId);

            if (!$document->file_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found',
                ], 404);
            }

            $filePath = storage_path('app/public/' . $document->file_path);

            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found on server',
                ], 404);
            }

            return response()->download($filePath, $document->file_name);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to download file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}