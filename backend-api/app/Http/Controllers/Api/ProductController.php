<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Imports\ProductsImport;
use App\Exports\ProductsExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    /**
     * Display a listing of products with filter & search
     */
    public function index(Request $request)
    {
        $query = Product::with('supplier');

        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->filterByCategory($request->category);
        }

        // ✅ Filter by product_type
        if ($request->has('product_type')) {
            $query->where('product_type', $request->product_type);
        }

        // Filter by brand
        if ($request->has('brand')) {
            $query->where('brand', $request->brand);
        }

        // Filter by supplier
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
            'product_type' => 'nullable|in:prekursor,bbo,ppi,teknis,glassware,alat_lab',
            'brand' => 'required|string|max:200',
            'unit' => 'nullable|string|max:50',
            'supplier_id' => 'nullable|exists:suppliers,supplier_id',
            'purchase_price' => 'nullable|integer|min:0',
            'selling_price' => 'nullable|integer|min:0',
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
            'product_type' => 'nullable|in:prekursor,bbo,ppi,teknis,glassware,alat_lab',
            'brand' => 'sometimes|required|string|max:200',
            'unit' => 'nullable|string|max:50',
            'supplier_id' => 'nullable|exists:suppliers,supplier_id',
            'purchase_price' => 'nullable|integer|min:0',
            'selling_price' => 'nullable|integer|min:0',
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
     * Get all unique brands
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
     * ✅ NEW: Get product type options
     */
    public function productTypes()
    {
        return response()->json([
            'success' => true,
            'data' => Product::getProductTypeOptions()
        ], 200);
    }

    /**
     * ✅ NEW: Export products to Excel
     */
    public function export(Request $request)
    {
        try {
            $filters = $request->only([
                'search',
                'category',
                'product_type',
                'brand',
                'supplier_id',
                'is_precursor'
            ]);

            $fileName = 'products_' . date('YmdHis') . '.xlsx';

            return Excel::download(
                new ProductsExport($filters),
                $fileName
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NEW: Import products from Excel
     */
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:xlsx,xls,csv|max:5120', // Max 5MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $import = new ProductsImport();
            Excel::import($import, $request->file('file'));

            $summary = $import->getSummary();

            $message = "Import completed. Imported: {$summary['imported']}, Skipped: {$summary['skipped']}";

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $summary
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NEW: Download import template
     */
    public function downloadTemplate()
    {
        try {
            $templatePath = storage_path('app/templates/product_import_template.xlsx');

            // If template doesn't exist, create a basic one
            if (!file_exists($templatePath)) {
                $this->createTemplate($templatePath);
            }

            return response()->download($templatePath, 'product_import_template.xlsx');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to download template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create import template file
     */
    private function createTemplate($path)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $headers = [
            'product_code',
            'product_name',
            'category',
            'product_type',
            'brand',
            'unit',
            'supplier',
            'purchase_price',
            'selling_price',
            'is_precursor',
            'description'
        ];

        // Set headers
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $col++;
        }

        // Sample data
        $sheet->setCellValue('A2', 'PRD001');
        $sheet->setCellValue('B2', 'Contoh Produk');
        $sheet->setCellValue('C2', 'Kategori A');
        $sheet->setCellValue('D2', 'prekursor');
        $sheet->setCellValue('E2', 'Brand ABC');
        $sheet->setCellValue('F2', 'pcs');
        $sheet->setCellValue('G2', 'Supplier XYZ');
        $sheet->setCellValue('H2', '100000');
        $sheet->setCellValue('I2', '150000');
        $sheet->setCellValue('J2', 'Ya');
        $sheet->setCellValue('K2', 'Deskripsi produk');

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($path);
    }
}