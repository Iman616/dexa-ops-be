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
    /* ==========================================================
     * HELPER PRIVATE
     *
     * ✅ KEPUTUSAN ARSITEKTUR:
     *   pivot `user_companies` → hanya untuk mencatat default/history
     *   BUKAN untuk membatasi akses company.
     *
     *   Filtering data per company sudah terjadi di setiap endpoint
     *   masing-masing (via company_id dari user_sessions).
     *   Tidak perlu double-restriction di sini.
     * ========================================================== */
    private function getActiveCompanies(): \Illuminate\Support\Collection
    {
        // Semua role mendapat daftar yang sama: semua company aktif
        return Company::where('is_active', true)
            ->orderBy('company_code')
            ->get(['company_id', 'company_code', 'company_name']);
    }

    private function formatCompanies(\Illuminate\Support\Collection $companies): \Illuminate\Support\Collection
    {
        return $companies->map(fn($c) => [
            'company_id'   => $c->company_id,
            'company_code' => $c->company_code,
            'company_name' => $c->company_name,
        ])->values();
    }

    /* ==========================================================
     * POST /api/auth/register
     * ========================================================== */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users,email',
            'username'  => 'required|string|max:100|unique:users,username',
            'password'  => 'required|string|min:8|confirmed',
            'phone'     => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $user = User::create([
                'full_name' => $request->full_name,
                'email'     => $request->email,
                'username'  => $request->username,
                'password'  => Hash::make($request->password),
                'phone'     => $request->phone,
                'role_id'   => 3, // Default: Sales/Marketing
                'is_active' => true,
            ]);

            // Auto-set default company untuk user baru
            $defaultCompany = Company::where('is_active', true)->where('is_default', true)->first()
                           ?? Company::where('is_active', true)->orderBy('company_id')->first();

            if ($defaultCompany) {
                $user->companies()->attach($defaultCompany->company_id, ['is_default' => 1]);
                $user->update(['default_company_id' => $defaultCompany->company_id]);
            }

            ActivityLog::create([
                'user_id'     => $user->user_id,
                'action'      => 'register',
                'module'      => 'auth',
                'description' => 'User registered: ' . $user->username,
                'ip_address'  => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'data'    => [
                    'user' => [
                        'user_id'   => $user->user_id,
                        'username'  => $user->username,
                        'email'     => $user->email,
                        'full_name' => $user->full_name,
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Registration error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /* ==========================================================
     * POST /api/auth/login
     *
     * BUG SEBELUMNYA:
     *   Non-SA → $companies = $user->companies()->where(is_active)...
     *   Jika user hanya punya 1 row di pivot user_companies (misal
     *   company_id=3), maka dropdown hanya tampil 1 company.
     *   User tidak bisa pilih company lain meskipun company itu aktif.
     *
     * FIX:
     *   Semua user mendapat daftar SEMUA company aktif.
     *   Pivot user_companies hanya untuk menentukan default company.
     * ========================================================== */
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
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
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

            // ✅ FIX #1 — Semua user dapat semua company aktif
            $companies = $this->getActiveCompanies();

            if ($companies->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada company aktif. Hubungi administrator.',
                ], 403);
            }

            // Tentukan selected company:
            // 1. Pakai default_company_id user jika ada & masih aktif
            // 2. Fallback ke company pertama yang aktif
            $selectedCompany = null;

            if ($user->default_company_id) {
                $selectedCompany = $companies->firstWhere('company_id', $user->default_company_id);
            }

            if (!$selectedCompany) {
                $selectedCompany = $companies->first();
                $user->update(['default_company_id' => $selectedCompany->company_id]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;
            $user->update(['last_login' => now()]);

            UserSession::create([
                'user_id'             => $user->user_id,
                'session_token'       => $token,
                'selected_company_id' => $selectedCompany->company_id,
                'ip_address'          => $request->ip(),
                'user_agent'          => $request->userAgent(),
                'is_active'           => true,
                'login_at'            => now(),
            ]);

            ActivityLog::create([
                'user_id'     => $user->user_id,
                'action'      => 'login',
                'module'      => 'auth',
                'description' => 'User logged in: ' . $user->username,
                'ip_address'  => $request->ip()
            ]);

            Log::info('Login successful', [
                'user_id'             => $user->user_id,
                'username'            => $user->username,
                'companies_available' => $companies->count(),
                'selected_company_id' => $selectedCompany->company_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data'    => [
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
                    'token'            => $token,
                    'token_type'       => 'Bearer',
                    'permissions'      => $user->permissions,
                    'companies'        => $this->formatCompanies($companies),
                    'selected_company' => [
                        'company_id'   => $selectedCompany->company_id,
                        'company_code' => $selectedCompany->company_code,
                        'company_name' => $selectedCompany->company_name,
                    ],
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Login failed',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /* ==========================================================
     * POST /api/auth/switch-company
     *
     * BUG SEBELUMNYA:
     *   if ($user->role_id !== 1 && !$user->hasAccessToCompany($companyId))
     *       → return 403
     *
     *   hasAccessToCompany() mengecek pivot user_companies.
     *   Non-SA yang hanya punya 1 company di pivot → selalu 403
     *   saat coba switch ke company lain.
     *
     * FIX:
     *   Hapus pengecekan hasAccessToCompany().
     *   Validasi cukup: company harus ada dan is_active=true.
     *   Data tetap aman karena setiap endpoint filter by company_id
     *   dari session — user hanya lihat data company yang sedang aktif.
     * ========================================================== */
    public function switchCompany(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $user      = $request->user();
            $companyId = (int) $request->company_id;

            // ✅ FIX #2 — Validasi cukup: company harus aktif
            // DIHAPUS: if ($user->role_id !== 1 && !$user->hasAccessToCompany($companyId)) → 403
            $company = Company::where('company_id', $companyId)
                               ->where('is_active', true)
                               ->first();

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company tidak ditemukan atau tidak aktif.'
                ], 404);
            }

            // Update default company user & session
            $user->update(['default_company_id' => $companyId]);

            // Catat di pivot sebagai audit trail (tidak membatasi akses)
            $user->companies()->syncWithoutDetaching([
                $companyId => ['is_default' => 1]
            ]);

            // Update session aktif dengan company baru
            $token = $request->bearerToken();
            UserSession::where('session_token', $token)
                       ->where('user_id', $user->user_id)
                       ->update(['selected_company_id' => $companyId]);

            ActivityLog::create([
                'user_id'     => $user->user_id,
                'action'      => 'switch_company',
                'module'      => 'auth',
                'description' => "Switched to company: {$company->company_name}",
                'ip_address'  => $request->ip()
            ]);

            Log::info('Company switched', [
                'user_id'    => $user->user_id,
                'username'   => $user->username,
                'company_id' => $companyId,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Berhasil beralih ke {$company->company_name}",
                'data'    => [
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
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /* ==========================================================
     * GET /api/auth/me
     *
     * BUG SEBELUMNYA:
     *   Non-SA → $companies = $user->companies()->where(is_active)...
     *   Sama seperti login — hanya pivot, bukan semua company aktif.
     *   Setelah login + switch company, /me masih return list lama
     *   sehingga frontend tidak tahu company lain tersedia.
     *
     * FIX:
     *   Return semua company aktif + resolve selected_company
     *   dari session (bukan dari default_company_id saja).
     * ========================================================== */
    public function me(Request $request)
    {
        try {
            $user = $request->user()->load(['role.permissions.menu', 'companies', 'defaultCompany']);

            // ✅ FIX #3 — Semua user dapat semua company aktif
            $companies = $this->getActiveCompanies();

            // Resolve selected company dari session yang sedang aktif
            // (bukan dari default_company_id, agar konsisten dengan switchCompany)
            $token   = $request->bearerToken();
            $session = \Illuminate\Support\Facades\DB::table('user_sessions')
                ->where('session_token', $token)
                ->where('user_id', $user->user_id)
                ->where('is_active', true)
                ->orderByDesc('login_at')
                ->first();

            $selectedCompanyId = $session?->selected_company_id
                              ?? $user->default_company_id;

            $selectedCompany = $companies->firstWhere('company_id', $selectedCompanyId)
                            ?? $companies->first();

            return response()->json([
                'success' => true,
                'data'    => [
                    'user' => [
                        'user_id'      => $user->user_id,
                        'username'     => $user->username,
                        'email'        => $user->email,
                        'full_name'    => $user->full_name,
                        'phone'        => $user->phone,
                        'role_id'      => $user->role_id,
                        'role_name'    => $user->role->role_name,
                        'company_id'   => $selectedCompany?->company_id,
                        'company_name' => $selectedCompany?->company_name,
                        'is_active'    => $user->is_active,
                        'last_login'   => $user->last_login,
                    ],
                    'permissions'      => $user->permissions,
                    'companies'        => $this->formatCompanies($companies),
                    'selected_company' => $selectedCompany ? [
                        'company_id'   => $selectedCompany->company_id,
                        'company_code' => $selectedCompany->company_code,
                        'company_name' => $selectedCompany->company_name,
                    ] : null,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get user info error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get user info',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /* ==========================================================
     * POST /api/auth/logout
     * ========================================================== */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            UserSession::where('user_id', $user->user_id)
                       ->where('is_active', true)
                       ->update([
                           'logout_at' => now(),
                           'is_active' => false,
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
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
