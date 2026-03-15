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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /* ==========================================================
     * HELPER PRIVATE
     * ========================================================== */
    private function getActiveCompanies(): \Illuminate\Support\Collection
    {
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

    /**
     * Buat throttle key unik per username + IP
     */
    private function throttleKey(Request $request): string
    {
        return Str::lower($request->input('username')) . '|' . $request->ip();
    }

    /* ==========================================================
     * POST /api/auth/register
     * ========================================================== */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users,email',
            'username'  => [
                'required',
                'string',
                'max:100',
                'unique:users,username',
                'regex:/^[a-zA-Z0-9_]+$/', // hanya huruf, angka, underscore
            ],
            'password'  => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', // minimal 1 huruf kecil, besar, angka
            ],
            'phone'     => 'nullable|string|max:50|regex:/^[0-9\+\-\s]+$/',
        ], [
            // ✅ Custom messages — lebih informatif untuk frontend
            'full_name.required'  => 'Nama lengkap wajib diisi.',
            'email.required'      => 'Email wajib diisi.',
            'email.email'         => 'Format email tidak valid.',
            'email.unique'        => 'Email sudah terdaftar. Gunakan email lain.',
            'username.required'   => 'Username wajib diisi.',
            'username.unique'     => 'Username sudah digunakan. Pilih username lain.',
            'username.regex'      => 'Username hanya boleh mengandung huruf, angka, dan underscore.',
            'password.required'   => 'Password wajib diisi.',
            'password.min'        => 'Password minimal 8 karakter.',
            'password.confirmed'  => 'Konfirmasi password tidak cocok.',
            'password.regex'      => 'Password harus mengandung minimal 1 huruf besar, 1 huruf kecil, dan 1 angka.',
            'phone.regex'         => 'Format nomor telepon tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal. Periksa kembali data yang dimasukkan.',
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
                'role_id'   => 3,
                'is_active' => true,
            ]);

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
                'message' => 'Registrasi berhasil.',
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
                'message' => 'Registrasi gagal. Silakan coba lagi.',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /* ==========================================================
     * POST /api/auth/login
     * ========================================================== */
    public function login(Request $request)
    {
        // ✅ VALIDASI 1 — Field wajib
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ], [
            'username.required' => 'Username atau email wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        // ✅ VALIDASI 2 — Rate limiting: maks 5 percobaan dalam 60 detik
        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'success' => false,
                'message' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
                'retry_after_seconds' => $seconds,
            ], 429);
        }

        try {
            // ✅ VALIDASI 3 — Cek user exist
            $user = User::where('username', $request->username)
                        ->orWhere('email', $request->username)
                        ->with(['role.permissions.menu', 'companies', 'defaultCompany'])
                        ->first();

            if (!$user) {
                RateLimiter::hit($throttleKey, 60);
                return response()->json([
                    'success' => false,
                    'message' => 'Username atau email tidak ditemukan.',
                    'errors'  => ['username' => ['Username atau email tidak terdaftar.']]
                ], 401);
            }

            // ✅ VALIDASI 4 — Cek password
            if (!Hash::check($request->password, $user->password)) {
                RateLimiter::hit($throttleKey, 60);

                // Hitung sisa percobaan
                $attempts   = RateLimiter::attempts($throttleKey);
                $remaining  = max(0, 5 - $attempts);

                // Log gagal login
                ActivityLog::create([
                    'user_id'     => $user->user_id,
                    'action'      => 'login_failed',
                    'module'      => 'auth',
                    'description' => "Login gagal (password salah) untuk: {$user->username}",
                    'ip_address'  => $request->ip()
                ]);

                return response()->json([
                    'success'            => false,
                    'message'            => 'Password yang Anda masukkan salah.',
                    'remaining_attempts' => $remaining,
                    'errors'             => ['password' => ['Password salah.']]
                ], 401);
            }

            // ✅ VALIDASI 5 — Cek status akun
            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun Anda dinonaktifkan. Hubungi administrator.',
                    'errors'  => ['account' => ['Akun tidak aktif.']]
                ], 403);
            }

            // ✅ Login berhasil — reset rate limiter
            RateLimiter::clear($throttleKey);

            // ✅ VALIDASI 6 — Cek company aktif
            $companies = $this->getActiveCompanies();

            if ($companies->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada company aktif. Hubungi administrator.',
                ], 403);
            }

            // Tentukan selected company
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
                'message' => 'Login berhasil.',
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
                'message' => 'Login gagal. Silakan coba lagi.',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /* ==========================================================
     * POST /api/auth/switch-company
     * ========================================================== */
    public function switchCompany(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,company_id',
        ], [
            'company_id.required' => 'company_id wajib diisi.',
            'company_id.integer'  => 'company_id harus berupa angka.',
            'company_id.exists'   => 'Company tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $user      = $request->user();
            $companyId = (int) $request->company_id;

            // ✅ Pastikan company aktif
            $company = Company::where('company_id', $companyId)
                               ->where('is_active', true)
                               ->first();

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company tidak ditemukan atau tidak aktif.',
                    'errors'  => ['company_id' => ['Company tidak aktif.']]
                ], 404);
            }

            // ✅ Cegah switch ke company yang sama
            $token   = $request->bearerToken();
            $session = UserSession::where('session_token', $token)
                                  ->where('user_id', $user->user_id)
                                  ->where('is_active', true)
                                  ->first();

            if ($session && $session->selected_company_id === $companyId) {
                return response()->json([
                    'success' => false,
                    'message' => "Anda sudah berada di company {$company->company_name}.",
                ], 422);
            }

            $user->update(['default_company_id' => $companyId]);

            $user->companies()->syncWithoutDetaching([
                $companyId => ['is_default' => 1]
            ]);

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

            return response()->json([
                'success' => true,
                'message' => "Berhasil beralih ke {$company->company_name}.",
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
                'message' => 'Gagal berpindah company.',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /* ==========================================================
     * GET /api/auth/me
     * ========================================================== */
    public function me(Request $request)
    {
        try {
            $user = $request->user();

            // ✅ Guard: pastikan token valid dan user masih aktif
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token tidak valid atau sudah kedaluwarsa.',
                ], 401);
            }

            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun Anda dinonaktifkan. Hubungi administrator.',
                ], 403);
            }

            $user->load(['role.permissions.menu', 'companies', 'defaultCompany']);
            $companies = $this->getActiveCompanies();

            $token   = $request->bearerToken();
            $session = \Illuminate\Support\Facades\DB::table('user_sessions')
                ->where('session_token', $token)
                ->where('user_id', $user->user_id)
                ->where('is_active', true)
                ->orderByDesc('login_at')
                ->first();

            $selectedCompanyId = $session?->selected_company_id ?? $user->default_company_id;
            $selectedCompany   = $companies->firstWhere('company_id', $selectedCompanyId)
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
                'message' => 'Gagal mengambil data user.',
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

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token tidak valid atau sudah kedaluwarsa.',
                ], 401);
            }

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
                'message' => 'Logout berhasil.'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Logout gagal.',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
