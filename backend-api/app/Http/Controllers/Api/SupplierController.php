<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupplierController extends Controller
{
    /**
     * GET /api/suppliers
     */
    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('supplier_name', 'LIKE', "%{$search}%")
                    ->orWhere('contact_person', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        // ✅ Filter by supplier_type (manufacturer, distributor, dll)
        if ($request->has('supplier_type')) {
            $query->where('supplier_type', $request->supplier_type);
        }

        // ✅ Filter by dropship
        if ($request->has('is_dropship_enabled')) {
            $query->where('is_dropship_enabled', $request->boolean('is_dropship_enabled'));
        }

        $sortBy    = $request->get('sort_by', 'supplier_id');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage   = $request->get('per_page', 15);
        $suppliers = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Suppliers retrieved successfully',
            'data'    => $suppliers,
        ], 200);
    }

    /**
     * POST /api/suppliers
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_name'      => 'required|string|max:255|unique:suppliers,supplier_name',
            'contact_person'     => 'nullable|string|max:255',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:50',
            'address'            => 'nullable|string',
            'supplier_type'      => 'nullable|string|max:50',
            'is_dropship_enabled'=> 'nullable|boolean',
            'notes'              => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            // ✅ FIXED: pakai only() bukan all()
            $supplier = Supplier::create($request->only([
                'supplier_name',
                'contact_person',
                'email',
                'phone',
                'address',
                'supplier_type',
                'is_dropship_enabled',
                'notes',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Supplier created successfully',
                'data'    => $supplier,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create supplier',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/suppliers/{id}
     */
    public function show($id)
    {
        // ✅ FIXED: load supplierPurchaseOrders (bukan purchaseOrders yang pakai model salah)
        $supplier = Supplier::with([
            'supplierPurchaseOrders',
            'productSuppliers.product',
        ])->find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Supplier retrieved successfully',
            'data'    => $supplier,
        ], 200);
    }

    /**
     * PUT /api/suppliers/{id}
     */
    public function update(Request $request, $id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'supplier_name'      => 'sometimes|required|string|max:255|unique:suppliers,supplier_name,' . $id . ',supplier_id',
            'contact_person'     => 'nullable|string|max:255',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:50',
            'address'            => 'nullable|string',
            'supplier_type'      => 'nullable|string|max:50',
            'is_dropship_enabled'=> 'nullable|boolean',
            'notes'              => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            // ✅ FIXED: pakai only() bukan all()
            $supplier->update($request->only([
                'supplier_name',
                'contact_person',
                'email',
                'phone',
                'address',
                'supplier_type',
                'is_dropship_enabled',
                'notes',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Supplier updated successfully',
                'data'    => $supplier,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update supplier',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/suppliers/{id}
     */
    public function destroy($id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier not found',
            ], 404);
        }

        // ✅ Cek apakah masih ada relasi aktif sebelum hapus
        $hasActiveOrders = $supplier->supplierPurchaseOrders()
            ->whereIn('status', ['draft', 'sent', 'partial'])
            ->exists();

        if ($hasActiveOrders) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat menghapus supplier yang masih memiliki purchase order aktif',
            ], 400);
        }

        try {
            $supplier->delete();

            return response()->json([
                'success' => true,
                'message' => 'Supplier deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete supplier',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/suppliers/dropdown
     */
    public function dropdown(Request $request)
    {
        $query = Supplier::select('supplier_id', 'supplier_name', 'supplier_type')
            ->orderBy('supplier_name');

        // ✅ Bisa filter type langsung dari dropdown
        if ($request->has('supplier_type')) {
            $query->where('supplier_type', $request->supplier_type);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
        ], 200);
    }
}
