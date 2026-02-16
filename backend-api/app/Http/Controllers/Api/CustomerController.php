<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * ✅ UPDATED: Display a listing of customers with filters
     */
    public function index(Request $request)
    {
        $query = Customer::query();

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('customer_name', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%")
                  ->orWhere('npwp', 'LIKE', "%{$search}%")    // ✅ NEW
                  ->orWhere('nib', 'LIKE', "%{$search}%");    // ✅ NEW
            });
        }

        // ✅ NEW: Filter by tax info availability
        if ($request->has('has_npwp')) {
            $hasNpwp = $request->boolean('has_npwp');
            if ($hasNpwp) {
                $query->hasNpwp();
            } else {
                $query->where(function($q) {
                    $q->whereNull('npwp')->orWhere('npwp', '');
                });
            }
        }

        if ($request->has('has_nib')) {
            $hasNib = $request->boolean('has_nib');
            if ($hasNib) {
                $query->hasNib();
            } else {
                $query->where(function($q) {
                    $q->whereNull('nib')->orWhere('nib', '');
                });
            }
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'customer_id');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $customers = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Customers retrieved successfully',
            'data' => $customers
        ], 200);
    }

    /**
     * ✅ UPDATED: Store a newly created customer
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'npwp' => 'nullable|string|max:50|regex:/^[0-9.\-]+$/',  // ✅ NEW: Allow numbers, dots, dashes
            'nib' => 'nullable|string|max:50|regex:/^[0-9]+$/',      // ✅ NEW: Numbers only
            'tax_address' => 'nullable|string|max:500',              // ✅ NEW
        ], [
            'npwp.regex' => 'NPWP hanya boleh berisi angka, titik, dan strip',
            'nib.regex' => 'NIB hanya boleh berisi angka',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $customer = Customer::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ UPDATED: Display the specified customer
     */
    public function show($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        // Get transaction summary
        $transactionSummary = DB::table('stock_out')
            ->where('customer_id', $id)
            ->select(
                DB::raw('COUNT(*) as total_transactions'),
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(quantity * selling_price) as total_value')
            )
            ->first();

        // ✅ NEW: Get quotation and PO summary
        $quotationCount = DB::table('quotations')
            ->where('customer_id', $id)
            ->count();

        $poCount = DB::table('purchase_orders')
            ->where('customer_id', $id)
            ->count();

        $customer->transaction_summary = $transactionSummary;
        $customer->quotation_count = $quotationCount;      // ✅ NEW
        $customer->purchase_order_count = $poCount;        // ✅ NEW

        return response()->json([
            'success' => true,
            'message' => 'Customer retrieved successfully',
            'data' => $customer
        ], 200);
    }

    /**
     * ✅ UPDATED: Update the specified customer
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'customer_name' => 'sometimes|required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'npwp' => 'nullable|string|max:50|regex:/^[0-9.\-]+$/',  // ✅ NEW
            'nib' => 'nullable|string|max:50|regex:/^[0-9]+$/',      // ✅ NEW
            'tax_address' => 'nullable|string|max:500',              // ✅ NEW
        ], [
            'npwp.regex' => 'NPWP hanya boleh berisi angka, titik, dan strip',
            'nib.regex' => 'NIB hanya boleh berisi angka',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $customer->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Customer updated successfully',
                'data' => $customer
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ UPDATED: Remove the specified customer
     */
    public function destroy($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        // Check if customer has transactions
        $hasTransactions = DB::table('stock_out')
            ->where('customer_id', $id)
            ->exists();

        if ($hasTransactions) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete customer with existing transactions'
            ], 400);
        }

        // ✅ NEW: Check if customer has quotations
        $hasQuotations = DB::table('quotations')
            ->where('customer_id', $id)
            ->exists();

        if ($hasQuotations) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete customer with existing quotations'
            ], 400);
        }

        // ✅ NEW: Check if customer has purchase orders
        $hasPurchaseOrders = DB::table('purchase_orders')
            ->where('customer_id', $id)
            ->exists();

        if ($hasPurchaseOrders) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete customer with existing purchase orders'
            ], 400);
        }

        try {
            $customer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Customer deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NEW: Validate NPWP format
     */
    public function validateNpwp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'npwp' => 'required|string|regex:/^[0-9.\-]+$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid NPWP format',
                'errors' => $validator->errors()
            ], 422);
        }

        // Clean NPWP
        $cleanNpwp = preg_replace('/[^0-9]/', '', $request->npwp);

        // Check if NPWP is 15 digits
        $isValid = strlen($cleanNpwp) === 15;

        // Check if NPWP already exists
        $exists = Customer::where('npwp', $cleanNpwp)
            ->when($request->has('customer_id'), function($q) use ($request) {
                return $q->where('customer_id', '!=', $request->customer_id);
            })
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'is_valid' => $isValid,
                'is_unique' => !$exists,
                'clean_npwp' => $cleanNpwp,
                'length' => strlen($cleanNpwp),
            ]
        ], 200);
    }
}
