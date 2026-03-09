<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /* =========================================================
     * HELPER: company_id aktif dari session
     * ✅ OPTIMIZED: cache per user 60 detik, hindari query berulang
     * ========================================================= */
    private function getCompanyId(Request $request): ?int
    {
        $user = $request->user();

        return Cache::remember(
            "user_company_{$user->user_id}",
            60,
            function () use ($user) {
                $session = DB::table('user_sessions')
                    ->where('user_id', $user->user_id)
                    ->where('is_active', true)
                    ->orderByDesc('login_at')
                    ->value('selected_company_id'); // ✅ ->value() lebih ringan dari ->first()

                return $session ?? $user->default_company_id;
            }
        );
    }

    /* =========================================================
     * HELPER: batch cek menu access sekaligus (1 query, bukan N query)
     * ✅ OPTIMIZED: sebelumnya 1 query per menu key → sekarang 1 query untuk semua
     * ========================================================= */
    private function getMenuAccess(int $roleId, array $menuKeys): array
    {
        if ($roleId === 1) {
            // Super Admin: semua true
            return array_fill_keys($menuKeys, true);
        }

        $allowed = DB::table('role_menus as rm')
            ->join('menus as m', 'm.menu_id', '=', 'rm.menu_id')
            ->where('rm.role_id', $roleId)
            ->whereIn('m.menu_key', $menuKeys)
            ->where('rm.can_read', 1)
            ->pluck('m.menu_key')
            ->flip()
            ->map(fn() => true)
            ->toArray();

        return array_merge(array_fill_keys($menuKeys, false), $allowed);
    }

    /* =========================================================
     * HELPER: builder supplier invoice base query
     * (dipakai di beberapa tempat, DRY)
     * ========================================================= */
    private function supplierInvoiceBase(int $roleId, ?int $companyId)
    {
        if ($roleId === 1 || !$companyId) {
            return DB::table('supplierinvoices as si');
        }

        return DB::table('supplierinvoices as si')
            ->where(function ($q) use ($companyId) {
                $q->whereIn('si.supplier_po_id', function ($sub) use ($companyId) {
                    $sub->select('supplier_po_id')
                        ->from('supplier_purchase_orders')
                        ->where('company_id', $companyId);
                })->orWhere(function ($q2) use ($companyId) {
                    $q2->whereNull('si.supplier_po_id')
                       ->whereIn('si.supplier_id', function ($sub) use ($companyId) {
                           $sub->select('supplier_id')->distinct()
                               ->from('supplier_purchase_orders')
                               ->where('company_id', $companyId);
                       });
                });
            });
    }

    /* =========================================================
     * GET /api/dashboard/stats
     * ✅ OPTIMIZED:
     *   - 1 query batch per tabel (SUM CASE) gantikan N count() terpisah
     *   - 1 query batch hasMenuAccess untuk semua menu sekaligus
     *   - Cache 2 menit untuk data master (customers, products, suppliers)
     * ========================================================= */
    public function getStats(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);
            $today     = now()->toDateString();

            // ✅ 1 query untuk semua menu check sekaligus
            $access = $this->getMenuAccess($roleId, [
                'quotations', 'purchase_orders', 'invoices',
                'supplier_po', 'supplier_invoices', 'stock_batches',
                'products', 'customers', 'suppliers',
            ]);

            $data = [];

            /* ---- SALES: quotations (1 query, bukan 4) ---- */
            if ($access['quotations']) {
                $row = DB::table('quotations')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                    ->selectRaw("
                        COUNT(*) AS total,
                        SUM(status = 'draft')    AS draft,
                        SUM(status = 'sent')     AS sent,
                        SUM(status = 'approved') AS approved
                    ")
                    ->first();

                $data['quotations'] = [
                    'total'    => (int) $row->total,
                    'draft'    => (int) $row->draft,
                    'sent'     => (int) $row->sent,
                    'approved' => (int) $row->approved,
                ];
            }

            /* ---- SALES: purchase_orders (1 query, bukan 4) ---- */
            if ($access['purchase_orders']) {
                $row = DB::table('purchase_orders')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                    ->selectRaw("
                        COUNT(*) AS total,
                        SUM(status = 'processing') AS processing,
                        SUM(status = 'completed')  AS completed,
                        SUM(status IN ('draft','issued','sent')) AS pending
                    ")
                    ->first();

                $data['purchase_orders'] = [
                    'total'      => (int) $row->total,
                    'processing' => (int) $row->processing,
                    'completed'  => (int) $row->completed,
                    'pending'    => (int) $row->pending,
                ];
            }

            /* ---- FINANCE: invoices (1 query, bukan 5) ---- */
            if ($access['invoices']) {
                $row = DB::table('invoices')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                    ->selectRaw("
                        COUNT(*) AS total,
                        SUM(payment_status = 'unpaid') AS unpaid,
                        SUM(payment_status = 'paid')   AS paid,
                        COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount ELSE 0 END), 0) AS unpaid_amount,
                        SUM(payment_status != 'paid' AND due_date < ?) AS overdue
                    ", [$today])
                    ->first();

                $data['invoices'] = [
                    'total'         => (int) $row->total,
                    'unpaid'        => (int) $row->unpaid,
                    'paid'          => (int) $row->paid,
                    'unpaid_amount' => (float) $row->unpaid_amount,
                    'overdue'       => (int) $row->overdue,
                ];
            }

            /* ---- PURCHASING: supplier_po (1 query, bukan 3) ---- */
            if ($access['supplier_po']) {
                $row = DB::table('supplier_purchase_orders')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                    ->selectRaw("
                        COUNT(*) AS total,
                        SUM(status IN ('draft','issued')) AS pending,
                        SUM(status = 'completed')         AS completed
                    ")
                    ->first();

                $data['supplier_po'] = [
                    'total'     => (int) $row->total,
                    'pending'   => (int) $row->pending,
                    'completed' => (int) $row->completed,
                ];
            }

            /* ---- PURCHASING: supplier_invoices (1 query, bukan 4) ---- */
            if ($access['supplier_invoices']) {
                $siBase = $this->supplierInvoiceBase($roleId, $companyId);

                $row = (clone $siBase)
                    ->selectRaw("
                        COUNT(*) AS total,
                        SUM(si.payment_status = 'unpaid') AS unpaid,
                        SUM(si.payment_status = 'paid')   AS paid,
                        COALESCE(SUM(CASE WHEN si.payment_status = 'unpaid' THEN si.total_amount ELSE 0 END), 0) AS unpaid_amount,
                        SUM(si.payment_status != 'paid' AND si.due_date < ?) AS overdue
                    ", [$today])
                    ->first();

                $data['supplier_invoices'] = [
                    'total'         => (int) $row->total,
                    'unpaid'        => (int) $row->unpaid,
                    'paid'          => (int) $row->paid,
                    'unpaid_amount' => (float) $row->unpaid_amount,
                    'overdue'       => (int) $row->overdue,
                ];
            }

            /* ---- WAREHOUSE: stock_batches (1 query, bukan 4) ---- */
            if ($access['stock_batches']) {
                $expireDate = now()->addDays(30)->toDateString();
                $row = DB::table('stock_batches')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                    ->selectRaw("
                        COUNT(*) AS total,
                        SUM(status = 'active')   AS active,
                        SUM(status = 'expired')  AS expired,
                        SUM(status = 'active' AND expiry_date <= ?) AS near_expiry
                    ", [$expireDate])
                    ->first();

                $data['stock_batches'] = [
                    'total'      => (int) $row->total,
                    'active'     => (int) $row->active,
                    'expired'    => (int) $row->expired,
                    'near_expiry'=> (int) $row->near_expiry,
                ];
            }

            /* ---- MASTER DATA: cache 5 menit (data jarang berubah) ---- */
            if ($access['products']) {
                $total = Cache::remember("stats_products_{$companyId}", 300, fn() =>
                    DB::table('products')->count()
                );
                $data['products'] = ['total' => $total, 'active' => $total];
            }

            if ($access['customers']) {
                $total = Cache::remember("stats_customers_{$companyId}", 300, fn() =>
                    DB::table('customers')->count()
                );
                $data['customers'] = ['total' => $total];
            }

            if ($access['suppliers']) {
                $total = Cache::remember("stats_suppliers_{$companyId}", 300, fn() =>
                    DB::table('suppliers')->count()
                );
                $data['suppliers'] = ['total' => $total, 'active' => $total];
            }

            /* ---- SYSTEM (Super Admin only) ---- */
            if ($roleId === 1) {
                $row = DB::table('user_sessions')
                    ->selectRaw("
                        COUNT(DISTINCT user_id) AS active_users,
                        COUNT(*) AS active_sessions
                    ")
                    ->where('is_active', true)
                    ->first();

                $data['system'] = [
                    'active_users'    => (int) $row->active_users,
                    'active_sessions' => (int) $row->active_sessions,
                    'total_companies' => Cache::remember('stats_companies', 300, fn() => DB::table('companies')->count()),
                    'total_users'     => Cache::remember('stats_users', 300, fn() => DB::table('users')->count()),
                ];
            }

            return response()->json(['success' => true, 'data' => $data]);

        } catch (\Exception $e) {
            \Log::error('Dashboard stats error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load stats',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/recent-transactions
     * ✅ OPTIMIZED: UNION ALL satu query ke DB, bukan 3 query + PHP sort
     * ========================================================= */
    public function getRecentTransactions(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);
            $limit     = min((int) $request->input('limit', 10), 50);

            $access = $this->getMenuAccess($roleId, ['invoices', 'payments', 'supplier-po']);

            $unions = [];

            if ($access['invoices']) {
                $unions[] = DB::table('invoices as i')
                    ->leftJoin('customers as c', 'c.customer_id', '=', 'i.customer_id')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                    ->select(
                        'i.invoice_id as id',
                        DB::raw("'invoice' as type"),
                        'i.invoice_number as number',
                        'c.customer_name',
                        'i.total_amount as amount',
                        'i.payment_status as status',
                        'i.invoice_date as date',
                        'i.created_at'
                    )
                    ->orderByDesc('i.created_at')
                    ->limit($limit);
            }

            if ($access['payments']) {
                $unions[] = DB::table('payments as p')
                    ->leftJoin('invoices as i', 'i.invoice_id', '=', 'p.invoice_id')
                    ->leftJoin('customers as c', 'c.customer_id', '=', 'i.customer_id')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                    ->select(
                        'p.payment_id as id',
                        DB::raw("'payment' as type"),
                        'p.payment_number as number',
                        'c.customer_name',
                        'p.amount',
                        'p.status',
                        'p.payment_date as date',
                        'p.created_at'
                    )
                    ->orderByDesc('p.created_at')
                    ->limit($limit);
            }

            if ($access['supplier-po']) {
                $unions[] = DB::table('supplier_purchase_orders as spo')
                    ->leftJoin('suppliers as s', 's.supplier_id', '=', 'spo.supplier_id')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('spo.company_id', $companyId))
                    ->select(
                        'spo.supplier_po_id as id',
                        DB::raw("'supplier_po' as type"),
                        'spo.po_number as number',
                        's.supplier_name as customer_name',
                        'spo.total_amount as amount',
                        'spo.status',
                        'spo.po_date as date',
                        'spo.created_at'
                    )
                    ->orderByDesc('spo.created_at')
                    ->limit($limit);
            }

            if (empty($unions)) {
                return response()->json(['success' => true, 'data' => []]);
            }

            // ✅ UNION ALL di DB, bukan merge di PHP
            $base = array_shift($unions);
            foreach ($unions as $q) {
                $base = $base->unionAll($q);
            }

            $result = DB::query()
                ->fromSub($base, 'combined')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return response()->json(['success' => true, 'data' => $result]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent transactions',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/weekly-revenue
     * ✅ OPTIMIZED: 2 query SUM jadi 1 query CASE WHEN
     * ========================================================= */
    public function getWeeklyRevenue(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            $access = $this->getMenuAccess($roleId, ['invoices', 'financial-report']);
            if (!$access['invoices'] && !$access['financial-report']) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            $thisStart = Carbon::now()->startOfWeek()->toDateString();
            $thisEnd   = Carbon::now()->endOfWeek()->toDateString();
            $lastStart = Carbon::now()->subWeek()->startOfWeek()->toDateString();
            $lastEnd   = Carbon::now()->subWeek()->endOfWeek()->toDateString();

            // ✅ 1 query gantikan 2 query SUM terpisah
            $row = DB::table('invoices')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                ->whereBetween('invoice_date', [$lastStart, $thisEnd])
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) AS this_week,
                    COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) AS last_week
                ", [$thisStart, $thisEnd, $lastStart, $lastEnd])
                ->first();

            $thisWeek = (float) $row->this_week;
            $lastWeek = (float) $row->last_week;

            return response()->json([
                'success' => true,
                'data'    => [
                    'this_week'         => $thisWeek,
                    'last_week'         => $lastWeek,
                    'percentage_change' => $lastWeek > 0
                        ? round((($thisWeek - $lastWeek) / $lastWeek) * 100, 2) : 0,
                    'is_increase'       => $thisWeek >= $lastWeek,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch weekly revenue',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/omset-margin
     * ✅ OPTIMIZED: prev period digabung dalam 1 query per tabel
     *   (bukan 4 query terpisah untuk current + prev)
     * ========================================================= */
    public function getOmsetMargin(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            $access = $this->getMenuAccess($roleId, ['invoices', 'financial-report']);
            if (!$access['invoices'] && !$access['financial-report']) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            [$startDate, $endDate] = $this->resolveDateRange($request);
            $prevStart = Carbon::parse($startDate)->subMonth()->startOfMonth()->toDateString();
            $prevEnd   = Carbon::parse($startDate)->subMonth()->endOfMonth()->toDateString();

            // ✅ 1 query untuk current + prev period sekaligus
            $omsetRow = DB::table('invoices')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                ->whereBetween('invoice_date', [$prevStart, $endDate])
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN 1              ELSE 0 END), 0) AS total_invoices,
                    COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN subtotal       ELSE 0 END), 0) AS omset,
                    COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN tax_amount     ELSE 0 END), 0) AS total_ppn,
                    COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN total_amount   ELSE 0 END), 0) AS omset_with_tax,
                    COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? AND payment_status = 'paid'   THEN total_amount ELSE 0 END), 0) AS omset_paid,
                    COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? AND payment_status = 'unpaid' THEN total_amount ELSE 0 END), 0) AS omset_unpaid,
                    COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN subtotal       ELSE 0 END), 0) AS prev_omset
                ", [
                    $startDate, $endDate,
                    $startDate, $endDate,
                    $startDate, $endDate,
                    $startDate, $endDate,
                    $startDate, $endDate,
                    $startDate, $endDate,
                    $prevStart, $prevEnd,
                ])
                ->first();

            // ✅ 1 query untuk HPP current + prev sekaligus
            $hppRow = DB::table('stock_out as so')
                ->join('stock_batches as sb', 'sb.batch_id', '=', 'so.batch_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('so.company_id', $companyId))
                ->where('so.transaction_type', 'sale')
                ->whereBetween('so.out_date', [$prevStart, $endDate])
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN so.out_date BETWEEN ? AND ? THEN so.quantity * sb.purchase_price ELSE 0 END), 0) AS hpp,
                    COALESCE(SUM(CASE WHEN so.out_date BETWEEN ? AND ? THEN so.quantity * so.selling_price  ELSE 0 END), 0) AS pendapatan_stock_out,
                    SUM(CASE WHEN so.out_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS total_stock_out,
                    COALESCE(SUM(CASE WHEN so.out_date BETWEEN ? AND ? THEN so.quantity * sb.purchase_price ELSE 0 END), 0) AS prev_hpp
                ", [
                    $startDate, $endDate,
                    $startDate, $endDate,
                    $startDate, $endDate,
                    $prevStart, $prevEnd,
                ])
                ->first();

            $omset      = (float) $omsetRow->omset;
            $hpp        = (float) $hppRow->hpp;
            $margin     = $omset - $hpp;
            $prevOmset  = (float) $omsetRow->prev_omset;
            $prevHpp    = (float) $hppRow->prev_hpp;
            $prevMargin = $prevOmset - $prevHpp;

            return response()->json([
                'success' => true,
                'period'  => ['start' => $startDate, 'end' => $endDate],
                'data'    => [
                    'omset'           => $omset,
                    'omset_with_tax'  => (float) $omsetRow->omset_with_tax,
                    'total_ppn'       => (float) $omsetRow->total_ppn,
                    'omset_paid'      => (float) $omsetRow->omset_paid,
                    'omset_unpaid'    => (float) $omsetRow->omset_unpaid,
                    'total_invoices'  => (int)   $omsetRow->total_invoices,
                    'hpp'             => $hpp,
                    'margin'          => $margin,
                    'margin_percent'  => $omset > 0 ? round(($margin / $omset) * 100, 2) : 0,
                    'total_stock_out' => (int) $hppRow->total_stock_out,
                    'prev_omset'      => $prevOmset,
                    'prev_hpp'        => $prevHpp,
                    'prev_margin'     => $prevMargin,
                    'omset_growth'    => $prevOmset > 0 ? round((($omset - $prevOmset) / $prevOmset) * 100, 2) : 0,
                    'margin_growth'   => $prevMargin > 0 ? round((($margin - $prevMargin) / $prevMargin) * 100, 2) : 0,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch omset & margin',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/omset-by-type
     * ✅ OPTIMIZED: query omsetNoType pakai LEFT JOIN bukan NOT EXISTS
     * ========================================================= */
    public function getOmsetByTypeCode(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            $access = $this->getMenuAccess($roleId, ['invoices', 'financial-report']);
            if (!$access['invoices'] && !$access['financial-report']) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            [$startDate, $endDate] = $this->resolveDateRange($request);

            // ✅ Semua jenis omset sekaligus via LEFT JOIN (termasuk NULL type_code)
            $allOmset = DB::table('invoices as i')
                ->leftJoin('purchase_orders as po', 'po.po_id', '=', 'i.po_id')
                ->leftJoin('activity_types as at', 'at.activity_type_id', '=', 'po.activity_type_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                ->whereBetween('i.invoice_date', [$startDate, $endDate])
                ->selectRaw("
                    COALESCE(at.type_code, 'OTHER') AS type_code,
                    COALESCE(at.type_name, 'Lainnya') AS type_name,
                    COUNT(i.invoice_id)              AS total_invoices,
                    COALESCE(SUM(i.subtotal), 0)     AS omset,
                    COALESCE(SUM(i.total_amount), 0) AS omset_with_tax
                ")
                ->groupByRaw("COALESCE(at.type_code, 'OTHER'), COALESCE(at.type_name, 'Lainnya')")
                ->get()
                ->keyBy('type_code');

            $hppByType = DB::table('stock_out as so')
                ->join('stock_batches as sb',   'sb.batch_id',          '=', 'so.batch_id')
                ->join('delivery_notes as dn',  'dn.delivery_note_id',  '=', 'so.delivery_note_id')
                ->join('invoices as i',          'i.invoice_id',         '=', 'dn.invoice_id')
                ->join('purchase_orders as po',  'po.po_id',             '=', 'i.po_id')
                ->join('activity_types as at',   'at.activity_type_id',  '=', 'po.activity_type_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('so.company_id', $companyId))
                ->where('so.transaction_type', 'sale')
                ->whereBetween('so.out_date', [$startDate, $endDate])
                ->selectRaw("
                    at.type_code,
                    COALESCE(SUM(so.quantity * sb.purchase_price), 0) AS hpp
                ")
                ->groupBy('at.type_code')
                ->get()
                ->keyBy('type_code');

            $allTypeCodes = ['TENDER', 'RETAIL', 'ONLINE_SHOP', 'OTHER'];
            $result       = [];
            $grandOmset   = 0;
            $grandMargin  = 0;

            foreach ($allTypeCodes as $code) {
                $omset  = (float) ($allOmset[$code]->omset ?? 0);
                $hpp    = $code === 'OTHER' ? 0 : (float) ($hppByType[$code]->hpp ?? 0);
                $margin = $omset - $hpp;
                $grandOmset  += $omset;
                $grandMargin += $margin;

                $result[] = [
                    'type_code'      => $code,
                    'type_name'      => $allOmset[$code]->type_name ?? ($code === 'OTHER' ? 'Lainnya' : $code),
                    'total_invoices' => (int) ($allOmset[$code]->total_invoices ?? 0),
                    'omset'          => $omset,
                    'omset_with_tax' => (float) ($allOmset[$code]->omset_with_tax ?? 0),
                    'hpp'            => $hpp,
                    'margin'         => $margin,
                    'margin_percent' => $omset > 0 ? round(($margin / $omset) * 100, 2) : 0,
                    'omset_share'    => 0, // dihitung setelah grand total diketahui
                ];
            }

            $grandOmset = max($grandOmset, 1);
            foreach ($result as &$row) {
                $row['omset_share'] = round(($row['omset'] / $grandOmset) * 100, 2);
            }
            unset($row);

            return response()->json([
                'success' => true,
                'period'  => ['start' => $startDate, 'end' => $endDate],
                'summary' => ['grand_omset' => $grandOmset, 'grand_margin' => $grandMargin],
                'data'    => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch omset by type code',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/monthly-margin
     * ✅ TIDAK ADA PERUBAHAN LOGIKA — sudah efisien (2 query group by)
     *    Hanya tambah cache 5 menit untuk this_year / last_year
     * ========================================================= */
    public function getMonthlyMargin(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);
            $typeCode  = $request->input('type_code');
            $period    = $request->input('period', 'this_month');

            $access = $this->getMenuAccess($roleId, ['invoices', 'financial-report']);
            if (!$access['invoices'] && !$access['financial-report']) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            // ✅ Cache untuk periode yang tidak berubah (tahun lalu)
            $cacheTtl = in_array($period, ['last_year', 'last_month']) ? 3600 : 0;
            $cacheKey = "monthly_margin_{$roleId}_{$companyId}_{$period}_{$typeCode}";

            $result = $cacheTtl > 0
                ? Cache::remember($cacheKey, $cacheTtl, fn() => $this->buildMonthlyMargin($request, $roleId, $companyId, $typeCode))
                : $this->buildMonthlyMargin($request, $roleId, $companyId, $typeCode);

            [$startDate, $endDate] = $this->resolveDateRange($request);

            return response()->json([
                'success'   => true,
                'type_code' => $typeCode ?? 'ALL',
                'period'    => ['start' => $startDate, 'end' => $endDate],
                'data'      => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch monthly margin',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function buildMonthlyMargin(Request $request, int $roleId, ?int $companyId, ?string $typeCode): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($request);
        $startCarbon = Carbon::parse($startDate)->startOfMonth();
        $endCarbon   = Carbon::parse($endDate)->endOfMonth();

        $omsetQuery = DB::table('invoices as i')
            ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
            ->whereBetween('i.invoice_date', [$startCarbon->toDateString(), $endCarbon->toDateString()]);

        if ($typeCode) {
            $omsetQuery
                ->join('purchase_orders as po', 'po.po_id', '=', 'i.po_id')
                ->join('activity_types as at', 'at.activity_type_id', '=', 'po.activity_type_id')
                ->where('at.type_code', $typeCode);
        }

        $omsetRaw = $omsetQuery
            ->selectRaw('YEAR(i.invoice_date) AS year, MONTH(i.invoice_date) AS month, COALESCE(SUM(i.subtotal), 0) AS omset, COUNT(i.invoice_id) AS invoice_count')
            ->groupByRaw('YEAR(i.invoice_date), MONTH(i.invoice_date)')
            ->get()
            ->keyBy(fn($r) => "{$r->year}-{$r->month}");

        $hppQuery = DB::table('stock_out as so')
            ->join('stock_batches as sb', 'sb.batch_id', '=', 'so.batch_id')
            ->when($roleId !== 1 && $companyId, fn($q) => $q->where('so.company_id', $companyId))
            ->where('so.transaction_type', 'sale')
            ->whereBetween('so.out_date', [$startCarbon->toDateString(), $endCarbon->toDateString()]);

        if ($typeCode) {
            $hppQuery
                ->join('delivery_notes as dn', 'dn.delivery_note_id', '=', 'so.delivery_note_id')
                ->join('invoices as i',         'i.invoice_id',        '=', 'dn.invoice_id')
                ->join('purchase_orders as po', 'po.po_id',            '=', 'i.po_id')
                ->join('activity_types as at',  'at.activity_type_id', '=', 'po.activity_type_id')
                ->where('at.type_code', $typeCode);
        }

        $hppRaw = $hppQuery
            ->selectRaw('YEAR(so.out_date) AS year, MONTH(so.out_date) AS month, COALESCE(SUM(so.quantity * sb.purchase_price), 0) AS hpp')
            ->groupByRaw('YEAR(so.out_date), MONTH(so.out_date)')
            ->get()
            ->keyBy(fn($r) => "{$r->year}-{$r->month}");

        $result  = [];
        $current = $startCarbon->copy();

        while ($current->lte($endCarbon)) {
            $key    = "{$current->year}-{$current->month}";
            $omset  = (float) ($omsetRaw[$key]->omset ?? 0);
            $hpp    = (float) ($hppRaw[$key]->hpp ?? 0);
            $margin = $omset - $hpp;

            $result[] = [
                'year'           => $current->year,
                'month'          => str_pad($current->month, 2, '0', STR_PAD_LEFT),
                'label'          => $current->translatedFormat('M Y'),
                'omset'          => $omset,
                'hpp'            => $hpp,
                'margin'         => $margin,
                'margin_percent' => $omset > 0 ? round(($margin / $omset) * 100, 2) : 0,
                'invoice_count'  => (int) ($omsetRaw[$key]->invoice_count ?? 0),
            ];

            $current->addMonth();
        }

        return $result;
    }

    /* =========================================================
     * GET /api/dashboard/expiry-alerts
     * ✅ TIDAK BERUBAH — sudah optimal (single JOIN query, limit 20)
     * ========================================================= */
    public function getExpiryAlerts(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            $access = $this->getMenuAccess($roleId, ['stock-batches']);
            if (!$access['stock-batches']) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            $alerts = DB::table('expiry_alerts as ea')
                ->join('stock_batches as sb', 'sb.batch_id', '=', 'ea.batch_id')
                ->join('products as p', 'p.product_id', '=', 'sb.product_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('sb.company_id', $companyId))
                ->where('ea.status', 'pending')
                ->orderBy('ea.expiry_date')
                ->limit(20)
                ->select(
                    'ea.alert_id', 'p.product_name', 'p.product_code',
                    'sb.batch_number', 'sb.quantity_available',
                    'ea.expiry_date', 'ea.alert_date', 'ea.status'
                )
                ->get();

            return response()->json(['success' => true, 'data' => $alerts]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch expiry alerts',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/monthly-revenue (legacy — tidak diubah)
     * GET /api/dashboard/top-customers   (tidak diubah)
     * GET /api/dashboard/payment-methods (tidak diubah)
     * ========================================================= */
    public function getMonthlyRevenue(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);
            $months    = min((int) $request->input('months', 6), 24);

            $access = $this->getMenuAccess($roleId, ['invoices', 'financial-report']);
            if (!$access['invoices'] && !$access['financial-report']) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            $raw = DB::table('invoices')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                ->where('invoice_date', '>=', Carbon::now()->subMonths($months)->startOfMonth())
                ->selectRaw('MONTH(invoice_date) as month, YEAR(invoice_date) as year, SUM(total_amount) as total_amount, COUNT(*) as invoice_count')
                ->groupByRaw('year, month')
                ->orderByRaw('year ASC, month ASC')
                ->get();

            $result = [];
            for ($i = $months - 1; $i >= 0; $i--) {
                $date  = Carbon::now()->subMonths($i);
                $found = $raw->first(fn($r) => $r->month == $date->month && $r->year == $date->year);
                $result[] = [
                    'month'         => str_pad($date->month, 2, '0', STR_PAD_LEFT),
                    'year'          => $date->year,
                    'label'         => $date->translatedFormat('M Y'),
                    'total_amount'  => $found?->total_amount ?? 0,
                    'invoice_count' => $found?->invoice_count ?? 0,
                ];
            }

            return response()->json(['success' => true, 'data' => $result]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'message' => 'Failed to fetch monthly revenue',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getTopCustomers(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);
            $limit     = min((int) $request->input('limit', 5), 20);

            $access = $this->getMenuAccess($roleId, ['invoices', 'customers']);
            if (!$access['invoices'] && !$access['customers']) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            $result = DB::table('customers as c')
                ->join('invoices as i', 'i.customer_id', '=', 'c.customer_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                ->selectRaw('c.customer_id, c.customer_name, COUNT(i.invoice_id) as total_invoices, SUM(i.total_amount) as total_amount, MAX(i.invoice_date) as last_transaction')
                ->groupBy('c.customer_id', 'c.customer_name')
                ->orderByDesc('total_amount')
                ->limit($limit)
                ->get();

            return response()->json(['success' => true, 'data' => $result]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'message' => 'Failed to fetch top customers',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getPaymentMethodStats(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            $access = $this->getMenuAccess($roleId, ['payments', 'financial-report']);
            if (!$access['payments'] && !$access['financial-report']) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            $rows = DB::table('payments as p')
                ->join('invoices as i', 'i.invoice_id', '=', 'p.invoice_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                ->where('p.status', 'success')
                ->selectRaw('p.payment_method, COUNT(*) as count, SUM(p.amount) as total_amount')
                ->groupBy('p.payment_method')
                ->get();

            $total  = $rows->sum('total_amount');
            $result = $rows->map(fn($r) => [
                'payment_method' => $r->payment_method,
                'count'          => $r->count,
                'total_amount'   => $r->total_amount,
                'percentage'     => $total > 0 ? round(($r->total_amount / $total) * 100, 2) : 0,
            ]);

            return response()->json(['success' => true, 'data' => $result]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'message' => 'Failed to fetch payment method stats',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * PRIVATE HELPER: resolveDateRange
     * ========================================================= */
    private function resolveDateRange(Request $request): array
    {
        $period = $request->input('period', 'this_month');

        return match ($period) {
            'last_month' => [
                Carbon::now()->subMonth()->startOfMonth()->toDateString(),
                Carbon::now()->subMonth()->endOfMonth()->toDateString(),
            ],
            'this_year' => [
                Carbon::now()->startOfYear()->toDateString(),
                Carbon::now()->endOfYear()->toDateString(),
            ],
            'last_year' => [
                Carbon::now()->subYear()->startOfYear()->toDateString(),
                Carbon::now()->subYear()->endOfYear()->toDateString(),
            ],
            'custom' => [
                $request->input('start_date', Carbon::now()->startOfMonth()->toDateString()),
                $request->input('end_date',   Carbon::now()->toDateString()),
            ],
            default => [
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->toDateString(),
            ],
        };
    }
}
