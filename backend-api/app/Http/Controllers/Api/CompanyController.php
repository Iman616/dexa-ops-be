<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class CompanyController extends Controller
{
    /**
     * Display a listing of companies
     */
    public function index(Request $request)
    {
        $query = Company::query();

        // Filter by is_active
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('company_code', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'company_code');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        if ($request->get('paginate', true)) {
            $perPage = $request->get('per_page', 15);
            $companies = $query->paginate($perPage);
        } else {
            $companies = $query->get();
        }

        return response()->json([
            'success' => true,
            'message' => 'Companies retrieved successfully',
            'data' => $companies
        ], 200);
    }

    /**
     * Store a newly created company - LENGKAP UNTUK KOP SURAT
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_code' => 'required|string|max:10|unique:companies,company_code',
            'company_name' => 'required|string|max:255',
            
            // KOLOM KOP SURAT
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|max:100',
            'city' => 'nullable|string|max:100',
            'pic_name' => 'nullable|string|max:100',
            
            // BANK INFO
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            
            // LOGO UPLOAD - Accept file
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:2048', // Max 2MB
            
            // LAINNYA
            'is_active' => 'nullable|boolean',
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

            // Prepare company data (exclude logo file from mass assignment)
            $companyData = $request->except(['logo', 'logo_file']);
            $companyData['created_by'] = Auth::id() ?? 1; // Auto set creator

            // Handle logo upload BEFORE creating company
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('logos/companies', 'public');
                $companyData['logo_path'] = $logoPath;
            }

            $company = Company::create($companyData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Company created successfully',
                'data' => $company->fresh() // Reload untuk data terbaru
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Delete uploaded logo if company creation fails
            if (isset($logoPath) && Storage::disk('public')->exists($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create company',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified company - LENGKAP UNTUK PDF
     */
    public function show($id)
    {
        $company = Company::with(['users'])->find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Company retrieved successfully',
            'data' => $company
        ], 200);
    }

    /**
     * Update the specified company - LENGKAP
     */
    public function update(Request $request, $id)
    {
        $company = Company::find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'company_code' => 'sometimes|required|string|max:10|unique:companies,company_code,' . $id . ',company_id',
            'company_name' => 'sometimes|required|string|max:255',
            
            // KOLOM KOP SURAT
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|max:100',
            'city' => 'nullable|string|max:100',
            'pic_name' => 'nullable|string|max:100',
            
            // BANK INFO
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            
            // LOGO UPLOAD - Accept file
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:2048', // Max 2MB
            
            // LAINNYA
            'is_active' => 'nullable|boolean',
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

            // Prepare update data (exclude logo file)
            $updateData = $request->except(['logo', 'logo_file', '_method']);

            // Handle logo upload
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
                    Storage::disk('public')->delete($company->logo_path);
                }
                
                // Upload new logo
                $logoPath = $request->file('logo')->store('logos/companies', 'public');
                $updateData['logo_path'] = $logoPath;
            }

            $company->update($updateData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Company updated successfully',
                'data' => $company->fresh()
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Delete uploaded logo if update fails
            if (isset($logoPath) && Storage::disk('public')->exists($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update company',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified company
     */
    public function destroy($id)
    {
        $company = Company::find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found'
            ], 404);
        }

        // Check dependencies
        $hasTransactions = $company->stockBatches()->exists() ||
                          $company->quotations()->exists() ||
                          $company->invoices()->exists();

        if ($hasTransactions) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete company with existing transactions'
            ], 409);
        }

        try {
            DB::beginTransaction();
            
            // Delete logo if exists
            if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
                Storage::disk('public')->delete($company->logo_path);
            }
            
            $company->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Company deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete company',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle company active status
     */
    public function toggleActive($id)
    {
        $company = Company::find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found'
            ], 404);
        }

        $company->update(['is_active' => !$company->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Company status updated',
            'data' => $company
        ], 200);
    }
}