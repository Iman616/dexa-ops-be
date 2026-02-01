<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Display a listing of products with filter & search
     */
    public function index(Request $request)
    {
        // ✅ Tambahkan eager loading supplier
        $query = Product::with('supplier');

        // ✅ Filter by brand
        if ($request->has('brand')) {
            $query->where('brand', $request->brand);
        }
        
        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->filterByCategory($request->category);
        }

        // ✅ Filter by supplier
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter by precursor
        if ($request->has('is_precursor')) {
            $query->where('is_precursor', $request->boolean('is_precursor'));
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'product_id');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $products = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully',
            'data' => $products
        ], 200);
    }

    /**
     * Store a newly created product
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_code' => 'required|string|max:100|unique:products,product_code',
            'product_name' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'brand' => 'required|string|max:200',
            'unit' => 'nullable|string|max:50',
            'supplier_id' => 'nullable|exists:suppliers,supplier_id', // ✅ Tambahkan validasi
            'purchase_price' => 'nullable|integer|min:0',              // ✅ Tambahkan validasi
            'selling_price' => 'nullable|integer|min:0',               // ✅ Tambahkan validasi
            'is_precursor' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $product = Product::create($request->all());
            
            // ✅ Load supplier relationship setelah create
            $product->load('supplier');

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified product
     */
    public function show($id)
    {
        // ✅ Tambahkan 'supplier' ke eager loading
        $product = Product::with(['supplier', 'stockIns', 'stockBatches'])->find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully',
            'data' => $product
        ], 200);
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'product_code' => 'sometimes|required|string|max:100|unique:products,product_code,' . $id . ',product_id',
            'product_name' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|max:100',
            'brand' => 'sometimes|required|string|max:200',
            'unit' => 'nullable|string|max:50',
            'supplier_id' => 'nullable|exists:suppliers,supplier_id', // ✅ Tambahkan validasi
            'purchase_price' => 'nullable|integer|min:0',              // ✅ Tambahkan validasi
            'selling_price' => 'nullable|integer|min:0',               // ✅ Tambahkan validasi
            'is_precursor' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $product->update($request->all());
            
            // ✅ Load supplier relationship setelah update
            $product->load('supplier');

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified product
     */
    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        try {
            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all unique categories
     */
    public function categories()
    {
        $categories = Product::select('category')
            ->distinct()
            ->whereNotNull('category')
            ->orderBy('category')
            ->pluck('category');

        return response()->json([
            'success' => true,
            'data' => $categories
        ], 200);
    }

    /**
     * ✅ BARU: Get all unique brands
     */
    public function brands()
    {
        $brands = Product::select('brand')
            ->distinct()
            ->whereNotNull('brand')
            ->orderBy('brand')
            ->pluck('brand');

        return response()->json([
            'success' => true,
            'data' => $brands
        ], 200);
    }

    /**
     * Export products to Excel (optional - requires maatwebsite/excel)
     */
    public function export()
    {
        return response()->json([
            'success' => false,
            'message' => 'Export feature not yet implemented'
        ], 501);
    }

    /**
     * Import products from Excel (optional - requires maatwebsite/excel)
     */
    public function import(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Import feature not yet implemented'
        ], 501);
    }
}
