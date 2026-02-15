<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class CompanyController extends Controller
{
    /**
     * ✅ OPTIMIZED: Display a listing of companies
     * - Selective column loading
     * - Query optimization
     * - Response caching
     */
    public function index(Request $request)
    {
        // ✅ Generate cache key based on request params
        $cacheKey = 'companies_list_' . md5(json_encode($request->all()));
        $cacheDuration = 300; // 5 minutes

        // ✅ Return cached response if exists
        if (!$request->has('no_cache') && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // ✅ Select only necessary columns for listing
        $query = Company::select([
            'company_id',
            'company_code',
            'company_name',
            'address',
            'phone',
            'email',
            'city',
            'logo_path',
            'is_active',
            'created_at',
        ]);

        // Filter by is_active
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // ✅ OPTIMIZED: Search with index-friendly queries
        if ($request->filled('search')) {
            $search = $request->search;
            
            // Use LIKE with leading wildcard only when necessary
            $query->where(function ($q) use ($search) {
                // Exact match first (uses index)
                $q->where('company_code', $search)
                  ->orWhere('company_name', $search)
                  // Then fuzzy match
                  ->orWhere('company_code', 'like', "{$search}%")
                  ->orWhere('company_name', 'like', "{$search}%")
                  ->orWhere('email', 'like', "{$search}%")
                  ->orWhere('phone', 'like', "{$search}%");
            });
        }

        // ✅ Whitelist sortable columns (security)
        $allowedSortColumns = [
            'company_code', 
            'company_name', 
            'email', 
            'phone', 
            'city',
            'is_active',
            'created_at'
        ];
        
        $sortBy = $request->get('sort_by', 'company_code');
        $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'company_code';
        
        $sortOrder = $request->get('sort_order', 'asc');
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? $sortOrder : 'asc';
        
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        if ($request->boolean('paginate', true)) {
            $perPage = min($request->get('per_page', 15), 100); // Max 100 per page
            $companies = $query->paginate($perPage);
        } else {
            // ✅ Limit for non-paginated requests
            $companies = $query->limit(1000)->get();
        }

        $response = response()->json([
            'success' => true,
            'message' => 'Companies retrieved successfully',
            'data' => $companies
        ], 200);

        // ✅ Cache the response
        Cache::put($cacheKey, $response, $cacheDuration);

        return $response;
    }

    /**
     * ✅ OPTIMIZED: Store a newly created company
     * - Batch validation
     * - Optimized file handling
     * - Cache invalidation
     */
    public function store(Request $request)
    {
        // ✅ Use validate() instead of Validator::make() for cleaner code
        $validated = $request->validate([
            'company_code' => 'required|string|max:10|unique:companies,company_code',
            'company_name' => 'required|string|max:255',
            
            // KOLOM KOP SURAT
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:100',
            'city' => 'nullable|string|max:100',
            'pic_name' => 'nullable|string|max:100',
            
            // BANK INFO
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            
            // LOGO UPLOAD
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            
            // LAINNYA
            'is_active' => 'nullable|boolean',
        ]);

        try {
            DB::beginTransaction();

            // ✅ Set defaults
            $validated['created_by'] = Auth::id() ?? 1;
            $validated['is_active'] = $validated['is_active'] ?? true;

            // ✅ OPTIMIZED: Handle logo upload with optimized filename
            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                
                // Generate optimized filename
                $extension = $file->getClientOriginalExtension();
                $filename = 'company_' . uniqid() . '_' . time() . '.' . $extension;
                
                // Store with custom filename
                $logoPath = $file->storeAs('logos/companies', $filename, 'public');
                $validated['logo_path'] = $logoPath;
            }

            $company = Company::create($validated);

            DB::commit();

            // ✅ Clear cache
            $this->clearCompanyCache();

            return response()->json([
                'success' => true,
                'message' => 'Company created successfully',
                'data' => $company
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // ✅ Cleanup uploaded file on error
            if (isset($logoPath) && Storage::disk('public')->exists($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create company',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * ✅ OPTIMIZED: Display the specified company
     * - Selective eager loading
     * - Response caching
     */
    public function show($id)
    {
        // ✅ Cache individual company
        $cacheKey = "company_detail_{$id}";
        
        $company = Cache::remember($cacheKey, 600, function () use ($id) {
            return Company::with([
                'users:user_id,username,email,full_name' // ✅ Select only needed columns
            ])->find($id);
        });

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
     * ✅ OPTIMIZED: Update the specified company
     * - Optimized validation
     * - Smart cache invalidation
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

        // ✅ Use validate() with Rule object for cleaner unique validation
        $validated = $request->validate([
            'company_code' => [
                'sometimes',
                'required',
                'string',
                'max:10',
                'unique:companies,company_code,' . $id . ',company_id'
            ],
            'company_name' => 'sometimes|required|string|max:255',
            
            // KOLOM KOP SURAT
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:100',
            'city' => 'nullable|string|max:100',
            'pic_name' => 'nullable|string|max:100',
            
            // BANK INFO
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            
            // LOGO UPLOAD
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            
            // LAINNYA
            'is_active' => 'nullable|boolean',
        ]);

        try {
            DB::beginTransaction();

            $oldLogoPath = $company->logo_path;

            // ✅ Handle logo upload
            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                
                // Generate optimized filename
                $extension = $file->getClientOriginalExtension();
                $filename = 'company_' . $id . '_' . time() . '.' . $extension;
                
                // Store new logo
                $logoPath = $file->storeAs('logos/companies', $filename, 'public');
                $validated['logo_path'] = $logoPath;
                
                // ✅ Delete old logo AFTER successful upload
                if ($oldLogoPath && Storage::disk('public')->exists($oldLogoPath)) {
                    Storage::disk('public')->delete($oldLogoPath);
                }
            }

            $company->update($validated);

            DB::commit();

            // ✅ Clear cache
            $this->clearCompanyCache($id);

            return response()->json([
                'success' => true,
                'message' => 'Company updated successfully',
                'data' => $company->fresh()
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // ✅ Cleanup uploaded file on error
            if (isset($logoPath) && $logoPath !== $oldLogoPath) {
                if (Storage::disk('public')->exists($logoPath)) {
                    Storage::disk('public')->delete($logoPath);
                }
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update company',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * ✅ OPTIMIZED: Remove the specified company
     * - Optimized dependency check
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

        // ✅ OPTIMIZED: Use exists() with limit for faster check
        $hasStockBatches = DB::table('stock_batches')
            ->where('company_id', $id)
            ->limit(1)
            ->exists();
            
        $hasQuotations = DB::table('quotations')
            ->where('company_id', $id)
            ->limit(1)
            ->exists();
            
        $hasInvoices = DB::table('invoices')
            ->where('company_id', $id)
            ->limit(1)
            ->exists();

        if ($hasStockBatches || $hasQuotations || $hasInvoices) {
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

            // ✅ Clear cache
            $this->clearCompanyCache($id);

            return response()->json([
                'success' => true,
                'message' => 'Company deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete company',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * ✅ OPTIMIZED: Toggle company active status
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

        // ✅ Use DB update for better performance (no model events)
        DB::table('companies')
            ->where('company_id', $id)
            ->update(['is_active' => !$company->is_active]);

        // Refresh model
        $company->refresh();

        // ✅ Clear cache
        $this->clearCompanyCache($id);

        return response()->json([
            'success' => true,
            'message' => 'Company status updated',
            'data' => $company
        ], 200);
    }

    /**
     * ✅ NEW: Clear company-related cache
     */
    private function clearCompanyCache($companyId = null)
    {
        // Clear list cache (all variations)
        Cache::forget('companies_list_*');
        
        // Clear specific company cache
        if ($companyId) {
            Cache::forget("company_detail_{$companyId}");
            Cache::forget("po_{$companyId}_is_tender"); // Related PO cache
        }
        
        // ✅ Clear cache tags if using Redis/Memcached
        if (config('cache.default') !== 'file') {
            Cache::tags(['companies'])->flush();
        }
    }

    /**
     * ✅ NEW: Get companies dropdown (lightweight)
     * For select options in forms
     */
    public function dropdown(Request $request)
    {
        $cacheKey = 'companies_dropdown_' . ($request->boolean('active_only') ? 'active' : 'all');
        
        $companies = Cache::remember($cacheKey, 3600, function () use ($request) {
            $query = Company::select(['company_id', 'company_code', 'company_name', 'logo_path']);  
            
            if ($request->boolean('active_only', true)) {
                $query->where('is_active', true);
            }
            
            return $query->orderBy('company_code')->get();
        });

        return response()->json([
            'success' => true,
            'data' => $companies
        ], 200);
    }
}
