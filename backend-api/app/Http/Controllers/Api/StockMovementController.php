<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\StockBatch;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Auth;

class StockMovementController extends Controller
{
    public function index(Request $request)
{
    $query = StockMovement::with([
        'product:product_id,product_name,product_code,product_type',
        'batch:batch_id,batch_number',
        'createdByUser:user_id,full_name',
    ]);

    // ✅ FIX: filter company via join ke stock_batches
    if ($request->filled('company_id')) {
        $query->whereHas('batch', function ($q) use ($request) {
            $q->where('company_id', $request->company_id);
        });
    }

    if ($request->filled('product_id'))
        $query->where('product_id', $request->product_id);

    if ($request->filled('batch_id'))
        $query->where('batch_id', $request->batch_id);

    if ($request->filled('movement_type'))
        $query->where('movement_type', $request->movement_type);

    if ($request->filled('reference_type'))
        $query->where('reference_type', $request->reference_type);

    if ($request->filled('start_date'))
        $query->whereDate('created_at', '>=', $request->start_date);

    if ($request->filled('end_date'))
        $query->whereDate('created_at', '<=', $request->end_date);

    if ($request->filled('search')) {
        $s = $request->search;
        $query->where(function ($q) use ($s) {
            $q->whereHas('product', fn($pq) =>
                $pq->where('product_name', 'like', "%{$s}%")
                   ->orWhere('product_code', 'like', "%{$s}%")
            )->orWhereHas('batch', fn($bq) =>
                $bq->where('batch_number', 'like', "%{$s}%")
            );
        });
    }

    $query->orderBy(
        $request->get('sort_by', 'created_at'),
        $request->get('sort_order', 'desc')
    );

    return response()->json([
        'success' => true,
        'data'    => $query->paginate($request->get('per_page', 15)),
    ], 200);
}
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id'     => 'required|exists:companies,company_id', // ✅ TAMBAH
            'product_id'     => 'required|exists:products,product_id',
            'batch_id'       => 'required|exists:stock_batches,batch_id',
            'movement_type'  => 'required|in:IN,OUT,ADJUSTMENT,RETURN,RETURN_IN,RETURN_OUT',
            'quantity'       => 'required|numeric|min:0.01',
            'unit_price'     => 'nullable|numeric|min:0', // ✅ FIX
            'reference_id'   => 'nullable|integer',
            'reference_type' => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        if ($validator->fails())
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            DB::beginTransaction();

            $movement = StockMovement::create([
                'company_id'     => $request->company_id, // ✅ TAMBAH
                'product_id'     => $request->product_id,
                'batch_id'       => $request->batch_id,
                'movement_type'  => $request->movement_type,
                'quantity'       => $request->quantity,
                'unit_price'     => $request->unit_price, // ✅ FIX
                'reference_id'   => $request->reference_id,
                'reference_type' => $request->reference_type,
                'notes'          => $request->notes,
                'created_by'     => Auth::id(),
            ]);

            // Update batch quantity
            $batch = StockBatch::find($request->batch_id);
            if ($batch) {
                if (in_array($request->movement_type, ['IN', 'RETURN', 'RETURN_IN'])) {
                    $batch->quantity_available += $request->quantity;
                } elseif (in_array($request->movement_type, ['OUT', 'ADJUSTMENT', 'RETURN_OUT'])) {
                    $batch->quantity_available -= $request->quantity;
                }

                // ✅ Update status sesuai requirement: <15 hampir habis, 0 habis, >30 banyak
                if ($batch->quantity_available <= 0) {
                    $batch->status = 'depleted';
                } elseif ($batch->quantity_available < 15) {
                    $batch->status = 'low';      // hampir habis
                } elseif ($batch->expiry_date && $batch->expiry_date < now()) {
                    $batch->status = 'expired';
                } else {
                    $batch->status = 'active';   // stock banyak (>30 handled di frontend)
                }

                $batch->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock movement created successfully',
                'data'    => $movement->load(['product', 'batch', 'createdByUser']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $movement = StockMovement::with(['product', 'batch', 'createdByUser'])->find($id);

        if (!$movement)
            return response()->json(['success' => false, 'message' => 'Not found'], 404);

        return response()->json(['success' => true, 'data' => $movement], 200);
    }

    public function update(Request $request, $id)
    {
        $movement = StockMovement::find($id);
        if (!$movement)
            return response()->json(['success' => false, 'message' => 'Not found'], 404);

        $movement->update(['notes' => $request->notes]);
        return response()->json(['success' => true, 'data' => $movement], 200);
    }

    public function destroy($id)
    {
        $movement = StockMovement::find($id);
        if (!$movement)
            return response()->json(['success' => false, 'message' => 'Not found'], 404);

        try {
            DB::beginTransaction();

            $batch = $movement->batch;
            if ($batch) {
                if (in_array($movement->movement_type, ['IN', 'RETURN', 'RETURN_IN'])) {
                    $batch->quantity_available -= $movement->quantity;
                } elseif (in_array($movement->movement_type, ['OUT', 'ADJUSTMENT', 'RETURN_OUT'])) {
                    $batch->quantity_available += $movement->quantity;
                }
                $batch->save();
            }

            $movement->delete();
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Deleted successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }



    public function historyByBatch($batchId)
    {
        $batch = StockBatch::with('product')->find($batchId);
        if (!$batch)
            return response()->json(['success' => false, 'message' => 'Batch not found'], 404);

        $movements = StockMovement::with('createdByUser')
            ->where('batch_id', $batchId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ['batch' => $batch, 'movements' => $movements],
        ], 200);
    }

    // ✅ TAMBAH: Warehouse Report — Fast Move & Data Aset
  public function warehouseReport(Request $request)
{
    $companyId = $request->get('company_id');
    $startDate = $request->get('start_date');
    $endDate   = $request->get('end_date');

    // ── Fast Move: product dengan OUT >= 3x ──
    $fastMoveQuery = DB::table('stock_movements')
        ->join('products',      'stock_movements.product_id', '=', 'products.product_id')
        ->join('stock_batches', 'stock_movements.batch_id',   '=', 'stock_batches.batch_id')
        ->where('stock_movements.movement_type', 'OUT')
        ->select(
            'products.product_id',
            'products.product_name',
            'products.product_code',
            'products.product_type',
            DB::raw('COUNT(*) as total_out_count'),
            DB::raw('SUM(stock_movements.quantity) as total_out_qty')
        )
        ->groupBy('products.product_id', 'products.product_name', 'products.product_code', 'products.product_type')
        ->having('total_out_count', '>=', 3);

    if ($companyId) $fastMoveQuery->where('stock_batches.company_id', $companyId);
    if ($startDate) $fastMoveQuery->whereDate('stock_movements.created_at', '>=', $startDate);
    if ($endDate)   $fastMoveQuery->whereDate('stock_movements.created_at', '<=', $endDate);

    $fastMove = $fastMoveQuery->orderBy('total_out_count', 'desc')->get();

    // ── Data Aset: akumulasi stock IN s/d end_date ──
    $asetQuery = DB::table('stock_in')
        ->join('products', 'stock_in.product_id', '=', 'products.product_id')
        ->whereNotNull('stock_in.purchase_price')
        ->select(
            'products.product_type',
            DB::raw('SUM(stock_in.quantity * stock_in.purchase_price) as total_aset')
        )
        ->groupBy('products.product_type');

    if ($companyId) $asetQuery->where('stock_in.company_id', $companyId);
    if ($endDate)   $asetQuery->whereDate('stock_in.received_date', '<=', $endDate);

    $dataAset = $asetQuery->get();

    // ── Stock Status ──
    $stockStatusQuery = DB::table('stock_batches')
        ->join('products', 'stock_batches.product_id', '=', 'products.product_id')
        ->select(
            'products.product_id',
            'products.product_name',
            'products.product_type',
            DB::raw('SUM(stock_batches.quantity_available) as total_qty'),
            DB::raw("CASE
                WHEN SUM(stock_batches.quantity_available) = 0 THEN 'Habis'
                WHEN SUM(stock_batches.quantity_available) < 15 THEN 'Hampir Habis'
                WHEN SUM(stock_batches.quantity_available) > 30 THEN 'Stock Banyak'
                ELSE 'Normal'
            END as stock_status")
        )
        ->where('stock_batches.status', 'active')
        ->groupBy('products.product_id', 'products.product_name', 'products.product_type');

    if ($companyId) $stockStatusQuery->where('stock_batches.company_id', $companyId);

    $stockStatus = $stockStatusQuery->orderBy('products.product_type')->orderBy('stock_status')->get();

    return response()->json([
        'success' => true,
        'data' => [
            'fast_move'    => $fastMove,
            'data_aset'    => $dataAset,
            'stock_status' => $stockStatus,
        ],
    ], 200);
}


public function export(Request $request): StreamedResponse
{
    $companyId = $request->get('company_id');
    $startDate = $request->get('start_date');
    $endDate   = $request->get('end_date');
    $type      = $request->get('movement_type');

    $query = DB::table('stock_movements')
        ->join('products',           'stock_movements.product_id', '=', 'products.product_id')
        ->leftJoin('stock_batches',  'stock_movements.batch_id',   '=', 'stock_batches.batch_id')
        ->leftJoin('users',          'stock_movements.created_by', '=', 'users.user_id')
        ->select(
            'stock_movements.movement_id',
            'stock_movements.movement_type',
            'stock_movements.quantity',
            // ❌ HAPUS: unit_price, total_cost — kolom tidak ada di tabel
            'stock_movements.reference_type',
            'stock_movements.reference_id',
            'stock_movements.notes',
            'stock_movements.created_at',
            'products.product_code',
            'products.product_name',
            'products.product_type',
            'stock_batches.batch_number',
            'users.full_name as created_by_name'
        )
        ->orderBy('stock_movements.created_at', 'desc');

    if ($companyId) $query->where('stock_batches.company_id', $companyId);
    if ($type)      $query->where('stock_movements.movement_type', $type);
    if ($startDate) $query->whereDate('stock_movements.created_at', '>=', $startDate);
    if ($endDate)   $query->whereDate('stock_movements.created_at', '<=', $endDate);

    $movements = $query->get();

    $typeLabels = [
        'IN'         => 'Barang Masuk',
        'OUT'        => 'Barang Keluar',
        'ADJUSTMENT' => 'Penyesuaian',
        'RETURN'     => 'Retur',
        'RETURN_IN'  => 'Retur Masuk',
        'RETURN_OUT' => 'Retur Keluar',
    ];

    $filename = 'stock_movements_' . now()->format('Ymd_His') . '.csv';

    return response()->streamDownload(function () use ($movements, $typeLabels) {
        $handle = fopen('php://output', 'w');
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        // ✅ Header tanpa kolom harga
        fputcsv($handle, [
            'No', 'Tanggal', 'Kode Produk', 'Nama Produk', 'Tipe Produk',
            'No Batch', 'Tipe Gerakan', 'Qty',
            'Referensi', 'Catatan', 'Dicatat Oleh'
        ]);

        foreach ($movements as $i => $m) {
            fputcsv($handle, [
                $i + 1,
                \Carbon\Carbon::parse($m->created_at)->format('d/m/Y H:i'),
                $m->product_code,
                $m->product_name,
                ucfirst($m->product_type),
                $m->batch_number ?? '-',
                $typeLabels[$m->movement_type] ?? $m->movement_type,
                number_format($m->quantity, 2, '.', ''),
                $m->reference_type ? "{$m->reference_type} #{$m->reference_id}" : '-',
                $m->notes ?? '-',
                $m->created_by_name ?? '-',
            ]);
        }

        fclose($handle);
    }, $filename, [
        'Content-Type'        => 'text/csv; charset=UTF-8',
        'Content-Disposition' => "attachment; filename=\"{$filename}\"",
    ]);
}

public function exportReport(Request $request): StreamedResponse
{
    $companyId = $request->get('company_id');
    $startDate = $request->get('start_date');
    $endDate   = $request->get('end_date');

    $typeLabels = [
        'precursor' => 'Pro Analis',
        'bbo'       => 'BBO',
        'ppi'       => 'PPI',
        'teknis'    => 'Teknis',
        'glassware' => 'Glassware',
        'alat_lab'  => 'Alat Lab',
    ];

    // 1. Semua transaksi OUT - 1 row per transaksi
    $outRows = DB::table('stock_movements')
        ->join('products',      'stock_movements.product_id', '=', 'products.product_id')
        ->join('stock_batches', 'stock_movements.batch_id',   '=', 'stock_batches.batch_id')
        ->leftJoin('users',     'stock_movements.created_by', '=', 'users.user_id')
        ->leftJoin('purchase_orders', function ($join) {
            $join->on('stock_movements.reference_id', '=', 'purchase_orders.po_id')
                 ->where('stock_movements.reference_type', 'purchase_order');
        })
        ->leftJoin('customers', 'purchase_orders.customer_id', '=', 'customers.customer_id')
        ->where('stock_movements.movement_type', 'OUT')
        ->select([
            'products.product_type',
            'products.product_name',
            'products.product_code',
            DB::raw("COALESCE(customers.customer_name, users.full_name, '-') as company_name"),
        ])
        ->when($companyId, fn($q) => $q->where('stock_batches.company_id', $companyId))
        ->when($startDate, fn($q) => $q->whereDate('stock_movements.created_at', '>=', $startDate))
        ->when($endDate,   fn($q) => $q->whereDate('stock_movements.created_at', '<=', $endDate))
        ->orderBy('products.product_type')
        ->orderBy('products.product_name')
        ->orderBy('stock_movements.created_at')
        ->get();

    // 2. Group type > product > [rows], filter >= 3 transaksi
    $fastGrouped = [];
    $tempGroup   = [];

    foreach ($outRows as $r) {
        $tempGroup[$r->product_type][$r->product_name][] = $r;
    }
    foreach ($tempGroup as $type => $products) {
        foreach ($products as $name => $rows) {
            if (count($rows) >= 3) {
                $fastGrouped[$type][$name] = $rows;
            }
        }
    }

    // 3. Data Aset per type per bulan
    $asetData = DB::table('stock_in')
        ->join('products', 'stock_in.product_id', '=', 'products.product_id')
        ->whereNotNull('stock_in.purchase_price')
        ->select([
            'products.product_type',
            DB::raw("DATE_FORMAT(stock_in.received_date, '%b') as bulan_label"),
            DB::raw("DATE_FORMAT(stock_in.received_date, '%Y-%m') as bulan_key"),
            DB::raw('SUM(stock_in.quantity * stock_in.purchase_price) as total_aset'),
        ])
        ->when($companyId, fn($q) => $q->where('stock_in.company_id', $companyId))
        ->when($endDate,   fn($q) => $q->whereDate('stock_in.received_date', '<=', $endDate))
        ->groupBy('products.product_type', 'bulan_label', 'bulan_key')
        ->orderBy('products.product_type')
        ->orderByRaw('MIN(stock_in.received_date)')
        ->get();

    $asetGrouped = [];
    foreach ($asetData as $a) {
        $asetGrouped[$a->product_type][] = $a;
    }

    // 4. Bangun baris CSV
    $allTypes = array_unique(array_merge(
        array_keys($fastGrouped), array_keys($asetGrouped)
    ));
    sort($allTypes);

    $csvRows = [];

    // Header utama
    $csvRows[] = ['Laporan Barang Keluar Terbanyak', '', '', '', '', '', '', 'Data Aset'];
    $csvRows[] = ['', '', '', '', '', '', '', ''];

    foreach ($allTypes as $type) {
        $typeLabel     = $typeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type));
        $typeMovements = $fastGrouped[$type] ?? [];
        $typeAset      = $asetGrouped[$type] ?? [];

        // LEFT: kolom A-D
        $leftRows   = [];
        $leftRows[] = [$typeLabel, '', '', ''];
        $leftRows[] = ['Nama Barang', 'Nama User/Perusahaan', 'Jenis Barang', 'No Katalog'];

        // FIX: 1 baris per transaksi, semua kolom langsung terisi
        foreach ($typeMovements as $productName => $productRows) {
            foreach ($productRows as $mov) {
                $leftRows[] = [
                    $productName,
                    $mov->company_name,
                    'precursor/teknis/regular/bakerglas/alat lab',
                    $mov->product_code ?? '-',
                ];
            }
            $leftRows[] = ['Total Fast Move (' . $productName . ')', '', '', count($productRows)];
        }

        // RIGHT: kolom G-H
        $rightRows   = [];
        $rightRows[] = [$typeLabel, ''];
        $rightRows[] = ['Bulan', 'Nominal Aset'];
        foreach ($typeAset as $aset) {
            $rightRows[] = [
                $aset->bulan_label,
                number_format((float) $aset->total_aset, 0, ',', '.'),
            ];
        }

        // Merge side-by-side
        $maxLen = max(count($leftRows), count($rightRows));
        for ($i = 0; $i < $maxLen; $i++) {
            $l = $leftRows[$i]  ?? ['', '', '', ''];
            $r = $rightRows[$i] ?? ['', ''];
            $csvRows[] = [$l[0], $l[1], $l[2], $l[3], '', '', $r[0], $r[1]];
        }

        $csvRows[] = ['', '', '', '', '', '', '', ''];
    }

    // 5. Stream CSV
    $filename = 'laporan_gudang_' . now()->format('Ymd_His') . '.csv';

    return response()->streamDownload(function () use ($csvRows) {
        $handle = fopen('php://output', 'w');
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
        foreach ($csvRows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }, $filename, [
        'Content-Type'        => 'text/csv; charset=UTF-8',
        'Content-Disposition' => "attachment; filename=\"{$filename}\"",
    ]);
}

public function summary(Request $request)
{
    $query = DB::table('stock_movements')
        ->join('products', 'stock_movements.product_id', '=', 'products.product_id')
        ->select(
            'products.product_id',
            'products.product_code',
            'products.product_name',
            'products.product_type',
            'stock_movements.movement_type',
            DB::raw('SUM(stock_movements.quantity) as total_quantity'),
            DB::raw('COUNT(*) as total_movements')
        )
        ->groupBy(
            'products.product_id',
            'products.product_code',
            'products.product_name',
            'products.product_type',
            'stock_movements.movement_type'
        );

    if ($request->filled('company_id')) {
        $query->join('stock_batches', 'stock_movements.batch_id', '=', 'stock_batches.batch_id')
              ->where('stock_batches.company_id', $request->company_id);
    }

    if ($request->filled('product_id'))
        $query->where('products.product_id', $request->product_id);

    return response()->json(['success' => true, 'data' => $query->get()], 200);
}



}
