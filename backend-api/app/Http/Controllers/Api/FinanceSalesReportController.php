<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FinanceSalesReportController extends Controller
{
    /* =========================================================
     * HELPERS (sama dengan DashboardController)
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

    private function hasMenuAccess(int $roleId, string $menuKey): bool
    {
        if ($roleId === 1)
            return true;

        return DB::table('role_menus as rm')
            ->join('menus as m', 'm.menu_id', '=', 'rm.menu_id')
            ->where('rm.role_id', $roleId)
            ->where('m.menu_key', $menuKey)
            ->where('rm.can_read', 1)
            ->exists();
    }
    /* =========================================================
     * PUBLIC: Shared logic untuk getSalesReport + Export
     * ========================================================= */
    public function buildSalesReportData(Request $request): array
    {
        $user = $request->user();
        $roleId = (int) $user->role_id;
        $companyId = $this->getCompanyId($request);

        [$startDate, $endDate] = $this->resolveDateRange($request);

        $invoices = DB::table('invoices as i')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'i.customer_id')
            ->leftJoin('companies as co', 'co.company_id', '=', 'i.company_id')
            ->leftJoin('purchase_orders as po', 'po.po_id', '=', 'i.po_id')
            ->leftJoin('activity_types as at', 'at.activity_type_id', '=', 'po.activity_type_id')
            ->leftJoin('proforma_invoices as pi', 'pi.proforma_id', '=', 'i.proforma_invoice_id')
            ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
            ->whereBetween('i.invoice_date', [$startDate, $endDate])
            ->select(
                'i.invoice_id',
                'i.invoice_number',
                'i.invoice_date',
                'i.due_date',
                'i.subtotal',
                'i.tax_percentage',
                'i.tax_amount',
                'i.discount_amount',
                'i.total_amount',
                'i.payment_status',
                'i.po_id',
                'i.proforma_invoice_id',
                'c.customer_name',
                'co.company_code',
                'at.type_code as unit_code',
                'at.type_name as unit_name',
                'pi.proforma_date as tanggal_pi',
                'po.po_number',
            )
            ->orderBy('i.invoice_date')
            ->orderBy('i.invoice_id')
            ->get();

        if ($invoices->isEmpty()) {
            return [[], $this->emptySummary(), ['start' => $startDate, 'end' => $endDate]];
        }

        $invoiceIds = $invoices->pluck('invoice_id')->toArray();

        $paymentsMap = DB::table('payments')
            ->whereIn('invoice_id', $invoiceIds)
            ->where('status', 'success')
            ->select('invoice_id', 'amount', 'payment_date', 'payment_method', 'bank_name')
            ->orderBy('payment_date')
            ->get()
            ->groupBy('invoice_id');

        $pphMap = DB::table('tax_invoices')
            ->whereIn('invoice_id', $invoiceIds)
            ->where('status', 'approved')
            ->whereIn('tax_type', ['pph_21', 'pph_22', 'pph_23'])
            ->selectRaw('invoice_id, SUM(tax_amount) as total_pph')
            ->groupBy('invoice_id')
            ->get()
            ->keyBy('invoice_id');

        $hppMap = DB::table('stock_out as so')
            ->join('stock_batches as sb', 'sb.batch_id', '=', 'so.batch_id')
            ->join('delivery_notes as dn', 'dn.delivery_note_id', '=', 'so.delivery_note_id')
            ->leftJoin('suppliers as s', 's.supplier_id', '=', 'sb.supplier_id')

            ->whereIn('dn.invoice_id', $invoiceIds)
            ->whereNotNull('dn.invoice_id')
            ->selectRaw('
            dn.invoice_id,
            COALESCE(SUM(so.quantity * sb.purchase_price), 0) AS hpp,
            GROUP_CONCAT(DISTINCT s.supplier_name ORDER BY s.supplier_name SEPARATOR ", ") AS nama_agen,
            MIN(sb.received_date) AS tgl_pembelian
        ')
            ->groupBy('dn.invoice_id')
            ->get()
            ->keyBy('invoice_id');

        $rows = [];
        $no = 1;
        $summary = $this->emptySummary();

        foreach ($invoices as $inv) {
            $invPayments = $paymentsMap[$inv->invoice_id] ?? collect();
            $paidAmount = $invPayments->sum('amount');
            $firstPay = $invPayments->first();

            $pphAmount = (float) ($pphMap[$inv->invoice_id]->total_pph ?? 0);
            $hppRow = $hppMap[$inv->invoice_id] ?? null;
            $hppAmount = (float) ($hppRow->hpp ?? 0);

            $subtotal = (float) $inv->subtotal;
            $taxPct = (float) $inv->tax_percentage;
            $dppLainnya = $taxPct > 0 ? round($subtotal * (11 / 12), 2) : $subtotal;
            $nilaiPenjualan = (float) $inv->total_amount;
            $margin = $nilaiPenjualan - $hppAmount;

            $rows[] = [
                'no' => $no++,
                'unit' => $inv->unit_code ?? '-',
                'unit_name' => $inv->unit_name ?? 'Lainnya',
                'perusahaan' => $inv->company_code ?? '-',
                'nama_project' => $inv->customer_name ?? '-',
                'tanggal_pi' => $inv->tanggal_pi ? Carbon::parse($inv->tanggal_pi)->format('Y-m-d') : null,
                'tanggal_invoice' => Carbon::parse($inv->invoice_date)->format('Y-m-d'),
                'due_date' => $inv->due_date ? Carbon::parse($inv->due_date)->format('Y-m-d') : null,
                'invoice_number' => $inv->invoice_number,
                'po_number' => $inv->po_number,
                'nilai_penjualan' => $nilaiPenjualan,
                'pajak' => ['dpp' => $dppLainnya, 'ppn' => (float) $inv->tax_amount, 'pph' => $pphAmount],
                'biaya_lain' => ['nominal' => 0, 'keterangan' => null],
                'dana_masuk' => [
                    'nominal' => $paidAmount,
                    'tanggal' => $firstPay ? Carbon::parse($firstPay->payment_date)->format('Y-m-d') : null,
                    'payment_method' => $firstPay?->payment_method,
                    'bank_name' => $firstPay?->bank_name,
                ],
                'pembelian' => [
                    'nominal' => $hppAmount,
                    'nama_agen' => $hppRow?->nama_agen,
                    'tgl_pembelian' => $hppRow?->tgl_pembelian
                        ? Carbon::parse($hppRow->tgl_pembelian)->format('Y-m-d') : null,
                ],
                'payment_status' => $inv->payment_status,
                'sisa_piutang' => max(0, $nilaiPenjualan - $paidAmount),
                'margin' => $margin,
                'margin_percent' => $nilaiPenjualan > 0 ? round(($margin / $nilaiPenjualan) * 100, 2) : 0,
            ];

            $summary['total_nilai_penjualan'] += $nilaiPenjualan;
            $summary['total_dpp'] += $dppLainnya;
            $summary['total_ppn'] += (float) $inv->tax_amount;
            $summary['total_pph'] += $pphAmount;
            $summary['total_dana_masuk'] += $paidAmount;
            $summary['total_pembelian'] += $hppAmount;
            $summary['total_margin'] += $margin;
        }

        $summary['margin_percent'] = $summary['total_nilai_penjualan'] > 0
            ? round(($summary['total_margin'] / $summary['total_nilai_penjualan']) * 100, 2) : 0;
        $summary['total_sisa_piutang'] = $summary['total_nilai_penjualan'] - $summary['total_dana_masuk'];

        return [$rows, $summary, ['start' => $startDate, 'end' => $endDate]];
    }


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
                $request->input('end_date', Carbon::now()->toDateString()),
            ],
            default => [
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->toDateString(),
            ],
        };
    }

    /* =========================================================
     * GET /api/finance/sales-report
     * Laporan Penjualan detail per invoice
     * Kolom: sesuai DATA-BASE-LAPORAN-PENJUALAN
     * ========================================================= */
    public function getSalesReport(Request $request)
    {
        try {
            $user = $request->user();
            $roleId = (int) $user->role_id;

            if (
                !$this->hasMenuAccess($roleId, 'invoices') &&
                !$this->hasMenuAccess($roleId, 'financialreport')
            ) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            [$rows, $summary, $period] = $this->buildSalesReportData($request);

            return response()->json([
                'success' => true,
                'period' => $period,
                'data' => $rows,
                'summary' => $summary,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan penjualan',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }


    /* =========================================================
     * GET /api/finance/sales-report/by-unit
     * Summary per Unit Bisnis (agregasi seperti sheet JPM/DNL)
     * ========================================================= */
    public function getSummaryByUnit(Request $request)
    {
        try {
            $user = $request->user();
            $roleId = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            if (
                !$this->hasMenuAccess($roleId, 'invoices') &&
                !$this->hasMenuAccess($roleId, 'financialreport')
            ) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            [$startDate, $endDate] = $this->resolveDateRange($request);

            $rows = DB::table('invoices as i')
                ->leftJoin('purchase_orders as po', 'po.po_id', '=', 'i.po_id')
                ->leftJoin('activity_types as at', 'at.activity_type_id', '=', 'po.activity_type_id')
                ->leftJoin('companies as co', 'co.company_id', '=', 'i.company_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                ->whereBetween('i.invoice_date', [$startDate, $endDate])
                ->selectRaw('
                    COALESCE(at.type_code, "OTHER")    AS unit_code,
                    COALESCE(at.type_name, "Lainnya")  AS unit_name,
                    co.company_code,
                    COUNT(i.invoice_id)                AS total_invoice,
                    COALESCE(SUM(i.subtotal), 0)       AS total_dpp,
                    COALESCE(SUM(i.tax_amount), 0)     AS total_ppn,
                    COALESCE(SUM(i.total_amount), 0)   AS total_nilai_penjualan,
                    COALESCE(SUM(
                        CASE WHEN i.payment_status IN ("paid","completed")
                        THEN i.total_amount ELSE 0 END
                    ), 0) AS total_lunas,
                    COALESCE(SUM(
                        CASE WHEN i.payment_status = "unpaid"
                        THEN i.total_amount ELSE 0 END
                    ), 0) AS total_belum_bayar,
                    COALESCE(SUM(
                        CASE WHEN i.payment_status = "partial"
                        THEN i.total_amount ELSE 0 END
                    ), 0) AS total_partial
                ')
                ->groupByRaw('
                    COALESCE(at.type_code, "OTHER"),
                    COALESCE(at.type_name, "Lainnya"),
                    co.company_code
                ')
                ->orderByDesc('total_nilai_penjualan')
                ->get();

            $grandTotal = [
                'total_invoice' => $rows->sum('total_invoice'),
                'total_dpp' => $rows->sum('total_dpp'),
                'total_ppn' => $rows->sum('total_ppn'),
                'total_nilai_penjualan' => $rows->sum('total_nilai_penjualan'),
                'total_lunas' => $rows->sum('total_lunas'),
                'total_belum_bayar' => $rows->sum('total_belum_bayar'),
                'total_partial' => $rows->sum('total_partial'),
            ];

            return response()->json([
                'success' => true,
                'period' => ['start' => $startDate, 'end' => $endDate],
                'data' => $rows,
                'grand_total' => $grandTotal,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat summary per unit',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/finance/sales-report/outstanding
     * AR Aging – invoice belum/partial lunas
     * ========================================================= */
    public function getOutstandingInvoices(Request $request)
    {
        try {
            $user = $request->user();
            $roleId = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            if (
                !$this->hasMenuAccess($roleId, 'invoices') &&
                !$this->hasMenuAccess($roleId, 'financialreport')
            ) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            $result = DB::table('invoices as i')
                ->leftJoin('customers as c', 'c.customer_id', '=', 'i.customer_id')
                ->leftJoin('companies as co', 'co.company_id', '=', 'i.company_id')
                ->when($roleId !== 1 && $companyId, fn($q) => $q->where('i.company_id', $companyId))
                ->whereIn('i.payment_status', ['unpaid', 'partial', 'dp_paid'])
                ->selectRaw("
                    i.invoice_id,
                    i.invoice_number,
                    i.invoice_date,
                    i.due_date,
                    i.total_amount,
                    i.payment_status,
                    c.customer_name,
                    co.company_code,
                    DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
                    CASE
                        WHEN i.due_date IS NULL
                            THEN 'Tanpa Jatuh Tempo'
                        WHEN CURDATE() <= i.due_date
                            THEN 'Belum Jatuh Tempo'
                        WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1  AND 30
                            THEN '1-30 Hari'
                        WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60
                            THEN '31-60 Hari'
                        WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90
                            THEN '61-90 Hari'
                        ELSE '>90 Hari'
                    END AS aging_bucket
                ")
                ->orderByRaw('ISNULL(i.due_date), i.due_date ASC')
                ->get();

            // Aging Summary
            $agingSummary = $result
                ->groupBy('aging_bucket')
                ->map(fn($g) => [
                    'count' => $g->count(),
                    'total' => round($g->sum('total_amount'), 2),
                ]);

            return response()->json([
                'success' => true,
                'data' => $result,
                'aging_summary' => $agingSummary,
                'total_outstanding' => round($result->sum('total_amount'), 2),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat outstanding invoices',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /* =========================================================
     * GET /api/finance/sales-report/supplier-payments
     * Laporan Pembayaran ke Supplier (sisi pembelian)
     * ========================================================= */
    public function getSupplierPaymentReport(Request $request)
    {
        try {
            $user = $request->user();
            $roleId = (int) $user->role_id;
            $companyId = $this->getCompanyId($request);

            if (
                !$this->hasMenuAccess($roleId, 'supplierpayments') &&
                !$this->hasMenuAccess($roleId, 'financialreport')
            ) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }

            [$startDate, $endDate] = $this->resolveDateRange($request);

            $rows = DB::table('supplier_payments as sp')
                ->join('supplier_invoices as si', 'si.supplier_invoice_id', '=', 'sp.supplier_invoice_id')
                ->leftJoin('suppliers as s', 's.supplier_id', '=', 'si.supplier_id')
                ->leftJoin(
                    'supplier_purchase_orders as spo',
                    'spo.supplier_po_id',
                    '=',
                    'si.supplier_po_id'
                )
                ->leftJoin('companies as co', 'co.company_id', '=', 'spo.company_id')
                ->when(
                    $roleId !== 1 && $companyId,
                    fn($q) =>
                    $q->where('spo.company_id', $companyId)
                )
                ->whereBetween('sp.payment_date', [$startDate, $endDate])
                ->whereNull('sp.deleted_at')
                ->select(
                    'sp.supplier_payment_id',
                    'sp.payment_number',
                    'sp.payment_date',
                    'sp.amount',
                    'sp.payment_type',
                    'sp.payment_method',
                    'sp.status',
                    'sp.reference_number',
                    'si.invoice_number   as supplier_invoice_number',
                    'si.total_amount     as invoice_total',
                    'si.payment_status   as invoice_payment_status',
                    's.supplier_name',
                    'co.company_code',
                    'spo.po_number       as supplier_po_number',
                )
                ->orderBy('sp.payment_date')
                ->get();

            $summary = [
                'total_payment' => round($rows->sum('amount'), 2),
                'total_dp' => round($rows->where('payment_type', 'dp')->sum('amount'), 2),
                'total_pelunasan' => round($rows->where('payment_type', 'full')->sum('amount'), 2),
                'count' => $rows->count(),
            ];

            return response()->json([
                'success' => true,
                'period' => ['start' => $startDate, 'end' => $endDate],
                'data' => $rows,
                'summary' => $summary,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan pembayaran supplier',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /* =========================================================
     * PRIVATE: empty summary template
     * ========================================================= */
    private function emptySummary(): array
    {
        return [
            'total_nilai_penjualan' => 0,
            'total_dpp' => 0,
            'total_ppn' => 0,
            'total_pph' => 0,
            'total_dana_masuk' => 0,
            'total_sisa_piutang' => 0,
            'total_pembelian' => 0,
            'total_margin' => 0,
            'margin_percent' => 0,
        ];
    }
}
