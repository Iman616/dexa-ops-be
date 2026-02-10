<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSession;
use App\Models\ActivityLog;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Register new user
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'username' => 'required|string|max:100|unique:users,username',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::create([
                'full_name' => $request->full_name,
                'email' => $request->email,
                'username' => $request->username,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'role_id' => 5, // Default: Viewer role
                'is_active' => true,
            ]);

            ActivityLog::create([
                'user_id' => $user->user_id,
                'action' => 'register',
                'module' => 'auth',
                'description' => 'User registered: ' . $user->username,
                'ip_address' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user' => [
                        'user_id' => $user->user_id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'full_name' => $user->full_name,
                    ]
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⭐ Login user (dengan multi-company)
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Load user dengan companies
        $user = User::where('username', $request->username)
                    ->orWhere('email', $request->username)
                    ->with(['role', 'companies', 'defaultCompany'])
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive'
            ], 403);
        }

        // ⭐ Validasi: user harus punya minimal 1 company
        if ($user->companies->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'User belum di-assign ke company. Hubungi admin.',
            ], 403);
        }

        // ⭐ Tentukan company yang dipilih
        $selectedCompanyId = $user->default_company_id ?? $user->companies->first()->company_id;
        $selectedCompany = Company::find($selectedCompanyId);

        // Update default_company_id jika belum set
        if (!$user->default_company_id) {
            $user->update(['default_company_id' => $selectedCompanyId]);
        }

        // Create token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Update last login
        $user->update(['last_login' => now()]);

        // ⭐ Create session dengan selected_company_id
        UserSession::create([
            'user_id' => $user->user_id,
            'session_token' => $token,
            'selected_company_id' => $selectedCompanyId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'is_active' => true,
            'login_at' => now(),
        ]);

        // Log activity
        ActivityLog::create([
            'user_id' => $user->user_id,
            'action' => 'login',
            'module' => 'auth',
            'description' => 'User logged in: ' . $user->username,
            'ip_address' => $request->ip()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'user_id' => $user->user_id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'full_name' => $user->full_name,
                    'phone' => $user->phone,
                    'role' => $user->role->role_name,
                    'last_login' => $user->last_login,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
                // ⭐ List companies
                'companies' => $user->accessible_companies,
                // ⭐ Selected company
                'selected_company' => [
                    'company_id' => $selectedCompany->company_id,
                    'company_code' => $selectedCompany->company_code,
                    'company_name' => $selectedCompany->company_name,
                ],
            ]
        ], 200);
    }

    /**
     * ⭐ Switch company
     */
    public function switchCompany(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Validasi akses
        if (!$user->hasAccessToCompany($request->company_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke company ini'
            ], 403);
        }

        // Update default company user
        $user->switchToCompany($request->company_id);

        // Update session
        $token = $request->bearerToken();
        UserSession::where('session_token', $token)
                   ->where('user_id', $user->user_id)
                   ->update(['selected_company_id' => $request->company_id]);

        $company = Company::find($request->company_id);

        // Log activity
        ActivityLog::create([
            'user_id' => $user->user_id,
            'action' => 'switch_company',
            'module' => 'auth',
            'description' => "Switched to company: {$company->company_name}",
            'ip_address' => $request->ip()
        ]);

        return response()->json([
            'success' => true,
            'message' => "Berhasil beralih ke {$company->company_name}",
            'data' => [
                'selected_company' => [
                    'company_id' => $company->company_id,
                    'company_code' => $company->company_code,
                    'company_name' => $company->company_name,
                ],
            ]
        ], 200);
    }

    /**
     * ⭐ Get authenticated user (dengan companies)
     */
    public function me(Request $request)
    {
        $user = $request->user()->load(['role', 'companies', 'defaultCompany']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'user_id' => $user->user_id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'full_name' => $user->full_name,
                    'phone' => $user->phone,
                    'role' => $user->role->role_name,
                    'is_active' => $user->is_active,
                    'last_login' => $user->last_login,
                ],
                'companies' => $user->accessible_companies,
                'selected_company' => $user->defaultCompany ? [
                    'company_id' => $user->defaultCompany->company_id,
                    'company_code' => $user->defaultCompany->company_code,
                    'company_name' => $user->defaultCompany->company_name,
                ] : null,
            ]
        ], 200);
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // Deactivate session
        UserSession::where('user_id', $user->user_id)
                   ->where('is_active', true)
                   ->update([
                       'logout_at' => now(),
                       'is_active' => false
                   ]);

        // Log activity
        ActivityLog::create([
            'user_id' => $user->user_id,
            'action' => 'logout',
            'module' => 'auth',
            'description' => 'User logged out: ' . $user->username,
            'ip_address' => $request->ip()
        ]);

        // Revoke token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful'
        ], 200);
    }
}
