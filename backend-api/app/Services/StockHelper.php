<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * StockHelper — single source of truth untuk kalkulasi stok real-time
 *
 * Formula: available = opening_stock + total_in - total_out
 *
 * ⚠️ ASUMSI STRUKTUR TABEL:
 *   - stock_batches      → ada company_id ✅
 *   - stock_in           → ada company_id ✅
 *   - stock_out          → ada company_id ✅
 *   - stock_opening      → TIDAK ada company_id ❌
 *
 * Filter company_id dilakukan di stock_batches (WHERE), bukan di JOIN.
 */
class StockHelper
{
    /**
     * Stok real-time untuk banyak produk sekaligus
     */
    public static function getAvailableByProducts($productIds, int $companyId): Collection
    {
        $ids = collect($productIds)->filter()->unique()->values()->toArray();

        if (empty($ids)) {
            return collect();
        }

        return DB::table('stock_batches as sb')
            // ✅ Filter company_id di stock_batches (WHERE, bukan JOIN)
            ->where('sb.company_id', $companyId)
            ->whereIn('sb.product_id', $ids)

            // Opening stock (tanpa filter company_id karena tabel tidak punya)
            ->leftJoin('stock_opening as so', 'so.batch_id', '=', 'sb.batch_id')

            // Stock IN (filter company_id di sini kalau ada)
            ->leftJoin('stock_in as si', function ($join) use ($companyId) {
                $join->on('si.batch_id', '=', 'sb.batch_id')
                     ->where('si.company_id', $companyId);
            })

            // Stock OUT (filter company_id di sini kalau ada)
            ->leftJoin('stock_out as so2', function ($join) use ($companyId) {
                $join->on('so2.batch_id', '=', 'sb.batch_id')
                     ->where('so2.company_id', $companyId);
            })

            ->groupBy('sb.product_id')
            ->select(
                'sb.product_id',
                DB::raw('
                    COALESCE(SUM(so.quantity),  0)
                    + COALESCE(SUM(si.quantity), 0)
                    - COALESCE(SUM(so2.quantity), 0)
                    as available_stock
                ')
            )
            ->pluck('available_stock', 'product_id');
    }

    /**
     * Stok real-time untuk SATU produk
     */
    public static function getAvailableByProduct(int $productId, int $companyId): float
    {
        return (float) self::getAvailableByProducts([$productId], $companyId)
            ->get($productId, 0);
    }

    /**
     * Stok real-time per batch (untuk FIFO, detail batch, dsb.)
     */
    public static function getAvailableByBatch(int $productId, int $companyId): Collection
    {
        return DB::table('stock_batches as sb')
            // ✅ Filter company_id di stock_batches
            ->where('sb.company_id', $companyId)
            ->where('sb.product_id', $productId)

            // Opening stock (tanpa filter company_id)
            ->leftJoin('stock_opening as so', 'so.batch_id', '=', 'sb.batch_id')

            // Stock IN (filter company_id)
            ->leftJoin('stock_in as si', function ($join) use ($companyId) {
                $join->on('si.batch_id', '=', 'sb.batch_id')
                     ->where('si.company_id', $companyId);
            })

            // Stock OUT (filter company_id)
            ->leftJoin('stock_out as so2', function ($join) use ($companyId) {
                $join->on('so2.batch_id', '=', 'sb.batch_id')
                     ->where('so2.company_id', $companyId);
            })

            ->groupBy(
                'sb.batch_id', 'sb.batch_number',
                'sb.manufacture_date', 'sb.expiry_date',
                'sb.created_at'
            )
            ->select(
                'sb.batch_id',
                'sb.batch_number',
                'sb.expiry_date',
                'sb.manufacture_date',
                DB::raw('COALESCE(SUM(so.quantity),  0) as opening_stock'),
                DB::raw('COALESCE(SUM(si.quantity),  0) as total_in'),
                DB::raw('COALESCE(SUM(so2.quantity), 0) as total_out'),
                DB::raw('
                    COALESCE(SUM(so.quantity),  0)
                    + COALESCE(SUM(si.quantity), 0)
                    - COALESCE(SUM(so2.quantity), 0)
                    as available_stock
                ')
            )
            ->orderBy('sb.expiry_date', 'asc')
            ->orderBy('sb.created_at',   'asc')
            ->get();
    }

    /**
     * Cek shortage untuk banyak item sekaligus
     */
    public static function calculateShortages(Collection $items, int $companyId): Collection
    {
        $productIds = $items->pluck('product_id')->filter();
        $stock = self::getAvailableByProducts($productIds, $companyId);

        return $items
            ->filter(fn($item) => !empty($item->product_id ?? $item['product_id'] ?? null))
            ->map(function ($item) use ($stock) {
                $isObj     = is_object($item);
                $productId = $isObj ? $item->product_id : $item['product_id'];
                $required  = (float)($isObj ? $item->quantity : $item['quantity']);
                $available = (float)($stock[$productId] ?? 0);
                $shortage  = max(0, $required - $available);

                return [
                    'product_id'   => $productId,
                    'product_name' => $isObj ? $item->product_name : $item['product_name'],
                    'unit'         => $isObj ? ($item->unit ?? '') : ($item['unit'] ?? ''),
                    'required'     => $required,
                    'available'    => $available,
                    'shortage'     => $shortage,
                ];
            })
            ->filter(fn($item) => $item['shortage'] > 0)
            ->values();
    }
}