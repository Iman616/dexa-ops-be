<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Services\TenderDocumentService;
use App\Http\Requests\StoreTenderDocumentRequest;
use App\Http\Requests\UpdateTenderDocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TenderDocumentController extends Controller
{
    protected $tenderDocumentService;

    public function __construct(TenderDocumentService $tenderDocumentService)
    {
        $this->tenderDocumentService = $tenderDocumentService;
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

            $documents = $this->tenderDocumentService->getAll($filters);

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
            $documents = $this->tenderDocumentService->getByPO($poId);

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
    public function store(StoreTenderDocumentRequest $request): JsonResponse
    {
        try {
            if (!$request->hasFile('file')) {
                return response()->json([
                    'success' => false,
                    'message' => 'File is required',
                ], 400);
            }

            $document = $this->tenderDocumentService->create(
                $request->validated(),
                $request->file('file')
            );

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded successfully',
                'data' => $document->load(['purchaseOrder', 'company', 'uploadedBy']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified document
     */
    public function show(int $documentId): JsonResponse
    {
        try {
            $document = $this->tenderDocumentService->getById($documentId);

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
            $document = $this->tenderDocumentService->update(
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
            $document = $this->tenderDocumentService->submit($documentId);

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
            $document = $this->tenderDocumentService->verify($documentId);

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
            $document = $this->tenderDocumentService->approve($documentId);

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
            $document = $this->tenderDocumentService->reject($documentId, $request->reason);

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
     * Remove the specified document
     */
    public function destroy(int $documentId): JsonResponse
    {
        try {
            $this->tenderDocumentService->delete($documentId);

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

            $statistics = $this->tenderDocumentService->getStatistics($poId);

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
            $progress = $this->tenderDocumentService->getTenderProgress($poId);

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
            $document = $this->tenderDocumentService->getById($documentId);

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
