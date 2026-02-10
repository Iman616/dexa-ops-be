<?php
// app/Services/TenderDocumentService.php

namespace App\Services;

use App\Models\TenderDocument;
use App\Models\TenderProjectDetail;
use App\Models\PurchaseOrder;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;


class TenderDocumentService
{
    /**
     * Get all documents with filters
     */
    public function getAll(array $filters = [])
    {
        $query = TenderDocument::with([
            'purchaseOrder:po_id,po_number,customer_id',
            'purchaseOrder.customer:customer_id,customer_name',
            'company:company_id,company_name,company_code',
            'uploadedBy:user_id,name',
            'verifiedBy:user_id,name',
            'approvedBy:user_id,name'
        ]);

        // Filter by PO
        if (!empty($filters['po_id'])) {
            $query->where('po_id', $filters['po_id']);
        }

        // Filter by company
        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        // Filter by document type
        if (!empty($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                  ->orWhereHas('purchaseOrder', function($q2) use ($search) {
                      $q2->where('po_number', 'like', "%{$search}%");
                  });
            });
        }

        // Order
        $query->orderBy('document_date', 'desc');

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get documents by PO
     */
    public function getByPO(int $poId)
    {
        return TenderDocument::with([
            'uploadedBy:user_id,name',
            'verifiedBy:user_id,name',
            'approvedBy:user_id,name'
        ])
        ->where('po_id', $poId)
        ->orderBy('document_date', 'desc')
        ->get()
        ->groupBy('document_type');
    }

    /**
     * Get single document by ID
     */
    public function getById(int $documentId)
    {
        return TenderDocument::with([
            'purchaseOrder.customer',
            'company',
            'uploadedBy',
            'verifiedBy',
            'approvedBy'
        ])->findOrFail($documentId);
    }

    /**
     * Create new document
     */
    public function create(array $data, UploadedFile $file): TenderDocument
    {
        return DB::transaction(function () use ($data, $file) {
            // Validate PO is tender
            $po = PurchaseOrder::findOrFail($data['po_id']);
            
            // Check if PO is tender type (1,7,8,9)
            if (!in_array($po->activity_type_id, [1, 7, 8, 9])) {
                throw new \Exception('PO must be a tender type');
            }

            // Upload file
            $uploadedFile = $this->uploadFile($file, 'tender_documents');

            // Create document
            $document = TenderDocument::create([
                'po_id' => $data['po_id'],
                'company_id' => $data['company_id'],
                'document_type' => $data['document_type'],
                'document_number' => $data['document_number'] ?? null,
                'document_date' => $data['document_date'] ?? now(),
                'file_path' => $uploadedFile['path'],
                'file_name' => $uploadedFile['name'],
                'file_size' => $uploadedFile['size'],
                'mime_type' => $uploadedFile['mime_type'],
                'status' => 'draft',
                'uploaded_by' =>  Auth::id(),
                'uploaded_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // Update tender project details
            $this->updateTenderProjectStatus($data['po_id'], $data['document_type']);

            // Log activity
            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'create',
                'module' => 'tender_documents',
                'record_id' => $document->document_id,
                'description' => "Document uploaded: {$document->type_label} for PO {$po->po_number}",
                'ip_address' => request()->ip(),
            ]);

            return $document;
        });
    }

    /**
     * Update document
     */
    public function update(int $documentId, array $data, ?UploadedFile $file = null): TenderDocument
    {
        return DB::transaction(function () use ($documentId, $data, $file) {
            $document = TenderDocument::findOrFail($documentId);

            // Check if can edit
            if (!$document->canEdit()) {
                throw new \Exception('Cannot edit document with status: ' . $document->status);
            }

            // Update data
            $updateData = [
                'document_number' => $data['document_number'] ?? $document->document_number,
                'document_date' => $data['document_date'] ?? $document->document_date,
                'notes' => $data['notes'] ?? $document->notes,
            ];

            // Upload new file if exists
            if ($file) {
                // Delete old file
                if ($document->file_path) {
                    Storage::disk('public')->delete($document->file_path);
                }
                
                $uploadedFile = $this->uploadFile($file, 'tender_documents');
                $updateData['file_path'] = $uploadedFile['path'];
                $updateData['file_name'] = $uploadedFile['name'];
                $updateData['file_size'] = $uploadedFile['size'];
                $updateData['mime_type'] = $uploadedFile['mime_type'];
            }

            $document->update($updateData);

            // Log activity
            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'update',
                'module' => 'tender_documents',
                'record_id' => $document->document_id,
                'description' => "Document updated: {$document->type_label}",
                'ip_address' => request()->ip(),
            ]);

            return $document->fresh();
        });
    }

    /**
     * Submit document for verification
     */
    public function submit(int $documentId): TenderDocument
    {
        return DB::transaction(function () use ($documentId) {
            $document = TenderDocument::findOrFail($documentId);

            if ($document->status !== 'draft') {
                throw new \Exception('Only draft document can be submitted');
            }

            $document->update([
                'status' => 'submitted',
            ]);

            // Log activity
            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'submit',
                'module' => 'tender_documents',
                'record_id' => $document->document_id,
                'description' => "Document submitted: {$document->type_label}",
                'ip_address' => request()->ip(),
            ]);

            return $document->fresh();
        });
    }

    /**
     * Verify document
     */
    public function verify(int $documentId): TenderDocument
    {
        return DB::transaction(function () use ($documentId) {
            $document = TenderDocument::findOrFail($documentId);

            if (!$document->canVerify()) {
                throw new \Exception('Cannot verify document with status: ' . $document->status);
            }

            $document->update([
                'status' => 'verified',
                'verified_by' =>  Auth::id(),
                'verified_at' => now(),
            ]);

            // Log activity
            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'verify',
                'module' => 'tender_documents',
                'record_id' => $document->document_id,
                'description' => "Document verified: {$document->type_label}",
                'ip_address' => request()->ip(),
            ]);

            return $document->fresh();
        });
    }

    /**
     * Approve document
     */
    public function approve(int $documentId): TenderDocument
    {
        return DB::transaction(function () use ($documentId) {
            $document = TenderDocument::findOrFail($documentId);

            if (!$document->canApprove()) {
                throw new \Exception('Cannot approve document with status: ' . $document->status);
            }

            $document->update([
                'status' => 'approved',
                'approved_by' =>  Auth::id(),
                'approved_at' => now(),
            ]);

            // Update tender project details after approval
            $this->updateTenderProjectStatus($document->po_id, $document->document_type, true);

            // Log activity
            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'approve',
                'module' => 'tender_documents',
                'record_id' => $document->document_id,
                'description' => "Document approved: {$document->type_label}",
                'ip_address' => request()->ip(),
            ]);

            return $document->fresh();
        });
    }

    /**
     * Reject document
     */
    public function reject(int $documentId, string $reason): TenderDocument
    {
        return DB::transaction(function () use ($documentId, $reason) {
            $document = TenderDocument::findOrFail($documentId);

            $document->update([
                'status' => 'rejected',
                'notes' => ($document->notes ? $document->notes . "\n\n" : '') . "Rejected: {$reason}",
            ]);

            // Log activity
            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'reject',
                'module' => 'tender_documents',
                'record_id' => $document->document_id,
                'description' => "Document rejected: {$document->type_label}. Reason: {$reason}",
                'ip_address' => request()->ip(),
            ]);

            return $document->fresh();
        });
    }

    /**
     * Delete document
     */
    public function delete(int $documentId): bool
    {
        return DB::transaction(function () use ($documentId) {
            $document = TenderDocument::findOrFail($documentId);

            // Only draft can be deleted
            if ($document->status !== 'draft') {
                throw new \Exception('Only draft document can be deleted');
            }

            // Delete file
            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }

            $documentType = $document->type_label;
            $poId = $document->po_id;
            $document->delete();

            // Revert tender project status if needed
            $this->revertTenderProjectStatus($poId, $document->document_type);

            // Log activity
            ActivityLog::create([
                'user_id' =>  Auth::id(),
                'action' => 'delete',
                'module' => 'tender_documents',
                'record_id' => $documentId,
                'description' => "Document deleted: {$documentType}",
                'ip_address' => request()->ip(),
            ]);

            return true;
        });
    }

    /**
     * Update tender project status based on document type
     */
    private function updateTenderProjectStatus(int $poId, string $documentType, bool $approved = false): void
    {
        $tenderDetail = TenderProjectDetail::where('po_id', $poId)->first();
        
        if (!$tenderDetail) {
            return;
        }

        $statusMap = [
            'ba_uji_fungsi' => ['has_ba_uji_fungsi', 'ba_uji_fungsi_date', 'ba_done'],
            'bahp' => ['has_bahp', 'bahp_date', 'bahp_done'],
            'bast' => ['has_bast', 'bast_date', 'bast_done'],
            'sp2d' => ['has_sp2d', 'sp2d_date', 'sp2d_done'],
        ];

        if (isset($statusMap[$documentType])) {
            [$hasField, $dateField, $statusValue] = $statusMap[$documentType];
            
            // Only update if approved or auto-approve on upload
            if ($approved) {
                $tenderDetail->update([
                    $hasField => true,
                    $dateField => now(),
                    'project_status' => $statusValue,
                ]);
            }
        }
    }

    /**
     * Revert tender project status when document deleted
     */
    private function revertTenderProjectStatus(int $poId, string $documentType): void
    {
        $tenderDetail = TenderProjectDetail::where('po_id', $poId)->first();
        
        if (!$tenderDetail) {
            return;
        }

        // Check if there are other approved documents of same type
        $hasOtherDocument = TenderDocument::where('po_id', $poId)
            ->where('document_type', $documentType)
            ->where('status', 'approved')
            ->exists();

        if (!$hasOtherDocument) {
            $statusMap = [
                'ba_uji_fungsi' => ['has_ba_uji_fungsi', 'ba_uji_fungsi_date'],
                'bahp' => ['has_bahp', 'bahp_date'],
                'bast' => ['has_bast', 'bast_date'],
                'sp2d' => ['has_sp2d', 'sp2d_date'],
            ];

            if (isset($statusMap[$documentType])) {
                [$hasField, $dateField] = $statusMap[$documentType];
                
                $tenderDetail->update([
                    $hasField => false,
                    $dateField => null,
                ]);

                // Update project_status based on remaining documents
                $this->recalculateProjectStatus($tenderDetail);
            }
        }
    }

    /**
     * Recalculate project status based on completed documents
     */
    private function recalculateProjectStatus(TenderProjectDetail $tenderDetail): void
    {
        if ($tenderDetail->has_sp2d) {
            $status = 'sp2d_done';
        } elseif ($tenderDetail->has_bast) {
            $status = 'bast_done';
        } elseif ($tenderDetail->has_bahp) {
            $status = 'bahp_done';
        } elseif ($tenderDetail->has_ba_uji_fungsi) {
            $status = 'ba_done';
        } else {
            $status = 'ongoing';
        }

        $tenderDetail->update(['project_status' => $status]);
    }

    /**
     * Upload file
     */
    private function uploadFile(UploadedFile $file, string $folder): array
    {
        $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $file->storeAs($folder, $fileName, 'public');
        
        return [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }

    /**
     * Get document statistics for a tender project
     */
    public function getStatistics(int $poId): array
    {
        $documents = TenderDocument::where('po_id', $poId)->get();

        $stats = [
            'total' => $documents->count(),
            'by_type' => [],
            'by_status' => [
                'draft' => 0,
                'submitted' => 0,
                'verified' => 0,
                'approved' => 0,
                'rejected' => 0,
            ],
        ];

        foreach ($documents as $doc) {
            // Count by type
            if (!isset($stats['by_type'][$doc->document_type])) {
                $stats['by_type'][$doc->document_type] = 0;
            }
            $stats['by_type'][$doc->document_type]++;

            // Count by status
            $stats['by_status'][$doc->status]++;
        }

        return $stats;
    }

    /**
     * Get tender progress
     */
    public function getTenderProgress(int $poId): array
    {
        $tenderDetail = TenderProjectDetail::where('po_id', $poId)->first();
        
        if (!$tenderDetail) {
            return [
                'completion_percentage' => 0,
                'steps' => [],
            ];
        }

        $steps = [
            [
                'name' => 'BA Uji Fungsi',
                'completed' => $tenderDetail->has_ba_uji_fungsi,
                'date' => $tenderDetail->ba_uji_fungsi_date,
            ],
            [
                'name' => 'BAHP',
                'completed' => $tenderDetail->has_bahp,
                'date' => $tenderDetail->bahp_date,
            ],
            [
                'name' => 'BAST',
                'completed' => $tenderDetail->has_bast,
                'date' => $tenderDetail->bast_date,
            ],
            [
                'name' => 'SP2D',
                'completed' => $tenderDetail->has_sp2d,
                'date' => $tenderDetail->sp2d_date,
            ],
        ];

        return [
            'completion_percentage' => $tenderDetail->completion_percentage,
            'project_status' => $tenderDetail->project_status,
            'steps' => $steps,
        ];
    }
}
