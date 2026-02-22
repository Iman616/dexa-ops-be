<?php
namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\SupplierPurchaseOrder;
use App\Models\SupplierPurchaseOrderItem;
use App\Models\ProductSupplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;


/**
 * AutoProcurementService - SIMPLIFIED VERSION
 * 
 * Logic sederhana:
 * 1. Cek product_suppliers (primary supplier)
 * 2. Jika tidak ada, fallback ke history stock_in
 * 3. Jika masih tidak ada, return "no supplier"
 */
class AutoProcurementService
{
public function handleStockShortage(
    PurchaseOrder $po,
    int $companyId,
    bool $autoCreate = false,
    string $strategy = 'last',
    array $manualSuppliers = []  // ← TAMBAH parameter
): array {
    $po->loadMissing('items');

    if ($po->items->isEmpty()) {
        return $this->buildResult([], [], 'PO tidak memiliki item');
    }

    $shortages = StockHelper::calculateShortages($po->items, $companyId);

    if ($shortages->isEmpty()) {
        return $this->buildResult([], [], 'Stok mencukupi');
    }

    // ← TAMBAH: inject manual supplier ke dalam plan
    $procurementPlan = $this->buildProcurementPlan(
        $shortages, $companyId, $strategy, $manualSuppliers
    );

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

    /**
     * Build procurement plan dengan 2-tier lookup yang SIMPLE
     */
private function buildProcurementPlan(
    Collection $shortages,
    int $companyId,
    string $strategy,
    array $manualSuppliers = []
): Collection {
    $manualMap = collect($manualSuppliers)->keyBy('product_id');

    return $shortages->map(function ($shortage) use ($companyId, $strategy, $manualMap) {
        $productId = $shortage['product_id'];

        // 1️⃣ Master (product_suppliers)
        $supplier = $this->getSupplierFromMaster($productId, $companyId, $strategy);
        $source   = 'master';

        // 2️⃣ History (stock_in)
        if (!$supplier) {
            $supplier = $this->getSupplierFromHistory($productId, $companyId, $strategy);
            $source   = 'history';
        }

        // 3️⃣ products.supplier_id — distributor bawaan produk ✅ NEW
        if (!$supplier) {
            $supplier = $this->getSupplierFromProduct($productId);
            $source   = 'product';
        }

        // 4️⃣ Manual dari frontend
        if (!$supplier && $manualMap->has($productId)) {
            $manual     = $manualMap->get($productId);
            $dbSupplier = \App\Models\Supplier::find($manual['supplier_id']);

            if ($dbSupplier) {
                $supplier = (object)[
                    'supplier_id'    => $dbSupplier->supplier_id,
                    'supplier_name'  => $dbSupplier->supplier_name,
                    'purchase_price' => (float) ($manual['unit_price'] ?? 0),
                ];
                $source = 'manual';
            }
        }

        $allSuppliers = $this->getAllSuppliersForProduct($productId, $companyId);

        return [
            'product_id'          => $productId,
            'product_name'        => $shortage['product_name'],
            'unit'                => $shortage['unit'],
            'shortage_qty'        => $shortage['shortage'],
            'order_qty'           => $shortage['shortage'],
            'selected_supplier'   => $supplier ? [
                'supplier_id'    => $supplier->supplier_id,
                'supplier_name'  => $supplier->supplier_name,
                'purchase_price' => $supplier->purchase_price,
                'source'         => $source,
            ] : null,
            'can_auto_procure'    => $supplier !== null,
            'available_suppliers' => $allSuppliers,
            'no_supplier_reason'  => !$supplier
                ? 'Produk ini belum memiliki supplier terdaftar.'
                : null,
        ];
    });
}

private function getSupplierFromProduct(int $productId): ?object
{
    $product = \App\Models\Product::with('supplier')
        ->where('product_id', $productId)
        ->whereNotNull('supplier_id')
        ->first();

    if (!$product || !$product->supplier) {
        return null;
    }

    return (object)[
        'supplier_id'    => $product->supplier_id,
        'supplier_name'  => $product->supplier->supplier_name,
        'purchase_price' => (float) $product->purchase_price,
    ];
}


/**
 * Ambil semua supplier (master + history) untuk satu produk
 * Dipakai di buildProcurementPlan untuk field available_suppliers
 */
private function getAllSuppliersForProduct(int $productId, int $companyId): array
{
    // Master
    $master = ProductSupplier::with('supplier')
        ->where('product_id', $productId)
        ->where('company_id', $companyId)
        ->where('is_active', true)
        ->get()
        ->map(fn($ps) => [
            'supplier_id'    => $ps->supplier_id,
            'supplier_name'  => $ps->supplier->supplier_name,
            'purchase_price' => (float) $ps->purchase_price,
            'avg_price'      => (float) $ps->purchase_price,
            'delivery_count' => null,
            'last_received'  => null,
            'is_primary'     => $ps->is_primary,
            'source'         => 'master',
        ]);

    // History — 3-tier COALESCE
    $history = DB::table('stock_in as si')
        ->leftJoin('supplier_delivery_notes as sdn',
            'si.supplier_delivery_note_id', '=', 'sdn.supplier_delivery_note_id')
        ->leftJoin('supplier_purchase_orders as spo',
            'si.supplier_po_id', '=', 'spo.supplier_po_id')
        ->leftJoin('stock_batches as sb',
            'si.batch_id', '=', 'sb.batch_id')
        ->join('suppliers as s', function ($join) {
            $join->on('s.supplier_id', '=',
                DB::raw('COALESCE(sdn.supplier_id, spo.supplier_id, sb.supplier_id)')
            );
        })
        ->where('si.product_id', $productId)
        ->where('si.company_id', $companyId)
        ->whereNotNull(DB::raw('COALESCE(sdn.supplier_id, spo.supplier_id, sb.supplier_id)'))
        ->select([
            's.supplier_id',
            's.supplier_name',
            DB::raw('AVG(si.purchase_price) as avg_price'),
            DB::raw('MAX(si.received_datetime) as last_received'),
            DB::raw('COUNT(*) as delivery_count'),
        ])
        ->groupBy('s.supplier_id', 's.supplier_name')
        ->orderByDesc('last_received')
        ->get()
        ->map(fn($s) => [
            'supplier_id'    => $s->supplier_id,
            'supplier_name'  => $s->supplier_name,
            'purchase_price' => round((float) $s->avg_price, 2),
            'avg_price'      => round((float) $s->avg_price, 2),
            'delivery_count' => (int) $s->delivery_count,
            'last_received'  => $s->last_received,
            'is_primary'     => false,
            'source'         => 'history',
        ]);

    // Gabung, master menang jika duplikat supplier_id
    return $master->concat($history)
        ->unique('supplier_id')
        ->values()
        ->toArray();
}


    /**
     * 1️⃣ Ambil supplier dari MASTER (product_suppliers)
     */
    private function getSupplierFromMaster(int $productId, int $companyId, string $strategy)
    {
        $query = ProductSupplier::with('supplier')
            ->where('product_id', $productId)
            ->where('company_id', $companyId)
            ->where('is_active', true);

        // Strategy: primary dulu, baru cheapest
        if ($strategy === 'cheapest') {
            // Cari yang paling murah
            $result = $query->orderBy('purchase_price', 'asc')->first();
        } else {
            // Default: ambil primary supplier
            $result = $query->where('is_primary', true)->first();
            
            // Jika tidak ada primary, ambil yang pertama (active)
            if (!$result) {
                $result = $query->first();
            }
        }

        if (!$result) {
            return null;
        }

        return (object)[
            'supplier_id'    => $result->supplier_id,
            'supplier_name'  => $result->supplier->supplier_name,
            'purchase_price' => (float)$result->purchase_price,
        ];
    }

    /**
     * 2️⃣ Ambil supplier dari HISTORY (stock_in)
     */
  private function getSupplierFromHistory(int $productId, int $companyId, string $strategy)
{
    $query = DB::table('stock_in as si')
        // Join ke supplier_delivery_notes untuk ambil supplier_id
        ->leftJoin('supplier_delivery_notes as sdn', 'si.supplier_delivery_note_id', '=', 'sdn.supplier_delivery_note_id')
        // Join via SPO jika ada
        ->leftJoin('supplier_purchase_orders as spo', 'si.supplier_po_id', '=', 'spo.supplier_po_id')
        ->join('suppliers as s', function ($join) {
            $join->on('s.supplier_id', '=', DB::raw('COALESCE(sdn.supplier_id, spo.supplier_id)'));
        })
        ->where('si.product_id', $productId)
        ->where('si.company_id', $companyId)
        ->where(function ($q) {
            // Harus punya salah satu: delivery note dengan supplier ATAU supplier PO
            $q->whereNotNull('sdn.supplier_id')
              ->orWhereNotNull('si.supplier_po_id');
        })
        ->select([
            's.supplier_id',
            's.supplier_name',
            DB::raw('AVG(si.purchase_price) as avg_price'),
            DB::raw('MAX(si.received_datetime) as last_received'),
            DB::raw('COUNT(*) as delivery_count'),
        ])
        ->groupBy('s.supplier_id', 's.supplier_name');

    if ($strategy === 'cheapest') {
        $query->orderBy('avg_price', 'asc');
    } elseif ($strategy === 'most_frequent') {
        $query->orderByDesc('delivery_count');
    } else {
        $query->orderByDesc('last_received');
    }

    $result = $query->first();
    if (!$result) return null;

    return (object)[
        'supplier_id'    => $result->supplier_id,
        'supplier_name'  => $result->supplier_name,
        'purchase_price' => round((float)$result->avg_price, 2),
    ];
}


    /**
     * Create Supplier POs (tidak berubah)
     */
   private function createSupplierPOs(Collection $plan, PurchaseOrder $po, int $companyId): Collection
{
    $resolvable = $plan->filter(fn($p) => $p['can_auto_procure']);
    if ($resolvable->isEmpty()) return collect();

    $grouped    = $resolvable->groupBy(fn($p) => $p['selected_supplier']['supplier_id']);
    $createdPos = collect();

    DB::transaction(function () use ($grouped, $po, $companyId, &$createdPos) {
        foreach ($grouped as $supplierId => $items) {

            // ✅ Cek apakah SPO untuk supplier ini + PO ini sudah ada
            $existing = SupplierPurchaseOrder::where('po_id', $po->po_id)
                ->where('supplier_id', $supplierId)
                ->whereNotIn('status', ['cancelled'])
                ->first();

            if ($existing) {
                // Sudah ada — skip, tapi tetap masukkan ke response
                $existing->load(['supplier', 'items']);
                $existing->setAttribute('_already_exists', true);
                $createdPos->push($existing);
                continue;
            }

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
                'created_by'             => Auth::id(),
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

    /**
     * Get suppliers untuk satu produk (untuk API)
     */
    public function getSuppliersForProduct(int $productId, int $companyId): array
    {
        // Ambil dari master
        $master = ProductSupplier::with('supplier')
            ->where('product_id', $productId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->map(fn($ps) => [
                'supplier_id'    => $ps->supplier_id,
                'supplier_name'  => $ps->supplier->supplier_name,
                'purchase_price' => $ps->purchase_price,
                'is_primary'     => $ps->is_primary,
                'source'         => 'master',
            ]);

        // Ambil dari history
       // Di bagian $history query:
$history = DB::table('stock_in as si')
    ->leftJoin('supplier_delivery_notes as sdn', 'si.supplier_delivery_note_id', '=', 'sdn.supplier_delivery_note_id')
    ->leftJoin('supplier_purchase_orders as spo', 'si.supplier_po_id', '=', 'spo.supplier_po_id')
    ->join('suppliers as s', function ($join) {
        $join->on('s.supplier_id', '=', DB::raw('COALESCE(sdn.supplier_id, spo.supplier_id)'));
    })
    ->where('si.product_id', $productId)
    ->where('si.company_id', $companyId)
    ->where(function ($q) {
        $q->whereNotNull('sdn.supplier_id')
          ->orWhereNotNull('si.supplier_po_id');
    })
    ->select([
        's.supplier_id',
        's.supplier_name',
        DB::raw('AVG(si.purchase_price) as avg_price'),
        DB::raw('MAX(si.received_datetime) as last_received'),
        DB::raw('COUNT(*) as delivery_count'),
    ])
    ->groupBy('s.supplier_id', 's.supplier_name')
    ->orderByDesc('last_received')
    ->get()
    ->map(fn($s) => [
        'supplier_id'    => $s->supplier_id,
        'supplier_name'  => $s->supplier_name,
        'purchase_price' => round((float)$s->avg_price, 2),
        'avg_price'      => round((float)$s->avg_price, 2),
        'delivery_count' => $s->delivery_count,
        'last_received'  => $s->last_received,
        'is_primary'     => false,
        'source'         => 'history',
    ]);


        // Gabung dan deduplicate
        $all = $master->concat($history)
            ->unique('supplier_id')
            ->values();

        return [
            'suppliers'       => $all->toArray(),
            'has_master_data' => $master->isNotEmpty(),
            'has_history'     => $history->isNotEmpty(),
        ];
    }

private function buildResult(array $plan, array $created, string $message): array
{
    $existingPos = collect($created)->filter(fn($s) =>
        isset($s['_already_exists']) || (is_object($s) && $s->getAttribute('_already_exists'))
    );

    return [
        'has_shortage'         => !empty($plan),
        'message'              => $message,
        'shortage_count'       => count($plan),
        'resolvable_count'     => collect($plan)->filter(fn($p) =>  $p['can_auto_procure'])->count(),
        'unresolvable_count'   => collect($plan)->filter(fn($p) => !$p['can_auto_procure'])->count(),
        'procurement_plan'     => $plan,
        'created_supplier_pos' => $created,
        'existing_po_count'    => $existingPos->count(), // ✅ info duplikat
    ];
}
}