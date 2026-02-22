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

        // Ambil dari session aktif
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

            /* ---- SALES (role: 1=SuperAdmin, 2=Admin, 3=SalesMarketing, 6=Finance) ---- */
            if ($this->hasMenuAccess($roleId, 'invoices')) {
                $invoiceBase = DB::table('invoices')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['invoices'] = [
                    'total'   => (clone $invoiceBase)->count(),
                    'total_amount' => (clone $invoiceBase)->sum('total_amount'),
                    'paid'    => (clone $invoiceBase)->where('payment_status', 'paid')->count(),
                    'paid_amount' => (clone $invoiceBase)->where('payment_status', 'paid')->sum('total_amount'),
                    'unpaid'  => (clone $invoiceBase)->where('payment_status', 'unpaid')->count(),
                    'unpaid_amount' => (clone $invoiceBase)->where('payment_status', 'unpaid')->sum('total_amount'),
                    'overdue' => (clone $invoiceBase)
                        ->where('payment_status', 'unpaid')
                        ->where('due_date', '<', now()->toDateString())
                        ->count(),
                    'overdue_amount' => (clone $invoiceBase)
                        ->where('payment_status', 'unpaid')
                        ->where('due_date', '<', now()->toDateString())
                        ->sum('total_amount'),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'quotations')) {
                $quotBase = DB::table('quotations')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['quotations'] = [
                    'total'    => (clone $quotBase)->count(),
                    'draft'    => (clone $quotBase)->where('status', 'draft')->count(),
                    'sent'     => (clone $quotBase)->where('status', 'sent')->count(),
                    'approved' => (clone $quotBase)->where('status', 'approved')->count(),
                    'total_amount' => (clone $quotBase)->sum('total_amount'),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'purchase-orders')) {
                $poBase = DB::table('purchase_orders')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['purchase_orders'] = [
                    'total'      => (clone $poBase)->count(),
                    'draft'      => (clone $poBase)->where('status', 'draft')->count(),
                    'processing' => (clone $poBase)->where('status', 'processing')->count(),
                    'completed'  => (clone $poBase)->where('status', 'completed')->count(),
                    'total_amount' => (clone $poBase)->sum('total_amount'),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'proforma-invoices')) {
                $pfBase = DB::table('proforma_invoices')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['proforma_invoices'] = [
                    'total'    => (clone $pfBase)->count(),
                    'draft'    => (clone $pfBase)->where('status', 'draft')->count(),
                    'approved' => (clone $pfBase)->where('status', 'approved')->count(),
                    'total_amount' => (clone $pfBase)->sum('total_amount'),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'delivery-notes')) {
                $dnBase = DB::table('delivery_notes')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['delivery_notes'] = [
                    'total'  => (clone $dnBase)->count(),
                    'draft'  => (clone $dnBase)->where('status', 'draft')->count(),
                    'issued' => (clone $dnBase)->where('status', 'issued')->count(),
                ];
            }

            /* ---- PURCHASING (role: 1, 2, 4=Purchasing) ---- */
            if ($this->hasMenuAccess($roleId, 'supplier-po')) {
                $spoBase = DB::table('supplier_purchase_orders')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['supplier_purchase_orders'] = [
                    'total'      => (clone $spoBase)->count(),
                    'draft'      => (clone $spoBase)->where('status', 'draft')->count(),
                    'ordered'    => (clone $spoBase)->where('status', 'ordered')->count(),
                    'partial'    => (clone $spoBase)->where('status', 'partial')->count(),
                    'completed'  => (clone $spoBase)->where('status', 'completed')->count(),
                    'total_amount' => (clone $spoBase)->sum('total_amount'),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'supplier-invoices')) {
                $siBase = DB::table('supplier_invoices')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['supplier_invoices'] = [
                    'total'        => (clone $siBase)->count(),
                    'unpaid'       => (clone $siBase)->where('payment_status', 'unpaid')->count(),
                    'unpaid_amount'=> (clone $siBase)->where('payment_status', 'unpaid')->sum('total_amount'),
                    'paid'         => (clone $siBase)->where('payment_status', 'paid')->count(),
                    'overdue'      => (clone $siBase)
                        ->where('payment_status', 'unpaid')
                        ->where('due_date', '<', now()->toDateString())
                        ->count(),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'supplier-delivery-notes')) {
                $sdnBase = DB::table('supplier_delivery_notes')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['supplier_delivery_notes'] = [
                    'total'    => (clone $sdnBase)->count(),
                    'pending'  => (clone $sdnBase)->where('status', 'pending')->count(),
                    'received' => (clone $sdnBase)->where('status', 'received')->count(),
                ];
            }

            /* ---- WAREHOUSE (role: 1, 2, 5=Warehouse) ---- */
            if ($this->hasMenuAccess($roleId, 'stock-in')) {
                $siBase = DB::table('stock_in')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['stock_in'] = [
                    'total_records'   => (clone $siBase)->count(),
                    'total_qty'       => (clone $siBase)->sum('quantity'),
                    'this_month'      => (clone $siBase)
                        ->whereMonth('received_datetime', now()->month)
                        ->whereYear('received_datetime', now()->year)
                        ->count(),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'stock-out')) {
                $soBase = DB::table('stock_out')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['stock_out'] = [
                    'total_records' => (clone $soBase)->count(),
                    'total_qty'     => (clone $soBase)->sum('quantity'),
                    'this_month'    => (clone $soBase)
                        ->whereMonth('created_at', now()->month)
                        ->whereYear('created_at', now()->year)
                        ->count(),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'stock-batches')) {
                $batchBase = DB::table('stock_batches')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                // Expiry alerts
                $expiryAlertCount = DB::table('expiry_alerts as ea')
                    ->join('stock_batches as sb', 'sb.batch_id', '=', 'ea.batch_id')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('sb.company_id', $companyId))
                    ->where('ea.status', 'pending')
                    ->count();

                $data['stock_batches'] = [
                    'total'         => (clone $batchBase)->count(),
                    'active'        => (clone $batchBase)->where('status', 'active')->count(),
                    'expired'       => (clone $batchBase)->where('status', 'expired')->count(),
                    'expiry_alerts' => $expiryAlertCount,
                    'near_expiry'   => (clone $batchBase)
                        ->whereNotNull('expiry_date')
                        ->where('expiry_date', '<=', now()->addDays(30)->toDateString())
                        ->where('expiry_date', '>=', now()->toDateString())
                        ->where('status', 'active')
                        ->count(),
                ];
            }

            /* ---- FINANCE (role: 1, 2, 6=Finance) ---- */
       if ($this->hasMenuAccess($roleId, 'payments')) {
    $payBase = DB::table('payments')
        ->join('invoices', 'invoices.invoice_id', '=', 'payments.invoice_id')
        ->when($roleId !== 1 && $companyId, fn($q) => $q->where('invoices.company_id', $companyId))
        ->select('payments.*');

    $data['payments'] = [
        'total'          => (clone $payBase)->count(),
        // ✅ FIX: payments.status bukan payments.payment_status
        'success'        => (clone $payBase)->where('payments.status', 'success')->count(),
        'success_amount' => (clone $payBase)->where('payments.status', 'success')->sum('payments.amount'),
        'pending'        => (clone $payBase)->where('payments.status', 'pending')->count(),
    ];
}

            if ($this->hasMenuAccess($roleId, 'receipts')) {
                $receiptBase = DB::table('receipts')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['receipts'] = [
                    'total'        => (clone $receiptBase)->count(),
                    'total_amount' => (clone $receiptBase)->sum('amount'),
                    'this_month'   => (clone $receiptBase)
                        ->whereMonth('receipt_date', now()->month)
                        ->whereYear('receipt_date', now()->year)
                        ->sum('amount'),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'tax-invoices')) {
                $taxBase = DB::table('tax_invoices')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['tax_invoices'] = [
                    'total'    => (clone $taxBase)->count(),
                    'draft'    => (clone $taxBase)->where('status', 'draft')->count(),
                    'approved' => (clone $taxBase)->where('status', 'approved')->count(),
                    'submitted'=> (clone $taxBase)->where('status', 'submitted')->count(),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'supplier-payments')) {
                $spBase = DB::table('supplier_payments')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('company_id', $companyId));

                $data['supplier_payments'] = [
                    'total'        => (clone $spBase)->count(),
                    'paid_amount'  => (clone $spBase)->where('status', 'paid')->sum('amount'),
                    'pending'      => (clone $spBase)->where('status', 'pending')->count(),
                ];
            }

            /* ---- MASTER DATA (role: 1, 2) ---- */
            if ($this->hasMenuAccess($roleId, 'products')) {
                $data['products'] = [
                    'total'  => DB::table('products')->count(),
                    'active' => DB::table('products')->where('is_active', 1)->count(),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'customers')) {
                $data['customers'] = [
                    'total'  => DB::table('customers')->count(),
                ];
            }

            if ($this->hasMenuAccess($roleId, 'suppliers')) {
                $data['suppliers'] = [
                    'total'  => DB::table('suppliers')->count(),
                    'active' => DB::table('suppliers')->where('is_active', 1)->count(),
                ];
            }

            /* ---- TENDER (role: 1, 2, 7=TenderManager) ---- */
            if ($this->hasMenuAccess($roleId, 'tender-projects')) {
                $tenderBase = DB::table('tender_project_details')
                    ->join('purchase_orders as po', 'po.po_id', '=', 'tender_project_details.po_id')
                    ->when($roleId !== 1 && $companyId, fn($q) => $q->where('po.company_id', $companyId));

                $data['tender_projects'] = [
                    'total' => (clone $tenderBase)->count(),
                ];
            }

            /* ---- SETTINGS (role: 1 only) ---- */
            if ($roleId === 1) {
                $data['system'] = [
                    'total_users'     => DB::table('users')->count(),
                    'active_users'    => DB::table('users')->where('is_active', 1)->count(),
                    'total_companies' => DB::table('companies')->count(),
                    'active_sessions' => DB::table('user_sessions')->where('is_active', true)->count(),
                ];
            }

            return response()->json([
                'success'    => true,
                'role_id'    => $roleId,
                'company_id' => $companyId,
                'data'       => $data,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard statistics',
                'error'   => $e->getMessage(),
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
            // ✅ FIX: kolom asli = 'status', bukan 'payment_status'
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
    // ✅ FIX: p.status bukan p.payment_status
    ->where('p.status', 'success')
    ->selectRaw('p.payment_method, COUNT(*) as count, SUM(p.amount) as total_amount')
    ->groupBy('p.payment_method')
    ->get();


            $total = $rows->sum('total_amount');
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
     * Khusus: Warehouse + Admin + SuperAdmin
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
}
