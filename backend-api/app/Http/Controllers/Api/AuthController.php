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
use Illuminate\Support\Facades\Log;

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
                'role_id' => 3, // Default: Sales/Marketing
                'is_active' => true,
            ]);

            // ✅ Auto-assign ke default company saat register
            // sehingga user langsung bisa login tanpa perlu setup manual
            $defaultCompany = Company::where('is_active', true)
                ->where('is_default', true)
                ->first()
                ?? Company::where('is_active', true)->orderBy('company_id')->first();

            if ($defaultCompany) {
                $user->companies()->attach($defaultCompany->company_id, [
                    'is_default' => 1,
                ]);
                $user->update(['default_company_id' => $defaultCompany->company_id]);
            }

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
            Log::error('Registration error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


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

        try {
            // Load user dengan eager loading
            $user = User::where('username', $request->username)
                        ->orWhere('email', $request->username)
                        ->with(['role.permissions.menu', 'companies', 'defaultCompany'])
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

            // Get companies sesuai role
            if ($user->role_id === 1) {
                // Super Admin: semua companies aktif
                $companies = Company::where('companies.is_active', true)
                    ->orderBy('companies.company_code')
                    ->get(['companies.company_id', 'companies.company_code', 'companies.company_name']);
            } else {
                // Normal user: hanya yang di-assign + aktif
                $companies = $user->companies()
                    ->where('companies.is_active', true)
                    ->orderBy('companies.company_code')
                    ->get(['companies.company_id', 'companies.company_code', 'companies.company_name']);
            }

            // ✅ FIX: Jika user belum di-assign ke company manapun,
            // auto-assign ke default/pertama company yang aktif
            // (terjadi pada user yang dibuat via admin panel tanpa assign company)
            if ($companies->isEmpty() && $user->role_id !== 1) {
                $defaultCompany = Company::where('is_active', true)
                    ->where('is_default', true)
                    ->first()
                    ?? Company::where('is_active', true)->orderBy('company_id')->first();

                if ($defaultCompany) {
                    // Attach ke user_companies
                    $user->companies()->syncWithoutDetaching([
                        $defaultCompany->company_id => ['is_default' => 1]
                    ]);
                    $user->update(['default_company_id' => $defaultCompany->company_id]);

                    // Reload companies setelah assign
                    $companies = $user->companies()
                        ->where('companies.is_active', true)
                        ->orderBy('companies.company_code')
                        ->get(['companies.company_id', 'companies.company_code', 'companies.company_name']);

                    Log::info('Auto-assigned user to default company', [
                        'user_id'    => $user->user_id,
                        'username'   => $user->username,
                        'company_id' => $defaultCompany->company_id,
                    ]);
                }
            }

            // Jika masih kosong setelah auto-assign (tidak ada company aktif sama sekali)
            if ($companies->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada company aktif yang tersedia. Hubungi administrator.',
                ], 403);
            }

            // Tentukan selected company
            $selectedCompany = null;

            if ($user->default_company_id) {
                $selectedCompany = $companies->firstWhere('company_id', $user->default_company_id);
            }

            // Fallback: pilih yang pertama
            if (!$selectedCompany) {
                $selectedCompany = $companies->first();
                $user->update(['default_company_id' => $selectedCompany->company_id]);
            }

            // Create token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Update last login
            $user->update(['last_login' => now()]);

            // Get permissions
            $permissions = $user->permissions;

            // Create session
            UserSession::create([
                'user_id'             => $user->user_id,
                'session_token'       => $token,
                'selected_company_id' => $selectedCompany->company_id,
                'ip_address'          => $request->ip(),
                'user_agent'          => $request->userAgent(),
                'is_active'           => true,
                'login_at'            => now(),
            ]);

            // Log activity
            ActivityLog::create([
                'user_id'     => $user->user_id,
                'action'      => 'login',
                'module'      => 'auth',
                'description' => 'User logged in: ' . $user->username,
                'ip_address'  => $request->ip()
            ]);

            $companiesFormatted = $companies->map(fn($c) => [
                'company_id'   => $c->company_id,
                'company_code' => $c->company_code,
                'company_name' => $c->company_name,
            ])->values();

            Log::info('Login successful', [
                'user_id'            => $user->user_id,
                'username'           => $user->username,
                'companies_count'    => $companies->count(),
                'selected_company_id'=> $selectedCompany->company_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'user_id'      => $user->user_id,
                        'username'     => $user->username,
                        'email'        => $user->email,
                        'full_name'    => $user->full_name,
                        'phone'        => $user->phone,
                        'role_id'      => $user->role_id,
                        'role_name'    => $user->role->role_name,
                        'company_id'   => $selectedCompany->company_id,
                        'company_name' => $selectedCompany->company_name,
                        'last_login'   => $user->last_login,
                    ],
                    'token'      => $token,
                    'token_type' => 'Bearer',
                    'permissions' => $permissions,
                    'companies'   => $companiesFormatted,
                    'selected_company' => [
                        'company_id'   => $selectedCompany->company_id,
                        'company_code' => $selectedCompany->company_code,
                        'company_name' => $selectedCompany->company_name,
                    ],
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Login failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Switch company
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

        try {
            $user      = $request->user();
            $companyId = $request->company_id;

            // Validasi akses (Super Admin bypass)
            if ($user->role_id !== 1 && !$user->hasAccessToCompany($companyId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke company ini'
                ], 403);
            }

            $user->update(['default_company_id' => $companyId]);

            $token = $request->bearerToken();
            UserSession::where('session_token', $token)
                       ->where('user_id', $user->user_id)
                       ->update(['selected_company_id' => $companyId]);

            $company = Company::find($companyId);

            ActivityLog::create([
                'user_id'     => $user->user_id,
                'action'      => 'switch_company',
                'module'      => 'auth',
                'description' => "Switched to company: {$company->company_name}",
                'ip_address'  => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => "Berhasil beralih ke {$company->company_name}",
                'data' => [
                    'selected_company' => [
                        'company_id'   => $company->company_id,
                        'company_code' => $company->company_code,
                        'company_name' => $company->company_name,
                        'is_default'   => (bool) $company->is_default,
                    ],
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Switch company error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to switch company',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get authenticated user (dengan permissions)
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user()->load(['role.permissions.menu', 'companies', 'defaultCompany']);

            if ($user->role_id === 1) {
                $companies = Company::where('companies.is_active', true)
                    ->orderBy('companies.company_code')
                    ->get(['companies.company_id', 'companies.company_code', 'companies.company_name']);
            } else {
                $companies = $user->companies()
                    ->where('companies.is_active', true)
                    ->orderBy('companies.company_code')
                    ->get(['companies.company_id', 'companies.company_code', 'companies.company_name']);
            }

            $companiesFormatted = $companies->map(fn($c) => [
                'company_id'   => $c->company_id,
                'company_code' => $c->company_code,
                'company_name' => $c->company_name,
            ])->values();

            $permissions = $user->permissions;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'user_id'      => $user->user_id,
                        'username'     => $user->username,
                        'email'        => $user->email,
                        'full_name'    => $user->full_name,
                        'phone'        => $user->phone,
                        'role_id'      => $user->role_id,
                        'role_name'    => $user->role->role_name,
                        'company_id'   => $user->default_company_id,
                        'company_name' => $user->defaultCompany?->company_name,
                        'is_active'    => $user->is_active,
                        'last_login'   => $user->last_login,
                    ],
                    'permissions'      => $permissions,
                    'companies'        => $companiesFormatted,
                    'selected_company' => $user->defaultCompany ? [
                        'company_id'   => $user->defaultCompany->company_id,
                        'company_code' => $user->defaultCompany->company_code,
                        'company_name' => $user->defaultCompany->company_name,
                    ] : null,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get user info error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get user info',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            UserSession::where('user_id', $user->user_id)
                       ->where('is_active', true)
                       ->update([
                           'logout_at' => now(),
                           'is_active' => false
                       ]);

            ActivityLog::create([
                'user_id'     => $user->user_id,
                'action'      => 'logout',
                'module'      => 'auth',
                'description' => 'User logged out: ' . $user->username,
                'ip_address'  => $request->ip()
            ]);

            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
