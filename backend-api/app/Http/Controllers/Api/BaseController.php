<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSession;
use Illuminate\Http\Request;

class BaseController extends Controller
{
    /**
     * Ambil company_id aktif untuk request ini.
     *
     * Prioritas:
     * 1. request->company_id  (eksplisit dari frontend)
     * 2. UserSession.selected_company_id (dari token session aktif)
     * 3. user->default_company_id
     * 4. Abort 403
     */
    protected function getCompanyId(Request $request): int
    {
        $user = $request->user();

        // 1️⃣ Eksplisit dari request
        if ($request->filled('company_id') && (int) $request->company_id > 0) {
            $companyId = (int) $request->company_id;

            // Validasi akses (Super Admin bypass)
            if ($user->role_id !== 1 && !$user->hasAccessToCompany($companyId)) {
                abort(403, 'Anda tidak memiliki akses ke company ini');
            }

            return $companyId;
        }

        // 2️⃣ Dari UserSession berdasarkan token Bearer aktif
        $token = $request->bearerToken();
        if ($token) {
            $session = UserSession::where('session_token', $token)
                ->where('user_id', $user->user_id)
                ->where('is_active', true)
                ->value('selected_company_id');

            if ($session && (int) $session > 0) {
                return (int) $session;
            }
        }

        // 3️⃣ Fallback ke default_company_id user
        if ($user->default_company_id && (int) $user->default_company_id > 0) {
            return (int) $user->default_company_id;
        }

        // 4️⃣ Tidak ditemukan
        abort(403, 'Company tidak ditemukan. Silakan login ulang atau pilih company.');
    }
}
