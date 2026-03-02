<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


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

        // ── 1. Dari header (opsional, untuk multi-company SPA) ─────────
        if ($request->hasHeader('X-Company-ID')) {
            $headerCompanyId = (int) $request->header('X-Company-ID');
            if ($headerCompanyId > 0 && $this->userHasCompanyAccess($user, $headerCompanyId)) {
                return $headerCompanyId;
            }
        }

        // ── 2. Dari session aktif ──────────────────────────────────────
        $session = DB::table('user_sessions')
            ->where('user_id', $user->user_id)
            ->where('is_active', true)
            ->orderByDesc('login_at')
            ->value('selected_company_id');

        if ($session) {
            return (int) $session;
        }

        // ── 3. Fallback ke default_company_id ─────────────────────────
        return (int) ($user->default_company_id ?? 0);
    }

    private function userHasCompanyAccess($user, int $companyId): bool
    {
        return DB::table('user_companies')
            ->where('user_companies.user_id', $user->user_id)
            ->where('user_companies.company_id', $companyId)  // ✅ fully-qualified
            ->exists();
    }
}
