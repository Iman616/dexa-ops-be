<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class StockBatchController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = StockBatch::with('product');

            if ($request->has('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->has('search')) {
                $query->where('batch_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 15);
            $batches = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Stock batches retrieved successfully',
                'data' => $batches
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve batches: ' . $e->getMessage()
            ], 500);
        }
    }

    public function realTimeStock(Request $request)
    {
        try {
            $query = StockBatch::with(['product'])
                ->select('stock_batches.*')
                ->selectRaw('
                    (SELECT COALESCE(SUM(quantity), 0) 
                     FROM stock_in 
                     WHERE stock_in.batch_id = stock_batches.batch_id) as total_in
                ')
                ->selectRaw('
                    (SELECT COALESCE(SUM(quantity), 0) 
                     FROM stock_out 
                     WHERE stock_out.batch_id = stock_batches.batch_id) as total_out
                ')
                ->selectRaw('
                    (SELECT COALESCE(SUM(quantity), 0) 
                     FROM stock_in 
                     WHERE stock_in.batch_id = stock_batches.batch_id) -
                    (SELECT COALESCE(SUM(quantity), 0) 
                     FROM stock_out 
                     WHERE stock_out.batch_id = stock_batches.batch_id) as current_stock
                ')
                ->selectRaw('
                    (SELECT COALESCE(AVG(purchase_price), 0) 
                     FROM stock_in 
                     WHERE stock_in.batch_id = stock_batches.batch_id) as avg_purchase_price
                ')
                ->selectRaw('
                    (SELECT COALESCE(AVG(selling_price), 0) 
                     FROM stock_out 
                     WHERE stock_out.batch_id = stock_batches.batch_id 
                     AND selling_price > 0) as avg_selling_price
                ')
                ->selectRaw('
                    ((SELECT COALESCE(SUM(quantity), 0) 
                      FROM stock_in 
                      WHERE stock_in.batch_id = stock_batches.batch_id) -
                     (SELECT COALESCE(SUM(quantity), 0) 
                      FROM stock_out 
                      WHERE stock_out.batch_id = stock_batches.batch_id)) *
                    (SELECT COALESCE(AVG(purchase_price), 0) 
                     FROM stock_in 
                     WHERE stock_in.batch_id = stock_batches.batch_id) as stock_value
                ');

            // Filter by product
            if ($request->has('product_id') && $request->product_id) {
                $query->where('stock_batches.product_id', $request->product_id);
            }

            // Filter by status - PERBAIKAN DI SINI
            if ($request->has('status') && $request->status !== 'all') {
                switch ($request->status) {
                    case 'in_stock':
                        $query->havingRaw('current_stock > 0');
                        break;
                    case 'out_of_stock':
                        $query->havingRaw('current_stock <= 0');
                        break;
                    case 'expired':
                        $query->where('expiry_date', '<', now());
                        break;
                    case 'expiring_soon':
                        $query->whereBetween('expiry_date', [now(), now()->addDays(30)]);
                        break;
                }
            }

            // Search
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('batch_number', 'like', "%{$search}%")
                      ->orWhereHas('product', function($pq) use ($search) {
                          $pq->where('product_name', 'like', "%{$search}%")
                            ->orWhere('product_code', 'like', "%{$search}%");
                      });
                });
            }

            // Sort
            $sortBy = $request->get('sort_by', 'batch_number');
            $sortOrder = $request->get('sort_order', 'asc');
            
            if (in_array($sortBy, ['current_stock', 'stock_value', 'total_in', 'total_out'])) {
                $query->orderByRaw("{$sortBy} {$sortOrder}");
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            $perPage = $request->integer('per_page', 15);
            $batches = $query->paginate($perPage);

            // Calculate totals
            $totals = StockBatch::selectRaw('
                COUNT(*) as total_batches,
                COALESCE(SUM((SELECT COALESCE(SUM(quantity), 0) 
                              FROM stock_in 
                              WHERE stock_in.batch_id = stock_batches.batch_id) -
                             (SELECT COALESCE(SUM(quantity), 0) 
                              FROM stock_out 
                              WHERE stock_out.batch_id = stock_batches.batch_id)), 0) as total_stock,
                ROUND(COALESCE(SUM(((SELECT COALESCE(SUM(quantity), 0) 
                               FROM stock_in 
                               WHERE stock_in.batch_id = stock_batches.batch_id) -
                              (SELECT COALESCE(SUM(quantity), 0) 
                               FROM stock_out 
                               WHERE stock_out.batch_id = stock_batches.batch_id)) *
                             (SELECT COALESCE(AVG(purchase_price), 0) 
                              FROM stock_in 
                              WHERE stock_in.batch_id = stock_batches.batch_id)), 0), 0) as total_value
            ')->first();

            return response()->json([
                'success' => true,
                'message' => 'Real-time stock retrieved successfully',
                'data' => $batches,
                'summary' => $totals
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve real-time stock: ' . $e->getMessage()
            ], 500);
        }
    }

    public function batchHistory($batchId)
    {
        try {
            $batch = StockBatch::with('product')->find($batchId);

            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch not found'
                ], 404);
            }

            // Get all stock IN
            $stockIns = DB::table('stock_in')
                ->where('batch_id', $batchId)
                ->select(
                    DB::raw("'IN' as type"),
                    'stock_in_id as id',
                    'quantity',
                    'purchase_price as price',
                    DB::raw('quantity * purchase_price as total_value'),
                    'received_date as date',
                    'notes',
                    'created_at'
                )
                ->get();

            // Get all stock OUT
            $stockOuts = DB::table('stock_out')
                ->where('batch_id', $batchId)
                ->select(
                    DB::raw("'OUT' as type"),
                    'stock_out_id as id',
                    'quantity',
                    'selling_price as price',
                    DB::raw('quantity * selling_price as total_value'),
                    'out_date as date',
                    'transaction_type',
                    'notes',
                    'created_at'
                )
                ->get();

            // Merge and sort
            $movements = $stockIns->concat($stockOuts)->sortBy('date')->values();

            // Calculate running stock
            $runningStock = 0;
            $movementsWithRunning = $movements->map(function($item) use (&$runningStock) {
                if ($item->type === 'IN') {
                    $runningStock += $item->quantity;
                } else {
                    $runningStock -= $item->quantity;
                }
                $item->running_stock = $runningStock;
                return $item;
            });

            // Calculate profit for sold items
            $totalProfit = DB::table('stock_out')
                ->where('stock_out.batch_id', $batchId)
                ->where('stock_out.transaction_type', 'sale')
                ->selectRaw('
                    SUM(
                        stock_out.quantity * (
                            stock_out.selling_price - 
                            (SELECT AVG(purchase_price) 
                             FROM stock_in 
                             WHERE stock_in.batch_id = stock_out.batch_id)
                        )
                    ) as profit
                ')
                ->value('profit') ?? 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'batch' => $batch,
                    'movements' => $movementsWithRunning,
                    'current_stock' => $runningStock,
                    'total_profit' => $totalProfit,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve batch history: ' . $e->getMessage()
            ], 500);
        }
    }

    public function fifoRecommendation(Request $request)
    {
        try {
            $productId = $request->input('product_id');
            $requestedQty = $request->input('quantity', 0);

            if (!$productId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product ID is required'
                ], 400);
            }

            // Get batches with available stock, ordered by expiry date (FIFO)
            $batches = StockBatch::where('product_id', $productId)
                ->select('stock_batches.*')
                ->selectRaw('
                    (SELECT COALESCE(SUM(quantity), 0) 
                     FROM stock_in 
                     WHERE stock_in.batch_id = stock_batches.batch_id) -
                    (SELECT COALESCE(SUM(quantity), 0) 
                     FROM stock_out 
                     WHERE stock_out.batch_id = stock_batches.batch_id) as available_stock
                ')
                ->selectRaw('
                    (SELECT COALESCE(AVG(purchase_price), 0) 
                     FROM stock_in 
                     WHERE stock_in.batch_id = stock_batches.batch_id) as avg_cost
                ')
                ->havingRaw('available_stock > 0')
                ->orderBy('expiry_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->get();

            $recommendation = [];
            $remaining = $requestedQty;

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;

                $takeQty = min($remaining, $batch->available_stock);
                
                $recommendation[] = [
                    'batch_id' => $batch->batch_id,
                    'batch_number' => $batch->batch_number,
                    'expiry_date' => $batch->expiry_date,
                    'available_stock' => $batch->available_stock,
                    'recommended_qty' => $takeQty,
                    'avg_cost' => $batch->avg_cost,
                    'total_cost' => $takeQty * $batch->avg_cost,
                ];

                $remaining -= $takeQty;
            }

            $canFulfill = $remaining <= 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'requested_qty' => $requestedQty,
                    'can_fulfill' => $canFulfill,
                    'shortage' => $remaining > 0 ? $remaining : 0,
                    'recommendations' => $recommendation,
                    'total_cost' => collect($recommendation)->sum('total_cost'),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate FIFO recommendation: ' . $e->getMessage()
            ], 500);
        }
    }

    public function byProduct(Request $request)
    {
        try {
            if (!$request->has('product_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product ID is required'
                ], 400);
            }

            $batches = StockBatch::where('product_id', $request->product_id)
                ->select('stock_batches.*')
                ->selectRaw('
                    (SELECT COALESCE(SUM(quantity), 0) 
                     FROM stock_in 
                     WHERE stock_in.batch_id = stock_batches.batch_id) -
                    (SELECT COALESCE(SUM(quantity), 0) 
                     FROM stock_out 
                     WHERE stock_out.batch_id = stock_batches.batch_id) as available_stock
                ')
                ->havingRaw('available_stock > 0')
                ->orderBy('expiry_date', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Batches retrieved successfully',
                'data' => $batches
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve batches: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,product_id',
            'batch_number' => 'required|string',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
        ]);

        try {
            $batch = StockBatch::create([
                'product_id' => $request->product_id,
                'batch_number' => $request->batch_number,
                'manufacture_date' => $request->manufacture_date,
                'expiry_date' => $request->expiry_date,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Batch created successfully',
                'data' => $batch
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create batch: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $batch = StockBatch::with('product')->find($id);

            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Batch retrieved successfully',
                'data' => $batch
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve batch: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $batch = StockBatch::find($id);

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        $request->validate([
            'batch_number' => 'sometimes|string|max:100',
            'product_id' => 'sometimes|exists:products,product_id',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
        ]);

        try {
            $batch->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Batch updated successfully',
                'data' => $batch
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update batch: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $batch = StockBatch::find($id);

            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch not found'
                ], 404);
            }

            $batch->delete();

            return response()->json([
                'success' => true,
                'message' => 'Batch deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete batch: ' . $e->getMessage()
            ], 500);
        }
    }

    public function expiring(Request $request)
    {
        try {
            $days = $request->get('days', 30);
            $date = now()->addDays($days);

            $batches = StockBatch::with('product')
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '<=', $date)
                ->where('expiry_date', '>=', now())
                ->orderBy('expiry_date', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Expiring batches retrieved successfully',
                'data' => $batches
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expiring batches: ' . $e->getMessage()
            ], 500);
        }
    }

    public function expired(Request $request)
    {
        try {
            $batches = StockBatch::with('product')
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '<', now())
                ->orderBy('expiry_date', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Expired batches retrieved successfully',
                'data' => $batches
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expired batches: ' . $e->getMessage()
            ], 500);
        }
    }
}