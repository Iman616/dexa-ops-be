<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSupplier;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProductSupplierController extends Controller
{
    /**
     * Display a listing of product suppliers
     * GET /api/product-suppliers
     */
    public function index(Request $request)
    {
        $query = ProductSupplier::with(['product', 'supplier', 'company']);

        // Filter by company
        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by supplier
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter active only
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Filter primary only
        if ($request->boolean('primary_only')) {
            $query->primary();
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('product', function($pq) use ($search) {
                    $pq->where('product_name', 'like', "%{$search}%")
                      ->orWhere('product_code', 'like', "%{$search}%");
                })
                ->orWhereHas('supplier', function($sq) use ($search) {
                    $sq->where('supplier_name', 'like', "%{$search}%");
                });
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $productSuppliers = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Product suppliers retrieved successfully',
            'data' => $productSuppliers
        ], 200);
    }

    /**
     * Get suppliers for specific product
     * GET /api/product-suppliers/for-product/{product_id}
     */
    public function getSuppliersForProduct($productId, Request $request)
    {
        $validator = Validator::make(array_merge($request->all(), ['product_id' => $productId]), [
            'product_id' => 'required|exists:products,product_id',
            'company_id' => 'required|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $suppliers = ProductSupplier::with('supplier')
            ->where('product_id', $productId)
            ->where('company_id', $request->company_id)
            ->active()
            ->orderByRaw('is_primary DESC') // Primary dulu
            ->get();

        return response()->json([
            'success' => true,
            'data' => $suppliers
        ], 200);
    }

    /**
     * Store a newly created product supplier
     * POST /api/product-suppliers
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,product_id',
            'supplier_id' => 'required|exists:suppliers,supplier_id',
            'company_id' => 'required|exists:companies,company_id',
            'purchase_price' => 'nullable|numeric|min:0',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Check duplicate
            $exists = ProductSupplier::where('product_id', $request->product_id)
                ->where('supplier_id', $request->supplier_id)
                ->where('company_id', $request->company_id)
                ->first();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'This supplier is already assigned to this product for this company'
                ], 422);
            }

            $productSupplier = ProductSupplier::create([
                'product_id' => $request->product_id,
                'supplier_id' => $request->supplier_id,
                'company_id' => $request->company_id,
                'purchase_price' => $request->purchase_price,
                'is_primary' => $request->boolean('is_primary', false),
                'is_active' => $request->boolean('is_active', true),
            ]);

            // Jika set sebagai primary, update yang lain jadi non-primary
            if ($productSupplier->is_primary) {
                $productSupplier->setPrimary();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product supplier created successfully',
                'data' => $productSupplier->load(['product', 'supplier', 'company'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified product supplier
     * GET /api/product-suppliers/{id}
     */
    public function show($id)
    {
        $productSupplier = ProductSupplier::with(['product', 'supplier', 'company'])->find($id);

        if (!$productSupplier) {
            return response()->json([
                'success' => false,
                'message' => 'Product supplier not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $productSupplier
        ], 200);
    }

    /**
     * Update the specified product supplier
     * PUT /api/product-suppliers/{id}
     */
    public function update(Request $request, $id)
    {
        $productSupplier = ProductSupplier::find($id);

        if (!$productSupplier) {
            return response()->json([
                'success' => false,
                'message' => 'Product supplier not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'purchase_price' => 'nullable|numeric|min:0',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $productSupplier->update($request->only([
                'purchase_price',
                'is_primary',
                'is_active',
            ]));

            // Jika set sebagai primary, update yang lain jadi non-primary
            if ($request->boolean('is_primary') && $productSupplier->is_primary) {
                $productSupplier->setPrimary();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product supplier updated successfully',
                'data' => $productSupplier->load(['product', 'supplier', 'company'])
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified product supplier
     * DELETE /api/product-suppliers/{id}
     */
    public function destroy($id)
    {
        $productSupplier = ProductSupplier::find($id);

        if (!$productSupplier) {
            return response()->json([
                'success' => false,
                'message' => 'Product supplier not found'
            ], 404);
        }

        try {
            $productSupplier->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product supplier deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set supplier as primary for a product-company
     * POST /api/product-suppliers/{id}/set-primary
     */
    public function setPrimary($id)
    {
        $productSupplier = ProductSupplier::find($id);

        if (!$productSupplier) {
            return response()->json([
                'success' => false,
                'message' => 'Product supplier not found'
            ], 404);
        }

        try {
            $productSupplier->setPrimary();

            return response()->json([
                'success' => true,
                'message' => 'Supplier set as primary successfully',
                'data' => $productSupplier->load(['product', 'supplier', 'company'])
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set primary supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk assign suppliers to product
     * POST /api/product-suppliers/bulk-assign
     */
    public function bulkAssign(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,product_id',
            'company_id' => 'required|exists:companies,company_id',
            'suppliers' => 'required|array|min:1',
            'suppliers.*.supplier_id' => 'required|exists:suppliers,supplier_id',
            'suppliers.*.purchase_price' => 'nullable|numeric|min:0',
            'suppliers.*.is_primary' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $created = [];
            $primarySet = false;

            foreach ($request->suppliers as $supplierData) {
                // Check if already exists
                $exists = ProductSupplier::where('product_id', $request->product_id)
                    ->where('supplier_id', $supplierData['supplier_id'])
                    ->where('company_id', $request->company_id)
                    ->first();

                if (!$exists) {
                    $isPrimary = ($supplierData['is_primary'] ?? false) && !$primarySet;
                    
                    $ps = ProductSupplier::create([
                        'product_id' => $request->product_id,
                        'supplier_id' => $supplierData['supplier_id'],
                        'company_id' => $request->company_id,
                        'purchase_price' => $supplierData['purchase_price'] ?? null,
                        'is_primary' => $isPrimary,
                        'is_active' => true,
                    ]);

                    if ($isPrimary) {
                        $ps->setPrimary();
                        $primarySet = true;
                    }

                    $created[] = $ps;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($created) . ' suppliers assigned successfully',
                'data' => $created
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk assign suppliers',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}