<?php
// app/Http/Controllers/Api/BankGuaranteeController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankGuaranteeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BankGuaranteeController extends Controller
{
    public function __construct(
        protected BankGuaranteeService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'company_id'      => $request->company_id,
            'po_id'           => $request->po_id,
            'guarantee_type'  => $request->guarantee_type,
            'status'          => $request->status,
            'search'          => $request->search,
            'per_page'        => $request->per_page ?? 15,
        ];

        $data = $this->service->list($filters);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $bg = $this->service->getById($id);

        return response()->json([
            'success' => true,
            'data'    => $bg,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'po_id'                => 'required|integer|exists:purchase_orders,po_id',
            'company_id'           => 'nullable|integer|exists:companies,company_id',
            'guarantee_type'       => 'required|in:jampel,jamuk',
            'bank_name'            => 'required|string|max:150',
            'bank_branch'          => 'nullable|string|max:150',
            'guarantee_number'     => 'required|string|max:100',
            'guarantee_amount'     => 'required|numeric|min:0',
            'guarantee_percentage' => 'nullable|numeric|min:0',
            'issue_date'           => 'required|date',
            'expiry_date'          => 'required|date|after_or_equal:issue_date',
            'return_date'          => 'nullable|date',
            'admin_fee'            => 'nullable|numeric|min:0',
            'collateral_fee'       => 'nullable|numeric|min:0',
            'status'               => 'nullable|in:active,returned,expired,claimed',
            'notes'                => 'nullable|string',
            'file'                 => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $bg = $this->service->create($validator->validated(), $request->file('file'));

        return response()->json([
            'success' => true,
            'message' => 'Bank guarantee created successfully',
            'data'    => $bg,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'guarantee_type'       => 'sometimes|in:jampel,jamuk',
            'bank_name'            => 'sometimes|string|max:150',
            'bank_branch'          => 'sometimes|nullable|string|max:150',
            'guarantee_number'     => 'sometimes|string|max:100',
            'guarantee_amount'     => 'sometimes|numeric|min:0',
            'guarantee_percentage' => 'sometimes|nullable|numeric|min:0',
            'issue_date'           => 'sometimes|date',
            'expiry_date'          => 'sometimes|date',
            'return_date'          => 'sometimes|nullable|date',
            'admin_fee'            => 'sometimes|nullable|numeric|min:0',
            'collateral_fee'       => 'sometimes|nullable|numeric|min:0',
            'status'               => 'sometimes|in:active,returned,expired,claimed',
            'notes'                => 'sometimes|nullable|string',
            'file'                 => 'sometimes|nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $bg = $this->service->update($id, $validator->validated(), $request->file('file'));

        return response()->json([
            'success' => true,
            'message' => 'Bank guarantee updated successfully',
            'data'    => $bg,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json([
            'success' => true,
            'message' => 'Bank guarantee deleted successfully',
        ]);
    }

    public function markReturned(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'return_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $bg = $this->service->markReturned($id, $request->return_date);

        return response()->json([
            'success' => true,
            'message' => 'Bank guarantee marked as returned',
            'data'    => $bg,
        ]);
    }

    public function download(int $id)
    {
        return $this->service->download($id);
    }
}
