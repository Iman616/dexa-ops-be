<?php
// app/Http/Controllers/Api/TenderReportController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenderReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TenderReportController extends Controller
{
    protected $reportService;

    public function __construct(TenderReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    // =========================================================================
    // GET /api/tender-reports/{detailId}/profit-loss
    // =========================================================================
    public function getProfitLoss(string $detailId): JsonResponse
    {
        if (!is_numeric($detailId) || (int)$detailId <= 0) {
            return response()->json(['success' => false, 'message' => 'ID tidak valid'], 400);
        }
        try {
            $result = $this->reportService->calculateTenderProfitLoss((int) $detailId);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghitung P/L',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // GET /api/tender-reports/{detailId}/checklist
    // =========================================================================
    public function getChecklist(string $detailId): JsonResponse
    {
        if (!is_numeric($detailId) || (int)$detailId <= 0) {
            return response()->json(['success' => false, 'message' => 'ID tidak valid'], 400);
        }
        try {
            $result = $this->reportService->getProjectClosingChecklist((int) $detailId);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil checklist',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // GET /api/tender-reports/{detailId}/summary
    // =========================================================================
    public function getSummary(string $detailId): JsonResponse
    {
        if (!is_numeric($detailId) || (int)$detailId <= 0) {
            return response()->json(['success' => false, 'message' => 'ID tidak valid'], 400);
        }
        try {
            $result = $this->reportService->getTenderSummaryReport((int) $detailId);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil ringkasan tender',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // GET /api/tender-reports/all
    // Query params: company_id, status, year
    // =========================================================================
    public function getAllTendersSummary(Request $request): JsonResponse
    {
        try {
            $filters = [
                'company_id' => $request->company_id,
                'status'     => $request->status,
                'year'       => $request->year,
            ];

            $result = $this->reportService->getAllTendersPLSummary($filters);

            return response()->json([
                'success' => true,
                'data'    => $result,
                'total'   => $result->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar tender',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // POST /api/tender-reports/{detailId}/close
    // =========================================================================
    public function closeProject(string $detailId): JsonResponse
    {
        if (!is_numeric($detailId) || (int)$detailId <= 0) {
            return response()->json(['success' => false, 'message' => 'ID tidak valid'], 400);
        }
        try {
            $result = $this->reportService->closeProject((int) $detailId);
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data'    => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menutup proyek',
                'error'   => $e->getMessage(),
            ], 400);
        }
    }

    // =========================================================================
    // ✅ BARU: POST /api/tender-reports/calculate-ppn
    //
    // Kalkulasi DPP & PPN dari inputan user — tanpa perlu tender ID.
    // Berguna untuk simulasi / cek perhitungan sebelum input data.
    //
    // Body JSON:
    // {
    //   "total_amount": 11100000,
    //   "ppn_rate": 11,          // fleksibel: 0, 11, 12, dst
    //   "include_ppn": true      // true = total sudah include PPN
    // }
    //
    // Response:
    // {
    //   "dpp": 10000000,
    //   "ppn_rate": 11,
    //   "ppn_amount": 1100000,
    //   "grand_total": 11100000,
    //   "formula": "DPP = 11100000 ÷ (1 + 11/100) = 10000000"
    // }
    // =========================================================================
    public function calculatePPN(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'total_amount' => 'required|numeric|min:0',
            'ppn_rate'     => 'required|numeric|min:0|max:100',
            'include_ppn'  => 'boolean',
        ]);

        try {
            $result = $this->reportService->recalculatePPN(
                totalAmount: (float) $validated['total_amount'],
                ppnRate    : (float) $validated['ppn_rate'],
                includePPN : (bool)  ($validated['include_ppn'] ?? true),
            );

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghitung PPN',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    // =========================================================================
    // GET /api/tender-reports/{detailId}/export/excel
    // GET /api/tender-reports/{detailId}/export/pdf
    // =========================================================================
    public function exportExcel(string $detailId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Export Excel belum diimplementasikan',
        ], 501);
    }

    public function exportPDF(string $detailId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Export PDF belum diimplementasikan',
        ], 501);
    }
}