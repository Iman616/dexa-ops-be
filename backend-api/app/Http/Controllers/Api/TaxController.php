<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TaxController extends Controller
{
    /**
     * Display a listing of taxes
     */
    public function index(Request $request)
    {
        $query = Tax::query();

        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination or all
        if ($request->boolean('all')) {
            $taxes = $query->get();
            return response()->json([
                'success' => true,
                'message' => 'Taxes retrieved successfully',
                'data' => $taxes
            ], 200);
        }

        $perPage = $request->get('per_page', 15);
        $taxes = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Taxes retrieved successfully',
            'data' => $taxes
        ], 200);
    }

    /**
     * Get only active taxes (for dropdown)
     */
    public function active()
    {
        $taxes = Tax::active()
            ->orderBy('tax_rate', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Active taxes retrieved successfully',
            'data' => $taxes
        ], 200);
    }

    /**
     * Store a newly created tax
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tax_name' => 'required|string|max:100|unique:taxes,tax_name',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ], [
            'tax_name.required' => 'Nama pajak wajib diisi',
            'tax_name.unique' => 'Nama pajak sudah terdaftar',
            'tax_rate.required' => 'Tarif pajak wajib diisi',
            'tax_rate.min' => 'Tarif pajak minimal 0%',
            'tax_rate.max' => 'Tarif pajak maksimal 100%',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $tax = Tax::create([
                'tax_name' => $request->tax_name,
                'tax_rate' => $request->tax_rate,
                'is_active' => $request->get('is_active', true),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tax created successfully',
                'data' => $tax
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create tax',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified tax
     */
    public function show($id)
    {
        $tax = Tax::find($id);

        if (!$tax) {
            return response()->json([
                'success' => false,
                'message' => 'Tax not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tax retrieved successfully',
            'data' => $tax
        ], 200);
    }

    /**
     * Update the specified tax
     */
    public function update(Request $request, $id)
    {
        $tax = Tax::find($id);

        if (!$tax) {
            return response()->json([
                'success' => false,
                'message' => 'Tax not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'tax_name' => 'sometimes|required|string|max:100|unique:taxes,tax_name,' . $id,
            'tax_rate' => 'sometimes|required|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ], [
            'tax_name.unique' => 'Nama pajak sudah terdaftar',
            'tax_rate.min' => 'Tarif pajak minimal 0%',
            'tax_rate.max' => 'Tarif pajak maksimal 100%',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $tax->update($request->only(['tax_name', 'tax_rate', 'is_active']));

            return response()->json([
                'success' => true,
                'message' => 'Tax updated successfully',
                'data' => $tax
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update tax',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle active status
     */
    public function toggleActive($id)
    {
        $tax = Tax::find($id);

        if (!$tax) {
            return response()->json([
                'success' => false,
                'message' => 'Tax not found'
            ], 404);
        }

        try {
            $tax->toggleActive();

            return response()->json([
                'success' => true,
                'message' => 'Tax status updated successfully',
                'data' => $tax
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update tax status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified tax
     */
    public function destroy($id)
    {
        $tax = Tax::find($id);

        if (!$tax) {
            return response()->json([
                'success' => false,
                'message' => 'Tax not found'
            ], 404);
        }

        // Check if tax is being used (you can add relationships checks here)
        // Example:
        // if ($tax->invoices()->exists()) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Cannot delete tax that is being used in invoices'
        //     ], 409);
        // }

        try {
            $tax->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tax deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete tax',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate tax from base amount
     */
    public function calculate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tax_id' => 'required|exists:taxes,id',
            'base_amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $tax = Tax::find($request->tax_id);
        $baseAmount = $request->base_amount;

        $taxAmount = $tax->calculateTaxAmount($baseAmount);
        $totalWithTax = $tax->calculateTotalWithTax($baseAmount);

        return response()->json([
            'success' => true,
            'message' => 'Tax calculated successfully',
            'data' => [
                'tax_id' => $tax->id,
                'tax_name' => $tax->tax_name,
                'tax_rate' => $tax->tax_rate,
                'tax_rate_formatted' => $tax->tax_rate_formatted,
                'base_amount' => $baseAmount,
                'tax_amount' => round($taxAmount, 2),
                'total_with_tax' => round($totalWithTax, 2),
            ]
        ], 200);
    }
}
