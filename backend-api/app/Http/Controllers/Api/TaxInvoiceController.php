<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Services\TaxInvoiceService;
use App\Models\TaxInvoice;
use Illuminate\Support\Facades\Storage;

use App\Http\Requests\StoreTaxInvoiceRequest;

use App\Http\Requests\UpdateTaxInvoiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TaxInvoiceController extends Controller
{
    protected $taxInvoiceService;

    public function __construct(TaxInvoiceService $taxInvoiceService)
    {
        $this->taxInvoiceService = $taxInvoiceService;
    }

    /**
     * Display a listing of tax invoices
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'company_id' => $request->company_id,
                'status' => $request->status,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'search' => $request->search,
                'per_page' => $request->per_page ?? 15,
            ];

            $taxInvoices = $this->taxInvoiceService->getAll($filters);

            return response()->json([
                'success' => true,
                'data' => $taxInvoices,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tax invoices',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created tax invoice
     */
    public function store(StoreTaxInvoiceRequest $request): JsonResponse
    {
        try {
            $taxInvoice = $this->taxInvoiceService->create(
                $request->validated(),
                $request->file('file')
            );

            return response()->json([
                'success' => true,
                'message' => 'Tax invoice created successfully',
                'data' => $taxInvoice->load(['company', 'invoice', 'createdBy']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create tax invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
 * Upload / replace file faktur pajak (semua status)
 * POST /api/tax-invoices/{id}/upload
 */
public function uploadFile(Request $request, int $taxInvoiceId): JsonResponse
{
    $request->validate([
        'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
    ]);

    try {
        $taxInvoice = $this->taxInvoiceService->uploadFile(
            $taxInvoiceId,
            $request->file('file')
        );

        return response()->json([
            'success' => true,
            'message' => 'File faktur pajak berhasil diupload',
            'data'    => [
                'tax_invoice_id' => $taxInvoice->tax_invoice_id,
                'file_path'      => $taxInvoice->file_path,
                'file_name'      => $taxInvoice->file_name,
                'status'         => $taxInvoice->status,
            ],
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal upload file',
            'error'   => $e->getMessage(),
        ], 500);
    }
}


    /**
     * Display the specified tax invoice
     */
    public function show(int $taxInvoiceId): JsonResponse
    {
        try {
            $taxInvoice = $this->taxInvoiceService->getById($taxInvoiceId);

            return response()->json([
                'success' => true,
                'data' => $taxInvoice,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tax invoice not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Update the specified tax invoice
     */
    public function update(UpdateTaxInvoiceRequest $request, int $taxInvoiceId): JsonResponse
    {
        try {
            $taxInvoice = $this->taxInvoiceService->update(
                $taxInvoiceId,
                $request->validated(),
                $request->file('file')
            );

            return response()->json([
                'success' => true,
                'message' => 'Tax invoice updated successfully',
                'data' => $taxInvoice->load(['company', 'invoice']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update tax invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit tax invoice for approval
     */
    public function submit(int $taxInvoiceId): JsonResponse
    {
        try {
            $taxInvoice = $this->taxInvoiceService->submit($taxInvoiceId);

            return response()->json([
                'success' => true,
                'message' => 'Tax invoice submitted successfully',
                'data' => $taxInvoice,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit tax invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve tax invoice
     */
    public function approve(Request $request, int $taxInvoiceId): JsonResponse
{
    $request->validate([
        'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
    ]);

    try {
        // 1) upload/replace file dulu
        $taxInvoice = $this->taxInvoiceService->uploadFile(
            $taxInvoiceId,
            $request->file('file')
        );

        // 2) baru approve
        $taxInvoice = $this->taxInvoiceService->approve($taxInvoiceId);

        return response()->json([
            'success' => true,
            'message' => 'Tax invoice approved successfully',
            'data'    => $taxInvoice,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to approve tax invoice',
            'error'   => $e->getMessage(),
        ], 500);
    }
}


    /**
     * Reject tax invoice
     */
    public function reject(Request $request, int $taxInvoiceId): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $taxInvoice = $this->taxInvoiceService->reject($taxInvoiceId, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Tax invoice rejected',
                'data' => $taxInvoice,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject tax invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified tax invoice
     */
    public function destroy(int $taxInvoiceId): JsonResponse
    {
        try {
            $this->taxInvoiceService->delete($taxInvoiceId);

            return response()->json([
                'success' => true,
                'message' => 'Tax invoice deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete tax invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get tax invoice statistics
     */
public function statistics(Request $request): JsonResponse
{
    try {
        $user = $request->user();

        $companyId = $request->company_id ?? $user?->company_id;

        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Company ID is required',
            ], 400);
        }

        $year = $request->year ?? date('Y');

        $statistics = $this->taxInvoiceService->getStatistics($companyId, $year);

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
     * Download tax invoice file
     */
   public function download($id)
{
    try {
        $taxInvoice = TaxInvoice::findOrFail($id);

        if (!$taxInvoice->file_path) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan'
            ], 404);
        }

        $fullPath = Storage::disk('public')->path($taxInvoice->file_path);

        if (!file_exists($fullPath)) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan di storage'
            ], 404);
        }

        // ✅ FIX: Sanitize filename — hapus / dan \
        $downloadName = $taxInvoice->file_name
            ? $this->sanitizeFilename($taxInvoice->file_name)
            : 'faktur_pajak_' . $taxInvoice->tax_invoice_number . '.pdf';

        return response()->download($fullPath, $downloadName);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal download file',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Sanitize filename untuk download — hapus karakter illegal
 */
private function sanitizeFilename(string $filename): string
{
    // Hapus / dan \ serta karakter illegal lain
    $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $filename);

    // Hapus spasi berlebihan
    $filename = preg_replace('/\s+/', ' ', $filename);

    // Trim
    return trim($filename);
}

}
