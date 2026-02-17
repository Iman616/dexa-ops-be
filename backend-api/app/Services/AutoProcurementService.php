<?php
namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\SupplierPurchaseOrder;
use App\Models\SupplierPurchaseOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * AutoProcurementService v3
 *
 * Perubahan dari v2:
 * ✅ getCurrentStock() → ganti pakai StockHelper::getAvailableByProducts()
 *    sinkron 100% dengan realTimeStock() di StockBatchController
 * ✅ Supplier lookup tetap dari riwayat StockIn
 * ✅ Logic lain tidak berubah
 */
class AutoProcurementService
{
    public function handleStockShortage(
        PurchaseOrder $po,
        int $companyId,
        bool $autoCreate = false,
        string $strategy = 'last'
    ): array {
        $po->loadMissing('items');

        if ($po->items->isEmpty()) {
            return $this->buildResult([], [], 'PO tidak memiliki item');
        }

        // ✅ Pakai StockHelper — formula sama dengan realTimeStock()
        $shortages = StockHelper::calculateShortages($po->items, $companyId);

        if ($shortages->isEmpty()) {
            return $this->buildResult([], [], 'Stok mencukupi, tidak perlu Supplier PO');
        }

        $procurementPlan = $this->buildProcurementPlan($shortages, $companyId, $strategy);

        $createdPos = collect();
        if ($autoCreate) {
            $createdPos = $this->createSupplierPOs($procurementPlan, $po, $companyId);
        }

        return $this->buildResult(
            $procurementPlan->toArray(),
            $createdPos->toArray(),
            $autoCreate ? 'Supplier PO berhasil dibuat' : 'Rekomendasi pengadaan tersedia'
        );
    }

    /* ── Supplier dari histori StockIn (tidak berubah dari v2) ── */

    private function buildProcurementPlan(
        Collection $shortages,
        int $companyId,
        string $strategy
    ): Collection {
        $productIds = $shortages->pluck('product_id')->unique()->values()->toArray();

        // Satu query: semua supplier per produk dari riwayat StockIn
        $history = DB::table('stock_in as si')
            ->join('supplier_purchase_orders as spo', 'si.supplier_po_id', '=', 'spo.supplier_po_id')
            ->join('suppliers as s', 'spo.supplier_id', '=', 's.supplier_id')
            ->whereIn('si.product_id', $productIds)
            ->where('si.company_id', $companyId)
            ->whereNotNull('si.supplier_po_id')
            ->select([
                'si.product_id',
                's.supplier_id',
                's.supplier_name',
                's.contact_person',
                's.phone',
                DB::raw('AVG(si.purchase_price) as avg_price'),
                DB::raw('MIN(si.purchase_price) as min_price'),
                DB::raw('MAX(si.received_date) as last_received'),
                DB::raw('COUNT(*) as delivery_count'),
            ])
            ->groupBy('si.product_id', 's.supplier_id', 's.supplier_name', 's.contact_person', 's.phone')
            ->orderByDesc('last_received')
            ->get()
            ->groupBy('product_id');

        return $shortages->map(function ($shortage) use ($history, $strategy) {
            $suppliers = $history[$shortage['product_id']] ?? collect();

            $selected = null;
            if ($suppliers->isNotEmpty()) {
                $selected = match ($strategy) {
                    'cheapest'      => $suppliers->sortBy('avg_price')->first(),
                    'most_frequent' => $suppliers->sortByDesc('delivery_count')->first(),
                    default         => $suppliers->first(), // 'last'
                };
            }

            return [
                'product_id'          => $shortage['product_id'],
                'product_name'        => $shortage['product_name'],
                'unit'                => $shortage['unit'],
                'shortage_qty'        => $shortage['shortage'],
                'order_qty'           => $shortage['shortage'],
                'available_suppliers' => $suppliers->map(fn($s) => [
                    'supplier_id'    => $s->supplier_id,
                    'supplier_name'  => $s->supplier_name,
                    'avg_price'      => round((float)$s->avg_price, 2),
                    'min_price'      => round((float)$s->min_price, 2),
                    'last_received'  => $s->last_received,
                    'delivery_count' => $s->delivery_count,
                ])->values()->toArray(),
                'selected_supplier'   => $selected ? [
                    'supplier_id'    => $selected->supplier_id,
                    'supplier_name'  => $selected->supplier_name,
                    'purchase_price' => round((float)$selected->avg_price, 2),
                ] : null,
                'can_auto_procure'   => $selected !== null,
                'no_supplier_reason' => $selected === null
                    ? 'Belum ada riwayat StockIn untuk produk ini'
                    : null,
            ];
        });
    }

    private function createSupplierPOs(
        Collection $plan,
        PurchaseOrder $po,
        int $companyId
    ): Collection {
        $resolvable = $plan->filter(fn($p) => $p['can_auto_procure']);
        if ($resolvable->isEmpty()) return collect();

        $grouped   = $resolvable->groupBy(fn($p) => $p['selected_supplier']['supplier_id']);
        $createdPos = collect();

        DB::transaction(function () use ($grouped, $po, $companyId, &$createdPos) {
            foreach ($grouped as $supplierId => $items) {
                $totalAmount = $items->sum(fn($i) =>
                    $i['order_qty'] * ($i['selected_supplier']['purchase_price'] ?? 0)
                );

                $spo = SupplierPurchaseOrder::create([
                    'po_number'              => SupplierPurchaseOrder::generatePoNumber(),
                    'po_id'                  => $po->po_id,
                    'supplier_id'            => $supplierId,
                    'company_id'             => $companyId,
                    'po_date'                => now()->toDateString(),
                    'expected_delivery_date' => now()->addDays(7)->toDateString(),
                    'status'                 => 'draft',
                    'payment_status'         => 'unpaid',
                    'subtotal'               => $totalAmount,
                    'tax_amount'             => 0,
                    'discount_amount'        => 0,
                    'total_amount'           => $totalAmount,
                    'notes'                  => "Auto dari PO #{$po->po_number}",
                    'created_by'             => auth()->id(),
                ]);

                foreach ($items as $item) {
                    $unitPrice = $item['selected_supplier']['purchase_price'] ?? 0;
                    $qty       = $item['order_qty'];
                    SupplierPurchaseOrderItem::create([
                        'supplier_po_id'    => $spo->supplier_po_id,
                        'product_id'        => $item['product_id'],
                        'product_name'      => $item['product_name'],
                        'product_code'      => null,
                        'quantity'          => $qty,
                        'unit'              => $item['unit'],
                        'unit_price'        => $unitPrice,
                        'discount_percent'  => 0,
                        'discount_amount'   => 0,
                        'subtotal'          => $qty * $unitPrice,
                        'total'             => $qty * $unitPrice,
                        'received_quantity' => 0,
                    ]);
                }

                $spo->load(['supplier', 'items']);
                $createdPos->push($spo);
            }
        });

        return $createdPos;
    }

    /* ── Helper publik ── */

    public function getSuppliersForProduct(int $productId, int $companyId): Collection
    {
        return DB::table('stock_in as si')
            ->join('supplier_purchase_orders as spo', 'si.supplier_po_id', '=', 'spo.supplier_po_id')
            ->join('suppliers as s', 'spo.supplier_id', '=', 's.supplier_id')
            ->where('si.product_id', $productId)
            ->where('si.company_id', $companyId)
            ->whereNotNull('si.supplier_po_id')
            ->select([
                's.supplier_id', 's.supplier_name', 's.contact_person', 's.phone', 's.email',
                DB::raw('AVG(si.purchase_price)  as avg_price'),
                DB::raw('MIN(si.purchase_price)  as min_price'),
                DB::raw('MAX(si.purchase_price)  as max_price'),
                DB::raw('MAX(si.received_date)   as last_received'),
                DB::raw('SUM(si.quantity)         as total_received'),
                DB::raw('COUNT(*)                 as delivery_count'),
            ])
            ->groupBy('s.supplier_id', 's.supplier_name', 's.contact_person', 's.phone', 's.email')
            ->orderByDesc('last_received')
            ->get()
            ->map(fn($s) => [
                'supplier_id'    => $s->supplier_id,
                'supplier_name'  => $s->supplier_name,
                'contact_person' => $s->contact_person,
                'phone'          => $s->phone,
                'email'          => $s->email,
                'avg_price'      => round((float)$s->avg_price, 2),
                'min_price'      => round((float)$s->min_price, 2),
                'max_price'      => round((float)$s->max_price, 2),
                'last_received'  => $s->last_received,
                'total_received' => (int)$s->total_received,
                'delivery_count' => (int)$s->delivery_count,
            ]);
    }

    private function buildResult(array $plan, array $created, string $message): array
    {
        return [
            'has_shortage'         => !empty($plan),
            'message'              => $message,
            'shortage_count'       => count($plan),
            'resolvable_count'     => collect($plan)->filter(fn($p) =>  $p['can_auto_procure'])->count(),
            'unresolvable_count'   => collect($plan)->filter(fn($p) => !$p['can_auto_procure'])->count(),
            'procurement_plan'     => $plan,
            'created_supplier_pos' => $created,
        ];
    }
}