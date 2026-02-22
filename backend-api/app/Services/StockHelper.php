<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * StockHelper — single source of truth untuk kalkulasi stok real-time
 *
 * Formula per batch: opening + total_in - total_out
 * Formula per produk: SUM semua batch
 */
class StockHelper
{
    /**
     * Stok real-time untuk banyak produk sekaligus
     * FIXED: Pakai subquery per-tabel agar tidak double-count
     */
    public static function getAvailableByProducts($productIds, int $companyId): Collection
    {
        $ids = collect($productIds)->filter()->unique()->values()->toArray();

        if (empty($ids)) {
            return collect();
        }

        // ✅ FIXED: Aggregasi dulu per batch di subquery, baru JOIN
        // Ini mencegah Cartesian product yang menyebabkan double-count

        $opening = DB::table('stock_opening')
            ->select('batch_id', DB::raw('SUM(quantity) as total_opening'))
            ->groupBy('batch_id');

        $in = DB::table('stock_in')
            ->select('batch_id', DB::raw('SUM(quantity) as total_in'))
            ->where('company_id', $companyId)
            ->groupBy('batch_id');

        $out = DB::table('stock_out')
            ->select('batch_id', DB::raw('SUM(quantity) as total_out'))
            ->where('company_id', $companyId)
            ->groupBy('batch_id');

        return DB::table('stock_batches as sb')
            ->where('sb.company_id', $companyId)
            ->whereIn('sb.product_id', $ids)
            ->leftJoinSub($opening, 'so', 'so.batch_id', '=', 'sb.batch_id')
            ->leftJoinSub($in,      'si', 'si.batch_id', '=', 'sb.batch_id')
            ->leftJoinSub($out,     'sout', 'sout.batch_id', '=', 'sb.batch_id')
            ->groupBy('sb.product_id')
            ->select(
                'sb.product_id',
                DB::raw('
                    SUM(
                        COALESCE(so.total_opening, 0)
                        + COALESCE(si.total_in,    0)
                        - COALESCE(sout.total_out,  0)
                    ) as available_stock
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
     * FIXED: Sama — pakai subquery agar tidak double-count
     */
    public static function getAvailableByBatch(int $productId, int $companyId): Collection
    {
        $opening = DB::table('stock_opening')
            ->select('batch_id', DB::raw('SUM(quantity) as total_opening'))
            ->groupBy('batch_id');

        $in = DB::table('stock_in')
            ->select('batch_id', DB::raw('SUM(quantity) as total_in'))
            ->where('company_id', $companyId)
            ->groupBy('batch_id');

        $out = DB::table('stock_out')
            ->select('batch_id', DB::raw('SUM(quantity) as total_out'))
            ->where('company_id', $companyId)
            ->groupBy('batch_id');

        return DB::table('stock_batches as sb')
            ->where('sb.company_id', $companyId)
            ->where('sb.product_id', $productId)
            ->leftJoinSub($opening, 'so',   'so.batch_id',   '=', 'sb.batch_id')
            ->leftJoinSub($in,      'si',   'si.batch_id',   '=', 'sb.batch_id')
            ->leftJoinSub($out,     'sout', 'sout.batch_id', '=', 'sb.batch_id')
            ->groupBy(
                'sb.batch_id',
                'sb.batch_number',
                'sb.manufacture_date',
                'sb.expiry_date',
                'sb.created_at'
            )
            ->select(
                'sb.batch_id',
                'sb.batch_number',
                'sb.expiry_date',
                'sb.manufacture_date',
                DB::raw('COALESCE(so.total_opening, 0) as opening_stock'),
                DB::raw('COALESCE(si.total_in,      0) as total_in'),
                DB::raw('COALESCE(sout.total_out,   0) as total_out'),
                DB::raw('
                    COALESCE(so.total_opening, 0)
                    + COALESCE(si.total_in,    0)
                    - COALESCE(sout.total_out, 0)
                    as available_stock
                ')
            )
            ->orderBy('sb.expiry_date', 'asc')
            ->orderBy('sb.created_at',  'asc')
            ->get();
    }

    /**
     * Cek shortage untuk banyak item sekaligus
     * FIXED: Handle relasi product untuk product_name & unit
     */
    public static function calculateShortages(Collection $items, int $companyId): Collection
    {
        $productIds = $items->pluck('product_id')->filter();
        $stock      = self::getAvailableByProducts($productIds, $companyId);

        return $items
            ->filter(function ($item) {
                $id = is_object($item)
                    ? ($item->product_id ?? null)
                    : ($item['product_id'] ?? null);
                return !empty($id);
            })
            ->map(function ($item) use ($stock) {
                $isObj     = is_object($item);
                $productId = $isObj ? $item->product_id : $item['product_id'];
                $required  = (float) ($isObj ? $item->quantity : $item['quantity']);
                $available = (float) $stock->get($productId, 0);
                $shortage  = max(0, $required - $available);

                // ✅ FIXED: Ambil product_name & unit dari relasi product jika ada
                if ($isObj) {
                    $productName = $item->product_name
                        ?? $item->product?->product_name
                        ?? '';
                    $unit = $item->unit
                        ?? $item->product?->unit
                        ?? '';
                } else {
                    $productName = $item['product_name']
                        ?? $item['product']['product_name']
                        ?? '';
                    $unit = $item['unit']
                        ?? $item['product']['unit']
                        ?? '';
                }

                return [
                    'product_id'   => $productId,
                    'product_name' => $productName,
                    'unit'         => $unit,
                    'required'     => $required,
                    'available'    => $available,
                    'shortage'     => $shortage,
                ];
            })
            ->filter(fn($item) => $item['shortage'] > 0)
            ->values();
    }
}
