<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /* =========================================================
     * HELPER: company_id aktif dari session
     * ========================================================= */
    private function getCompanyId(Request $request): ?int
    {
        $user = $request->user();

        $session = DB::table('user_sessions')
            ->where('user_id', $user->user_id)
            ->where('is_active', true)
            ->orderByDesc('login_at')
            ->first();

        return $session?->selected_company_id ?? $user->default_company_id;
    }

    /* =========================================================
     * HELPER: cek apakah user punya akses ke menu tertentu
     * ========================================================= */
    private function hasMenuAccess(int $roleId, string $menuKey): bool
    {
        if ($roleId === 1) return true; // Super Admin bypass

        return DB::table('role_menus as rm')
            ->join('menus as m', 'm.menu_id', '=', 'rm.menu_id')
            ->where('rm.role_id', $roleId)
            ->where('m.menu_key', $menuKey)
            ->where('rm.can_read', 1)
            ->exists();
    }

    /* =========================================================
     * HELPER: scope query by company (Super Admin = semua)
     * ========================================================= */
    private function scopeCompany($query, string $table, int $roleId, ?int $companyId)
    {
        if ($roleId !== 1 && $companyId) {
            $query->where("{$table}.company_id", $companyId);
        }
        return $query;
    }

    /* =========================================================
     * GET /api/dashboard/stats
     * ========================================================= */
    public function getStats(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            $data = [];

            /* ---- SALES ---- */
            if ($this->hasMenuAccess($roleId, 'quotations')) {
                $qBase = DB::table('quotations')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['quotations'] = [
                    'total'    => (clone $qBase)->count(),
                    'draft'    => (clone $qBase)->where('status', 'draft')->count(),
                    'sent'     => (clone $qBase)->where('status', 'sent')->count(),
                    'approved' => (clone $qBase)->where('status', 'approved')->count(),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'purchase_orders')) {
                $poBase = DB::table('purchase_orders')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['purchase_orders'] = [
                    'total'       => (clone $poBase)->count(),
                    'processing'  => (clone $poBase)->where('status', 'processing')->count(),
                    'completed'   => (clone $poBase)->where('status', 'completed')->count(),
                    'pending'     => (clone $poBase)->whereIn('status', ['draft', 'issued', 'sent'])->count(),
                ];
            }

            /* ---- FINANCE ---- */
            if ($this->hasMenuAccess($roleId, 'invoices')) {
                $invBase = DB::table('invoices')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['invoices'] = [
                    'total'         => (clone $invBase)->count(),
                    'unpaid'        => (clone $invBase)->where('payment_status', 'unpaid')->count(),
                    'paid'          => (clone $invBase)->where('payment_status', 'paid')->count(),
                    'unpaid_amount' => (clone $invBase)->where('payment_status', 'unpaid')->sum('total_amount'),
                    'overdue'       => (clone $invBase)
                        ->where('payment_status', '!=', 'paid')
                        ->where('due_date', '<', now()->toDateString())
                        ->count(),
                ];
            }

            /* ---- PURCHASING ---- */
            if ($this->hasMenuAccess($roleId, 'supplier_po')) {
                $spoBase = DB::table('supplier_purchase_orders')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['supplier_po'] = [
                    'total'     => (clone $spoBase)->count(),
                    'pending'   => (clone $spoBase)->whereIn('status', ['draft', 'issued'])->count(),
                    'completed' => (clone $spoBase)->where('status', 'completed')->count(),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'supplier_invoices')) {
                /*
                 * ✅ FIX: "Cardinality violation: Subquery returns more than 1 row"
                 *
                 * SEBELUM (❌ BUGGY):
                 *   ->where('supplier_id', function($sub) { $sub->select('supplier_id')... })
                 *   Operator '=' hanya bisa menampung 1 baris. Jika subquery
                 *   mengembalikan >1 baris → MySQL error 1242.
                 *
                 * SESUDAH (✅ FIXED):
                 *   Gunakan JOIN langsung ke supplier_purchase_orders agar query
                 *   lebih efisien dan tepat.
                 *
                 *   STRATEGI FILTER:
                 *   - supplierinvoices TIDAK punya company_id
                 *   - Filter via supplier_po_id → supplier_purchase_orders.company_id
                 *     (paling akurat, karena PO memang milik company tertentu)
                 *   - Fallback: invoices tanpa PO di-include jika supplier_id
                 *     pernah bertransaksi dengan company (via whereIn, bukan '=')
                 *
                 *   Pendekatan paling aman & correct untuk dashboard stats:
                 *   JOIN ke supplier_purchase_orders, group by supplier_invoice_id
                 *   agar tidak double-count jika satu invoice punya banyak PO.
                 */
                if ($roleId !== 1 && $companyId) {
                    $siBase = DB::table('supplierinvoices as si')
                        ->where(function ($q) use ($companyId) {
                            // Kasus 1: invoice punya supplier_po_id → filter via PO company_id
                            $q->whereIn('si.supplier_po_id', function ($sub) use ($companyId) {
                                $sub->select('supplier_po_id')
                                    ->from('supplier_purchase_orders')
                                    ->where('company_id', $companyId);
                            })
                            // Kasus 2: invoice tidak punya PO (supplier_po_id = NULL)
                            // → filter via supplier_id IN (supplier yang pernah ada PO di company ini)
                            ->orWhere(function ($q2) use ($companyId) {
                                $q2->whereNull('si.supplier_po_id')
                                   ->whereIn('si.supplier_id', function ($sub) use ($companyId) {
                                       $sub->select('supplier_id')
                                           ->from('supplier_purchase_orders')
                                           ->where('company_id', $companyId)
                                           ->distinct(); // ✅ distinct agar tidak cardinality issue
                                   });
                            });
                        });
                } else {
                    // Super Admin: semua invoice
                    $siBase = DB::table('supplierinvoices as si');
                }

                $data['supplier_invoices'] = [
                    'total'         => (clone $siBase)->count(),
                    'unpaid'        => (clone $siBase)->where('si.payment_status', 'unpaid')->count(),
                    'unpaid_amount' => (clone $siBase)->where('si.payment_status', 'unpaid')->sum('si.total_amount'),
                    'paid'          => (clone $siBase)->where('si.payment_status', 'paid')->count(),
                ];
            }

            /* ---- WAREHOUSE ---- */
            if ($this->hasMenuAccess($roleId, 'stock_batches')) {
                $sbBase = DB::table('stock_batches')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['stock_batches'] = [
                    'total'    => (clone $sbBase)->count(),
                    'active'   => (clone $sbBase)->where('status', 'active')->count(),
                    'expiring' => (clone $sbBase)
                        ->where('status', 'active')
                        ->where('expiry_date', '<=', now()->addDays(30)->toDateString())
                        ->count(),
                    'expired'  => (clone $sbBase)->where('status', 'expired')->count(),
                ];
            }

            /* ---- MASTER DATA ---- */
            if ($this->hasMenuAccess($roleId, 'products')) {
                $totalProducts = DB::table('products')->count();
                $data['products'] = [
                    'total'  => $totalProducts,
                    'active' => $totalProducts,
                ];
            }

            if ($this->hasMenuAccess($roleId, 'customers')) {
                $data['customers'] = [
                    'total' => DB::table('customers')->count(),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'suppliers')) {
                $totalSuppliers = DB::table('suppliers')->count();
                $data['suppliers'] = [
                    'total'  => $totalSuppliers,
                    'active' => $totalSuppliers,
                ];
            }

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);

        } catch (\Exception $e) {
            \Log::error('Dashboard stats error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load stats',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/recent-transactions
     * ========================================================= */
    public function getRecentTransactions(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);
            $limit     = min((int) $request->input('limit', 10), 50);

            $transactions = collect();

            if ($this->hasMenuAccess($roleId, 'invoices')) {
                $invoices = DB::table('invoices as i')
                    ->leftJoin('customers as c', 'c.customer_id', '=', 'i.customer_id')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                    ->orderByDesc('i.created_at')
                    ->limit($limit)
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
                    ->get();

                $transactions = $transactions->merge($invoices);
            }

            if ($this->hasMenuAccess($roleId, 'payments')) {
                $payments = DB::table('payments as p')
                    ->leftJoin('invoices as i', 'i.invoice_id', '=', 'p.invoice_id')
                    ->leftJoin('customers as c', 'c.customer_id', '=', 'i.customer_id')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                    ->orderByDesc('p.created_at')
                    ->limit($limit)
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
                    ->get();

                $transactions = $transactions->merge($payments);
            }

            if ($this->hasMenuAccess($roleId, 'supplier-po')) {
                $spoTx = DB::table('supplier_purchase_orders as spo')
                    ->leftJoin('suppliers as s', 's.supplier_id', '=', 'spo.supplier_id')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('spo.company_id', $companyId))
                    ->orderByDesc('spo.created_at')
                    ->limit($limit)
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
                    ->get();

                $transactions = $transactions->merge($spoTx);
            }

            $result = $transactions
                ->sortByDesc('created_at')
                ->take($limit)
                ->values();

            return response()->json([
                'success' => true,
                'data'    => $result,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent transactions',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/monthly-revenue
     * ========================================================= */
    public function getMonthlyRevenue(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);
            $months    = min((int) $request->input('months', 6), 24);

            if (!$this->hasMenuAccess($roleId, 'invoices') && !$this->hasMenuAccess($roleId, 'financial-report')) {
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

            return response()->json(['success' => true, 'data' => $result], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch monthly revenue',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/top-customers
     * ========================================================= */
    public function getTopCustomers(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);
            $limit     = min((int) $request->input('limit', 5), 20);

            if (!$this->hasMenuAccess($roleId, 'invoices') && !$this->hasMenuAccess($roleId, 'customers')) {
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

            return response()->json(['success' => true, 'data' => $result], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch top customers',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/payment-methods
     * ========================================================= */
    public function getPaymentMethodStats(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            if (!$this->hasMenuAccess($roleId, 'payments') && !$this->hasMenuAccess($roleId, 'financial-report')) {
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

            return response()->json(['success' => true, 'data' => $result], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment method stats',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/weekly-revenue
     * ========================================================= */
    public function getWeeklyRevenue(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            if (!$this->hasMenuAccess($roleId, 'invoices') && !$this->hasMenuAccess($roleId, 'financial-report')) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            $base = fn() => DB::table('invoices')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

            $thisWeek = $base()
                ->whereBetween('invoice_date', [
                    Carbon::now()->startOfWeek()->toDateString(),
                    Carbon::now()->endOfWeek()->toDateString(),
                ])->sum('total_amount');

            $lastWeek = $base()
                ->whereBetween('invoice_date', [
                    Carbon::now()->subWeek()->startOfWeek()->toDateString(),
                    Carbon::now()->subWeek()->endOfWeek()->toDateString(),
                ])->sum('total_amount');

            return response()->json([
                'success' => true,
                'data'    => [
                    'this_week'         => $thisWeek,
                    'last_week'         => $lastWeek,
                    'percentage_change' => $lastWeek > 0
                        ? round((($thisWeek - $lastWeek) / $lastWeek) * 100, 2)
                        : 0,
                    'is_increase'       => $thisWeek >= $lastWeek,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch weekly revenue',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/expiry-alerts
     * ========================================================= */
    public function getExpiryAlerts(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            if (!$this->hasMenuAccess($roleId, 'stock-batches')) {
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
                    'ea.alert_id',
                    'p.product_name',
                    'p.product_code',
                    'sb.batch_number',
                    'sb.quantity_available',
                    'ea.expiry_date',
                    'ea.alert_date',
                    'ea.status'
                )
                ->get();

            return response()->json(['success' => true, 'data' => $alerts], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch expiry alerts',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/dashboard/omset-margin
     * ========================================================= */
    public function getOmsetMargin(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            if (!$this->hasMenuAccess($roleId, 'invoices') && !$this->hasMenuAccess($roleId, 'financial-report')) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            [$startDate, $endDate] = $this->resolveDateRange($request);

            $omsetRow = DB::table('invoices')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                ->whereBetween('invoice_date', [$startDate, $endDate])
                ->selectRaw('
                    COUNT(*)                        AS total_invoices,
                    COALESCE(SUM(subtotal), 0)      AS omset,
                    COALESCE(SUM(tax_amount), 0)    AS total_ppn,
                    COALESCE(SUM(total_amount), 0)  AS omset_with_tax,
                    COALESCE(SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE 0 END), 0) AS omset_paid,
                    COALESCE(SUM(CASE WHEN payment_status = "unpaid" THEN total_amount ELSE 0 END), 0) AS omset_unpaid
                ')
                ->first();

            $hppRow = DB::table('stock_out as so')
                ->join('stock_batches as sb', 'sb.batch_id', '=', 'so.batch_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('so.company_id', $companyId))
                ->where('so.transaction_type', 'sale')
                ->whereBetween('so.out_date', [$startDate, $endDate])
                ->selectRaw('
                    COALESCE(SUM(so.quantity * sb.purchase_price), 0)  AS hpp,
                    COALESCE(SUM(so.quantity * so.selling_price), 0)   AS pendapatan_stock_out,
                    COUNT(*)                                            AS total_stock_out
                ')
                ->first();

            $omset         = (float) $omsetRow->omset;
            $hpp           = (float) $hppRow->hpp;
            $margin        = $omset - $hpp;
            $marginPercent = $omset > 0 ? round(($margin / $omset) * 100, 2) : 0;

            $prevStart = Carbon::parse($startDate)->subMonth()->startOfMonth()->toDateString();
            $prevEnd   = Carbon::parse($startDate)->subMonth()->endOfMonth()->toDateString();

            $prevOmset = (float) DB::table('invoices')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId))
                ->whereBetween('invoice_date', [$prevStart, $prevEnd])
                ->sum('subtotal');

            $prevHpp = (float) DB::table('stock_out as so')
                ->join('stock_batches as sb', 'sb.batch_id', '=', 'so.batch_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('so.company_id', $companyId))
                ->where('so.transaction_type', 'sale')
                ->whereBetween('so.out_date', [$prevStart, $prevEnd])
                ->sum(DB::raw('so.quantity * sb.purchase_price'));

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
                    'total_invoices'  => (int) $omsetRow->total_invoices,
                    'hpp'             => $hpp,
                    'margin'          => $margin,
                    'margin_percent'  => $marginPercent,
                    'total_stock_out' => (int) $hppRow->total_stock_out,
                    'prev_omset'      => $prevOmset,
                    'prev_hpp'        => $prevHpp,
                    'prev_margin'     => $prevMargin,
                    'omset_growth'    => $prevOmset > 0
                        ? round((($omset - $prevOmset) / $prevOmset) * 100, 2) : 0,
                    'margin_growth'   => $prevMargin > 0
                        ? round((($margin - $prevMargin) / $prevMargin) * 100, 2) : 0,
                ],
            ], 200);

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
     * ========================================================= */
    public function getOmsetByTypeCode(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            if (!$this->hasMenuAccess($roleId, 'invoices') && !$this->hasMenuAccess($roleId, 'financial-report')) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            [$startDate, $endDate] = $this->resolveDateRange($request);

            $omsetByType = DB::table('invoices as i')
                ->join('purchase_orders as po', 'po.po_id', '=', 'i.po_id')
                ->join('activity_types as at', 'at.activity_type_id', '=', 'po.activity_type_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                ->whereBetween('i.invoice_date', [$startDate, $endDate])
                ->selectRaw('
                    at.type_code,
                    at.type_name,
                    COUNT(i.invoice_id)              AS total_invoices,
                    COALESCE(SUM(i.subtotal), 0)     AS omset,
                    COALESCE(SUM(i.total_amount), 0) AS omset_with_tax
                ')
                ->groupBy('at.type_code', 'at.type_name')
                ->get()
                ->keyBy('type_code');

            $omsetNoType = DB::table('invoices as i')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                ->whereBetween('i.invoice_date', [$startDate, $endDate])
                ->where(function ($q) {
                    $q->whereNull('i.po_id')
                      ->orWhereNotExists(function ($sub) {
                          $sub->from('purchase_orders as po')
                              ->join('activity_types as at', 'at.activity_type_id', '=', 'po.activity_type_id')
                              ->whereColumn('po.po_id', 'i.po_id');
                      });
                })
                ->selectRaw('
                    COUNT(invoice_id)              AS total_invoices,
                    COALESCE(SUM(subtotal), 0)     AS omset,
                    COALESCE(SUM(total_amount), 0) AS omset_with_tax
                ')
                ->first();

            $hppByType = DB::table('stock_out as so')
                ->join('stock_batches as sb',   'sb.batch_id',         '=', 'so.batch_id')
                ->join('delivery_notes as dn',  'dn.delivery_note_id', '=', 'so.delivery_note_id')
                ->join('invoices as i',         'i.invoice_id',        '=', 'dn.invoice_id')
                ->join('purchase_orders as po', 'po.po_id',            '=', 'i.po_id')
                ->join('activity_types as at',  'at.activity_type_id', '=', 'po.activity_type_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('so.company_id', $companyId))
                ->where('so.transaction_type', 'sale')
                ->whereBetween('so.out_date', [$startDate, $endDate])
                ->selectRaw('
                    at.type_code,
                    COALESCE(SUM(so.quantity * sb.purchase_price), 0) AS hpp
                ')
                ->groupBy('at.type_code')
                ->get()
                ->keyBy('type_code');

            $allTypeCodes = ['TENDER', 'RETAIL', 'ONLINE_SHOP'];
            $result       = [];
            $grandOmset   = 0;
            $grandMargin  = 0;

            foreach ($allTypeCodes as $code) {
                $omset  = (float) ($omsetByType[$code]->omset ?? 0);
                $hpp    = (float) ($hppByType[$code]->hpp    ?? 0);
                $margin = $omset - $hpp;
                $grandOmset  += $omset;
                $grandMargin += $margin;

                $result[] = [
                    'type_code'      => $code,
                    'type_name'      => $omsetByType[$code]->type_name ?? $code,
                    'total_invoices' => (int) ($omsetByType[$code]->total_invoices ?? 0),
                    'omset'          => $omset,
                    'omset_with_tax' => (float) ($omsetByType[$code]->omset_with_tax ?? 0),
                    'hpp'            => $hpp,
                    'margin'         => $margin,
                    'margin_percent' => $omset > 0 ? round(($margin / $omset) * 100, 2) : 0,
                ];
            }

            $omsetLain = (float) $omsetNoType->omset;
            $result[]  = [
                'type_code'      => 'OTHER',
                'type_name'      => 'Lainnya',
                'total_invoices' => (int) $omsetNoType->total_invoices,
                'omset'          => $omsetLain,
                'omset_with_tax' => (float) $omsetNoType->omset_with_tax,
                'hpp'            => 0,
                'margin'         => $omsetLain,
                'margin_percent' => 100,
            ];

            $grandOmset = max($grandOmset + $omsetLain, 1);
            foreach ($result as &$row) {
                $row['omset_share'] = round(($row['omset'] / $grandOmset) * 100, 2);
            }
            unset($row);

            return response()->json([
                'success' => true,
                'period'  => ['start' => $startDate, 'end' => $endDate],
                'summary' => [
                    'grand_omset'  => $grandOmset,
                    'grand_margin' => $grandMargin + $omsetLain,
                ],
                'data' => $result,
            ], 200);

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
     * ========================================================= */
    public function getMonthlyMargin(Request $request)
    {
        try {
            $user      = $request->user();
            $roleId    = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);
            $typeCode  = $request->input('type_code');

            if (!$this->hasMenuAccess($roleId, 'invoices') && !$this->hasMenuAccess($roleId, 'financial-report')) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            [$startDate, $endDate] = $this->resolveDateRange($request);
            $startCarbon = Carbon::parse($startDate)->startOfMonth();
            $endCarbon   = Carbon::parse($endDate)->endOfMonth();

            $omsetQuery = DB::table('invoices as i')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                ->whereBetween('i.invoice_date', [
                    $startCarbon->toDateString(),
                    $endCarbon->toDateString(),
                ]);

            if ($typeCode) {
                $omsetQuery
                    ->join('purchase_orders as po', 'po.po_id', '=', 'i.po_id')
                    ->join('activity_types as at', 'at.activity_type_id', '=', 'po.activity_type_id')
                    ->where('at.type_code', $typeCode);
            }

            $omsetRaw = $omsetQuery
                ->selectRaw('
                    YEAR(i.invoice_date)         AS year,
                    MONTH(i.invoice_date)        AS month,
                    COALESCE(SUM(i.subtotal), 0) AS omset,
                    COUNT(i.invoice_id)          AS invoice_count
                ')
                ->groupByRaw('YEAR(i.invoice_date), MONTH(i.invoice_date)')
                ->get()
                ->keyBy(fn($r) => "{$r->year}-{$r->month}");

            $hppQuery = DB::table('stock_out as so')
                ->join('stock_batches as sb', 'sb.batch_id', '=', 'so.batch_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('so.company_id', $companyId))
                ->where('so.transaction_type', 'sale')
                ->whereBetween('so.out_date', [
                    $startCarbon->toDateString(),
                    $endCarbon->toDateString(),
                ]);

            if ($typeCode) {
                $hppQuery
                    ->join('delivery_notes as dn', 'dn.delivery_note_id', '=', 'so.delivery_note_id')
                    ->join('invoices as i',         'i.invoice_id',       '=', 'dn.invoice_id')
                    ->join('purchase_orders as po', 'po.po_id',           '=', 'i.po_id')
                    ->join('activity_types as at',  'at.activity_type_id', '=', 'po.activity_type_id')
                    ->where('at.type_code', $typeCode);
            }

            $hppRaw = $hppQuery
                ->selectRaw('
                    YEAR(so.out_date)                                  AS year,
                    MONTH(so.out_date)                                 AS month,
                    COALESCE(SUM(so.quantity * sb.purchase_price), 0) AS hpp
                ')
                ->groupByRaw('YEAR(so.out_date), MONTH(so.out_date)')
                ->get()
                ->keyBy(fn($r) => "{$r->year}-{$r->month}");

            $result  = [];
            $current = $startCarbon->copy();

            while ($current->lte($endCarbon)) {
                $key    = "{$current->year}-{$current->month}";
                $omset  = (float) ($omsetRaw[$key]->omset ?? 0);
                $hpp    = (float) ($hppRaw[$key]->hpp     ?? 0);
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

            return response()->json([
                'success'   => true,
                'type_code' => $typeCode ?? 'ALL',
                'period'    => ['start' => $startDate, 'end' => $endDate],
                'data'      => $result,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch monthly margin',
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

        switch ($period) {
            case 'last_month':
                return [
                    Carbon::now()->subMonth()->startOfMonth()->toDateString(),
                    Carbon::now()->subMonth()->endOfMonth()->toDateString(),
                ];
            case 'this_year':
                return [
                    Carbon::now()->startOfYear()->toDateString(),
                    Carbon::now()->endOfYear()->toDateString(),
                ];
            case 'last_year':
                return [
                    Carbon::now()->subYear()->startOfYear()->toDateString(),
                    Carbon::now()->subYear()->endOfYear()->toDateString(),
                ];
            case 'custom':
                return [
                    $request->input('start_date', Carbon::now()->startOfMonth()->toDateString()),
                    $request->input('end_date',   Carbon::now()->toDateString()),
                ];
            case 'this_month':
            default:
                return [
                    Carbon::now()->startOfMonth()->toDateString(),
                    Carbon::now()->toDateString(),
                ];
        }
    }
}
