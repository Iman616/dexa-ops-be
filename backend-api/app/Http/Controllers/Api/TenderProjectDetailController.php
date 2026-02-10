<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\TenderProjectDetailService;
use Illuminate\Support\Facades\Auth;


class TenderProjectDetailController extends Controller
{
    public function __construct(
        protected TenderProjectDetailService $service
    ) {}

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
            'data'    => $data,
        ]);
    }

    public function storeFromPO(Request $request, int $poId): JsonResponse
    {
        $request->validate([
            'contract_number'      => 'nullable|string|max:100',
            'contract_start_date'  => 'nullable|date',
            'contract_end_date'    => 'nullable|date|after_or_equal:contract_start_date',
            'notes'                => 'nullable|string',
        ]);

        $detail = $this->service->createFromPO($poId, $request->all());

        return response()->json([
            'success' => true,
            'message' => 'Tender project detail initialized from PO',
            'data'    => $detail,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $detail = $this->service->getById($id);

        return response()->json([
            'success' => true,
            'data'    => $detail,
        ]);
    }

    public function showByPO(int $poId): JsonResponse
    {
        $detail = $this->service->getByPO($poId);

        return response()->json([
            'success' => true,
            'data'    => $detail,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'contract_number'      => 'nullable|string|max:100',
            'contract_start_date'  => 'nullable|date',
            'contract_end_date'    => 'nullable|date|after_or_equal:contract_start_date',
            'notes'                => 'nullable|string',
        ]);

        $detail = $this->service->update($id, $request->all());

        return response()->json([
            'success' => true,
            'message' => 'Tender project detail updated successfully',
            'data'    => $detail,
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $companyId = $request->company_id ??  Auth::user()?->company_id ?? 3;
        $stats = $this->service->getStatistics($companyId);

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }
}
