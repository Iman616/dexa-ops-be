<?php
// app/Services/TenderReportService.php

namespace App\Services;

use App\Models\TenderProjectDetail;
use App\Models\Invoice;
use App\Models\SupplierPurchaseOrder;
use App\Models\BankGuarantee;
use App\Models\AgentPayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TenderReportService
{
    // =========================================================================
    // ✅ HELPER: KALKULASI DPP & PPN — DINAMIS, FLEKSIBEL PER TRANSAKSI
    //
    // 📒 Sesuai catatan:
    //    ▸ Jika INCLUDE PPN  → DPP = Total ÷ (1 + rate/100)
    //                          PPN = DPP × rate/100
    //    ▸ Jika EXCLUDE PPN  → DPP = Subtotal (harga barang)
    //                          PPN = DPP × rate/100
    //
    // Di DB (invoices): subtotal sudah = DPP, tax_amount sudah = PPN.
    // Gunakan langsung dari DB supaya tidak ada pembulatan ganda.
    // =========================================================================

    /**
     * Hitung DPP & PPN dari field invoice (sudah tersimpan di DB).
     * tax_percentage fleksibel: bisa 0%, 11%, 12%, dsb.
     */
    private function resolveInvoicePPN(Invoice $invoice): array
    {
        $subtotal       = (float) $invoice->subtotal;       // DPP
        $taxPercentage  = (float) $invoice->tax_percentage; // Rate PPN (fleksibel)
        $taxAmount      = (float) $invoice->tax_amount;     // PPN amount
        $discountAmount = (float) ($invoice->discount_amount ?? 0);

        // Validasi konsistensi (jika subtotal 0 tapi total ada → fallback hitung ulang)
        if ($subtotal == 0 && $invoice->total_amount > 0 && $taxPercentage > 0) {
            // total_amount = DPP + PPN → DPP = total / (1 + rate/100)
            $divisor   = 1 + ($taxPercentage / 100);
            $subtotal  = $invoice->total_amount / $divisor;
            $taxAmount = $subtotal * ($taxPercentage / 100);
        }

        return [
            'dpp'             => round($subtotal, 2),
            'ppn_rate'        => $taxPercentage,
            'ppn_amount'      => round($taxAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'grand_total'     => round($subtotal + $taxAmount - $discountAmount, 2),
        ];
    }

    /**
     * Hitung DPP & PPN dari Supplier PO.
     * Jika tax_percentage tidak tersimpan, hitung dari tax_amount / subtotal.
     */
    private function resolveSupplierPOPPN(SupplierPurchaseOrder $spo): array
    {
        $subtotal   = (float) $spo->subtotal;
        $taxAmount  = (float) $spo->tax_amount;
        $discount   = (float) ($spo->discount_amount ?? 0);

        // Hitung rate secara otomatis dari nilai yang tersimpan
        $ppnRate = ($subtotal > 0 && $taxAmount > 0)
            ? round(($taxAmount / $subtotal) * 100, 2)
            : 0;

        // Jika subtotal 0, gunakan fallback dari total_amount dengan rate default
        if ($subtotal == 0 && $spo->total_amount > 0 && $taxAmount > 0) {
            $subtotal = $spo->total_amount - $taxAmount;
            $ppnRate  = ($subtotal > 0) ? round(($taxAmount / $subtotal) * 100, 2) : 0;
        }

        return [
            'dpp'        => round($subtotal, 2),
            'ppn_rate'   => $ppnRate,
            'ppn_amount' => round($taxAmount, 2),
            'discount'   => round($discount, 2),
            'grand_total'=> round($subtotal + $taxAmount - $discount, 2),
        ];
    }

    // =========================================================================
    // ✅ MAIN: HITUNG P/L TENDER
    // =========================================================================

    /**
     * Hitung P/L lengkap untuk satu tender project.
     *
     * @param int $detailId  → tender_project_details.detail_id
     */
    public function calculateTenderProfitLoss(int $detailId): array
    {
        // 1. Ambil tender project detail + purchase order
        // ✅ Sesuai model: relasi bernama purchaseOrder(), bukan po()
        $detail = TenderProjectDetail::with([
            'purchaseOrder.customer',
            'purchaseOrder.company',
        ])->findOrFail($detailId);

        $po   = $detail->purchaseOrder;  // ✅ nama relasi sesuai model
        $poId = $po->po_id;

        if (!$po) {
            throw new \Exception("Purchase Order tidak ditemukan untuk detail_id #{$detailId}");
        }

        // ─── REVENUE (Invoice ke customer) ───────────────────────────────────
        // Bisa ada lebih dari 1 invoice per PO (termin/cicilan)
        $invoices = Invoice::where('po_id', $poId)->get();

        if ($invoices->isEmpty()) {
            throw new \Exception("Tidak ada invoice untuk PO #{$po->po_number}");
        }

        $revenueAggregate = [
            'dpp'        => 0,
            'ppn_amount' => 0,
            'grand_total'=> 0,
        ];
        $ppnRates = [];

        foreach ($invoices as $inv) {
            $ppn = $this->resolveInvoicePPN($inv);
            $revenueAggregate['dpp']         += $ppn['dpp'];
            $revenueAggregate['ppn_amount']  += $ppn['ppn_amount'];
            $revenueAggregate['grand_total'] += $ppn['grand_total'];
            $ppnRates[] = $ppn['ppn_rate'];
        }

        // PPN rate yang berlaku (ambil dari invoice pertama sebagai acuan)
        $mainPPNRate = $invoices->first()->tax_percentage;

        // ─── COGS (Supplier PO) ───────────────────────────────────────────────
        $supplierPOs = SupplierPurchaseOrder::where('po_id', $poId)->get();

        if ($supplierPOs->isEmpty()) {
            throw new \Exception("Tidak ada Supplier PO untuk PO #{$po->po_number}");
        }

        $cogsAggregate = [
            'dpp'        => 0,
            'ppn_amount' => 0,
            'grand_total'=> 0,
        ];
        $supplierPoIds = [];

        foreach ($supplierPOs as $spo) {
            $ppn = $this->resolveSupplierPOPPN($spo);
            $cogsAggregate['dpp']         += $ppn['dpp'];
            $cogsAggregate['ppn_amount']  += $ppn['ppn_amount'];
            $cogsAggregate['grand_total'] += $ppn['grand_total'];
            $supplierPoIds[] = $spo->supplier_po_id;
        }

        // ─── GROSS PROFIT ─────────────────────────────────────────────────────
        $dppRevenue = $revenueAggregate['dpp'];
        $dppCOGS    = $cogsAggregate['dpp'];

        $grossProfit = $dppRevenue - $dppCOGS;
        $grossMargin = ($dppRevenue > 0) ? ($grossProfit / $dppRevenue) * 100 : 0;

        // ─── BIAYA OPERASIONAL (OpEx) ─────────────────────────────────────────

        // 4a. Biaya Bank Garansi (dihitung per PO)
        $bgCosts = BankGuarantee::where('po_id', $poId)
            ->selectRaw('
                COALESCE(SUM(admin_fee), 0) as total_admin_fee,
                COALESCE(SUM(collateral_fee), 0) as total_collateral_fee
            ')
            ->first();

        $totalBGCosts = (float)$bgCosts->total_admin_fee + (float)$bgCosts->total_collateral_fee;

        // 4b. Komisi Agen (via supplier_po_id)
        $agentCommission = 0;
        if (!empty($supplierPoIds)) {
            $agentCommission = (float) AgentPayment::whereIn('supplier_po_id', $supplierPoIds)
                ->sum('amount');
        }

        // 4c. Biaya tambahan dari PO (bisa ditambahkan kolom jika diperlukan)
        // Saat ini tidak ada kolom shipping_cost / installation_cost di DB.
        // Siapkan sebagai 0 — bisa extend dari purchase_orders.notes / field baru.
        $shippingCost    = 0;
        $installationCost= 0;
        $penaltiAmount   = 0;
        $otherCosts      = 0;

        $totalOpEx = $totalBGCosts + $agentCommission + $shippingCost
                   + $installationCost + $penaltiAmount + $otherCosts;

        // ─── NET PROFIT ───────────────────────────────────────────────────────
        $netProfit = $grossProfit - $totalOpEx;
        $netMargin = ($dppRevenue > 0) ? ($netProfit / $dppRevenue) * 100 : 0;

        // ─── POSISI PPN (VAT IN vs VAT OUT) ──────────────────────────────────
        $ppnOut = $revenueAggregate['ppn_amount'];   // PPN ke customer (PPN Keluaran)
        $ppnIn  = $cogsAggregate['ppn_amount'];       // PPN dari supplier (PPN Masukan)
        $ppnNet = $ppnOut - $ppnIn;                   // > 0 = kurang bayar, < 0 = lebih bayar

        return [
            // ─ Info Tender ──────────────────────────────────────
            'detail_id'          => $detail->detail_id,
            'po_id'              => $poId,
            'po_number'          => $po->po_number,
            'customer_name'      => $po->customer->customer_name ?? 'N/A',
            'company_name'       => $po->company->company_name ?? 'N/A',
            'contract_number'    => $detail->contract_number,
            'contract_start_date'=> $detail->contract_start_date,
            'contract_end_date'  => $detail->contract_end_date,
            'project_status'     => $detail->project_status,

            // ─ Revenue ──────────────────────────────────────────
            'ppn_rate'           => $mainPPNRate,   // fleksibel dari DB
            'dpp_revenue'        => round($revenueAggregate['dpp'], 2),
            'ppn_revenue'        => round($revenueAggregate['ppn_amount'], 2),
            'total_revenue'      => round($revenueAggregate['grand_total'], 2),
            'invoice_count'      => $invoices->count(),

            // ─ COGS ─────────────────────────────────────────────
            'dpp_cogs'           => round($cogsAggregate['dpp'], 2),
            'ppn_cogs'           => round($cogsAggregate['ppn_amount'], 2),
            'total_cogs'         => round($cogsAggregate['grand_total'], 2),
            'supplier_po_count'  => $supplierPOs->count(),

            // ─ Gross Profit ─────────────────────────────────────
            'gross_profit'       => round($grossProfit, 2),
            'gross_margin'       => round($grossMargin, 2),

            // ─ OpEx ─────────────────────────────────────────────
            'total_opex'         => round($totalOpEx, 2),
            'opex_breakdown'     => [
                'bg_admin_fee'      => round((float)$bgCosts->total_admin_fee, 2),
                'bg_collateral_fee' => round((float)$bgCosts->total_collateral_fee, 2),
                'bg_total'          => round($totalBGCosts, 2),
                'agent_commission'  => round($agentCommission, 2),
                'shipping_cost'     => round($shippingCost, 2),
                'installation_cost' => round($installationCost, 2),
                'penalti_amount'    => round($penaltiAmount, 2),
                'other_costs'       => round($otherCosts, 2),
            ],

            // ─ Net Profit ───────────────────────────────────────
            'net_profit'         => round($netProfit, 2),
            'net_margin'         => round($netMargin, 2),

            // ─ Posisi PPN ───────────────────────────────────────
            'ppn_keluaran'       => round($ppnOut, 2),   // PPN tagih ke customer
            'ppn_masukan'        => round($ppnIn, 2),    // PPN bayar ke supplier
            'ppn_net'            => round($ppnNet, 2),   // Selisih (hutang/piutang pajak)
            'ppn_status'         => $ppnNet > 0 ? 'kurang_bayar' : ($ppnNet < 0 ? 'lebih_bayar' : 'nihil'),
        ];
    }

    // =========================================================================
    // ✅ CHECKLIST PENUTUPAN PROYEK
    // =========================================================================

    public function getProjectClosingChecklist(int $detailId): array
    {
        $detail = TenderProjectDetail::with('po')->findOrFail($detailId);
        $poId   = $detail->po_id;

        // Dokumen selesai (berdasarkan boolean field di DB)
        $baUjiFungsiDone = (bool) $detail->has_ba_uji_fungsi;
        $bahpDone        = (bool) $detail->has_bahp;
        $bastDone        = (bool) $detail->has_bast;
        $sp2dDone        = (bool) $detail->has_sp2d;

        // Pembayaran diterima (semua invoice paid)
        $invoices       = Invoice::where('po_id', $poId)->get();
        $paymentReceived = $invoices->isNotEmpty()
            && $invoices->every(fn($inv) => $inv->payment_status === 'paid');

        // Komisi agen sudah dibayar
        $supplierPoIds = SupplierPurchaseOrder::where('po_id', $poId)
            ->pluck('supplier_po_id');

        $agentCommissionPaid = true;
        if ($supplierPoIds->isNotEmpty()) {
            $agentPayments = AgentPayment::whereIn('supplier_po_id', $supplierPoIds)->get();
            $agentCommissionPaid = $agentPayments->isEmpty()
                || $agentPayments->every(fn($p) => $p->status === 'paid');
        }

        // Bank garansi sudah dikembalikan
        $bgJampel = BankGuarantee::where('po_id', $poId)
            ->where('guarantee_type', 'jampel')
            ->first();
        $bgJampelReturned = !$bgJampel || $bgJampel->status === 'returned';

        $bgJamuk = BankGuarantee::where('po_id', $poId)
            ->where('guarantee_type', 'jamuk')
            ->first();
        $bgJamukReturned = !$bgJamuk || $bgJamuk->status === 'returned';

        $checklist = [
            'ba_uji_fungsi_selesai' => $baUjiFungsiDone,
            'bahp_selesai'          => $bahpDone,
            'bast_selesai'          => $bastDone,
            'sp2d_selesai'          => $sp2dDone,
            'pembayaran_diterima'   => $paymentReceived,
            'komisi_agen_lunas'     => $agentCommissionPaid,
            'bg_jampel_kembali'     => $bgJampelReturned,
            'bg_jamuk_kembali'      => $bgJamukReturned,
        ];

        $totalItems     = count($checklist);
        $completedItems = count(array_filter($checklist));
        $percentage     = round(($completedItems / $totalItems) * 100, 2);

        return [
            'checklist'            => $checklist,
            'total_items'          => $totalItems,
            'completed_items'      => $completedItems,
            'completion_percentage'=> $percentage,
            'is_completed'         => $percentage == 100,
        ];
    }

    // =========================================================================
    // ✅ RINGKASAN LENGKAP SATU TENDER
    // =========================================================================

    public function getTenderSummaryReport(int $detailId): array
    {
        $pl        = $this->calculateTenderProfitLoss($detailId);
        $checklist = $this->getProjectClosingChecklist($detailId);

        return [
            'tender_info'      => [
                'detail_id'           => $pl['detail_id'],
                'po_number'           => $pl['po_number'],
                'customer_name'       => $pl['customer_name'],
                'company_name'        => $pl['company_name'],
                'contract_number'     => $pl['contract_number'],
                'contract_start_date' => $pl['contract_start_date'],
                'contract_end_date'   => $pl['contract_end_date'],
                'project_status'      => $pl['project_status'],
            ],
            'financial_summary' => $pl,
            'project_closing'   => $checklist,
            'generated_at'      => now()->toISOString(),
        ];
    }

    // =========================================================================
    // ✅ SEMUA TENDER — DASHBOARD P/L
    // =========================================================================

    public function getAllTendersPLSummary(array $filters = []): Collection
    {
        $query = TenderProjectDetail::with(['purchaseOrder.customer', 'purchaseOrder.company'])
            ->whereHas('purchaseOrder', fn($q) => $q->where('status', '!=', 'cancelled'));

        if (!empty($filters['company_id'])) {
            $query->whereHas('purchaseOrder', fn($q) => $q->where('company_id', $filters['company_id']));
        }

        if (!empty($filters['status'])) {
            $query->where('project_status', $filters['status']);
        }

        if (!empty($filters['year'])) {
            $query->whereYear('contract_start_date', $filters['year']);
        }

        return $query->get()->map(function ($detail) {
            try {
                $pl = $this->calculateTenderProfitLoss($detail->detail_id);
                return [
                    'detail_id'      => $detail->detail_id,
                    'po_number'      => $pl['po_number'],
                    'customer_name'  => $pl['customer_name'],
                    'company_name'   => $pl['company_name'],
                    'ppn_rate'       => $pl['ppn_rate'],
                    'dpp_revenue'    => $pl['dpp_revenue'],
                    'total_revenue'  => $pl['total_revenue'],
                    'gross_profit'   => $pl['gross_profit'],
                    'gross_margin'   => $pl['gross_margin'],
                    'net_profit'     => $pl['net_profit'],
                    'net_margin'     => $pl['net_margin'],
                    'ppn_net'        => $pl['ppn_net'],
                    'ppn_status'     => $pl['ppn_status'],
                    'project_status' => $detail->project_status,
                ];
            } catch (\Exception $e) {
                return null; // Skip jika data tidak lengkap
            }
        })->filter()->values();
    }

    // =========================================================================
    // ✅ TUTUP PROYEK
    // =========================================================================

    public function closeProject(int $detailId): array
    {
        $checklist = $this->getProjectClosingChecklist($detailId);

        if (!$checklist['is_completed']) {
            $missing = array_keys(array_filter($checklist['checklist'], fn($v) => !$v));
            throw new \Exception(
                'Proyek belum bisa ditutup. Item belum selesai: ' . implode(', ', $missing)
            );
        }

        $detail = TenderProjectDetail::findOrFail($detailId);
        $detail->update([
            'project_status'   => 'completed',
            'contract_end_date'=> now()->toDateString(),
        ]);

        // Update invoice & supplier PO terkait
        $poId = $detail->po_id;
        Invoice::where('po_id', $poId)->update(['payment_status' => 'paid']);
        SupplierPurchaseOrder::where('po_id', $poId)->update(['status' => 'completed']);

        return [
            'success'   => true,
            'message'   => 'Proyek berhasil ditutup',
            'detail_id' => $detailId,
            'closed_at' => now()->toISOString(),
        ];
    }

    // =========================================================================
    // ✅ REKALKULATOR MANUAL — Override PPN Rate dari Inputan
    //    Digunakan jika user ingin simulasi dengan rate PPN berbeda
    //    (misal: perbandingan 11% vs 12%)
    // =========================================================================

    /**
     * Hitung ulang DPP & PPN dari total_amount dengan rate inputan user.
     *
     * @param float $totalAmount  Nilai kontrak / total penjualan (include PPN)
     * @param float $ppnRate      Rate PPN dari inputan (mis: 11, 12, 0)
     * @param bool  $includePPN   true = total sudah include PPN, false = belum
     */
    public function recalculatePPN(float $totalAmount, float $ppnRate, bool $includePPN = true): array
    {
        if ($ppnRate < 0 || $ppnRate > 100) {
            throw new \InvalidArgumentException('PPN rate harus antara 0–100');
        }

        if ($includePPN) {
            // Total kontrak SUDAH include PPN → hitung DPP dulu
            // Rumus: DPP = Total ÷ (1 + rate/100)
            $divisor   = 1 + ($ppnRate / 100);
            $dpp       = $totalAmount / $divisor;
            $ppnAmount = $dpp * ($ppnRate / 100);
        } else {
            // Total adalah harga barang (DPP) → PPN dihitung di atas
            $dpp       = $totalAmount;
            $ppnAmount = $dpp * ($ppnRate / 100);
        }

        $grandTotal = $dpp + $ppnAmount;

        return [
            'ppn_rate'    => $ppnRate,
            'include_ppn' => $includePPN,
            'dpp'         => round($dpp, 2),
            'ppn_amount'  => round($ppnAmount, 2),
            'grand_total' => round($grandTotal, 2),
            'formula'     => $includePPN
                ? "DPP = {$totalAmount} ÷ (1 + {$ppnRate}/100) = " . round($dpp, 2)
                : "DPP = {$totalAmount}, PPN = {$totalAmount} × {$ppnRate}% = " . round($ppnAmount, 2),
        ];
    }
}