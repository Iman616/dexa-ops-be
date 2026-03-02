<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\EndingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class StockOpnameController extends Controller
{
    /* ── INDEX ── */
    public function index(Request $request)
    {
        $query = StockOpname::with(['conductedByUser:user_id,full_name', 'approvedByUser:user_id,full_name'])
            ->withCount('items');

        if ($request->filled('company_id'))
            $query->where('company_id', $request->company_id);

        if ($request->filled('status'))
            $query->where('status', $request->status);

        if ($request->filled('start_date'))
            $query->whereDate('opname_date', '>=', $request->start_date);

        if ($request->filled('end_date'))
            $query->whereDate('opname_date', '<=', $request->end_date);

        if ($request->filled('search'))
            $query->where('opname_number', 'like', "%{$request->search}%");

        $query->orderBy('created_at', 'desc');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->get('per_page', 15)),
        ]);
    }

    /* ── STORE (buat opname baru + auto-load semua batch aktif) ── */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id'   => 'required|exists:companies,company_id',
            'opname_date'  => 'required|date',
            'period_year'  => 'required|integer',
            'period_month' => 'required|integer|min:1|max:12',
            'notes'        => 'nullable|string',
        ]);

        if ($validator->fails())
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            DB::beginTransaction();

            $company      = \App\Models\Company::findOrFail($request->company_id);
            $opnameNumber = StockOpname::generateOpnameNumber($company->company_code);

            $opname = StockOpname::create([
                'company_id'   => $request->company_id,
                'opname_number'=> $opnameNumber,
                'opname_date'  => $request->opname_date,
                'period_year'  => $request->period_year,
                'period_month' => $request->period_month,
                'status'       => 'draft',
                'notes'        => $request->notes,
                'conducted_by' => Auth::id(),
                'created_by'   => Auth::id(),
            ]);

            // ── Auto-load semua batch aktif perusahaan ke items ──
            $batches = StockBatch::with('product')
                ->where('company_id', $request->company_id)
                ->where('status', '!=', 'depleted')
                ->get();

            $items = $batches->map(fn($batch) => [
                'opname_id'       => $opname->opname_id,
                'product_id'      => $batch->product_id,
                'batch_id'        => $batch->batch_id,
                'system_quantity' => $batch->quantity_available,
                'physical_quantity' => null,
                'difference'        => null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ])->toArray();

            StockOpnameItem::insert($items);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock opname berhasil dibuat',
                'data'    => $opname->load(['items.product', 'items.batch']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ── SHOW ── */
    public function show($id)
    {
        $opname = StockOpname::with([
            'items.product:product_id,product_name,product_code,product_type,unit',
            'items.batch:batch_id,batch_number,expiry_date,purchase_price',
            'conductedByUser:user_id,full_name',
            'approvedByUser:user_id,full_name',
            'company:company_id,company_name,company_code',
        ])->find($id);

        if (!$opname)
            return response()->json(['success' => false, 'message' => 'Opname tidak ditemukan'], 404);

        return response()->json(['success' => true, 'data' => $opname]);
    }

    /* ── UPDATE ITEM (input fisik qty) ── */
    public function updateItem(Request $request, $opnameId, $itemId)
    {
        $opname = StockOpname::find($opnameId);

        if (!$opname)
            return response()->json(['success' => false, 'message' => 'Opname tidak ditemukan'], 404);

        if (!$opname->can_edit)
            return response()->json(['success' => false, 'message' => 'Opname tidak bisa diedit'], 422);

        $validator = Validator::make($request->all(), [
            'physical_quantity' => 'required|numeric|min:0',
            'notes'             => 'nullable|string',
        ]);

        if ($validator->fails())
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        $item = StockOpnameItem::where('opname_id', $opnameId)->find($itemId);

        if (!$item)
            return response()->json(['success' => false, 'message' => 'Item tidak ditemukan'], 404);

        $item->update([
            'physical_quantity' => $request->physical_quantity,
            'difference'        => $request->physical_quantity - $item->system_quantity,
            'notes'             => $request->notes,
        ]);

        // Auto update status opname ke in_progress
        if ($opname->status === 'draft') {
            $opname->update(['status' => 'in_progress']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Item berhasil diupdate',
            'data'    => $item->fresh(['product', 'batch']),
        ]);
    }

    /* ── COMPLETE (tandai selesai hitung) ── */
    public function complete(Request $request, $id)
    {
        $opname = StockOpname::with('items')->find($id);

        if (!$opname)
            return response()->json(['success' => false, 'message' => 'Opname tidak ditemukan'], 404);

        $unfilledCount = $opname->items->whereNull('physical_quantity')->count();

        if ($unfilledCount > 0)
            return response()->json([
                'success' => false,
                'message' => "Masih ada {$unfilledCount} item yang belum diisi",
            ], 422);

        $opname->update(['status' => 'completed']);

        return response()->json(['success' => true, 'message' => 'Opname selesai dihitung']);
    }

    /* ── APPROVE + AUTO ADJUSTMENT ── */
    public function approve(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'approval_notes' => 'nullable|string',
        ]);

        $opname = StockOpname::with('items.batch')->find($id);

        if (!$opname)
            return response()->json(['success' => false, 'message' => 'Opname tidak ditemukan'], 404);

        if (!$opname->can_approve)
            return response()->json(['success' => false, 'message' => 'Opname belum selesai dihitung'], 422);

        try {
            DB::beginTransaction();

            foreach ($opname->items as $item) {
                if (is_null($item->difference) || $item->difference == 0) continue;

                // Buat ADJUSTMENT movement
                $movement = StockMovement::create([
                    'company_id'     => $opname->company_id,
                    'product_id'     => $item->product_id,
                    'batch_id'       => $item->batch_id,
                    'movement_type'  => 'ADJUSTMENT',
                    'quantity'       => abs($item->difference),
                    'reference_id'   => $opname->opname_id,
                    'reference_type' => 'stock_opname',
                    'notes'          => ($item->difference > 0 ? '+' : '-') .
                                        abs($item->difference) .
                                        " (Opname: {$opname->opname_number})",
                    'created_by'     => Auth::id(),
                ]);

                // Update quantity_available di batch
                $item->batch->update([
                    'quantity_available' => $item->physical_quantity,
                ]);

                // Tandai item sudah di-adjust
                $item->update(['adjustment_movement_id' => $movement->movement_id]);

                // Refresh ending stock
                EndingStock::updateEndingStock(
                    $item->batch_id,
                    $opname->period_year,
                    $opname->period_month
                );
            }

            $opname->update([
                'status'         => 'approved',
                'approved_by'    => Auth::id(),
                'approved_at'    => now(),
                'approval_notes' => $request->approval_notes,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Opname disetujui dan stok telah disesuaikan',
                'data'    => $opname->fresh(['items.product', 'items.batch']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ── DESTROY ── */
    public function destroy($id)
    {
        $opname = StockOpname::find($id);

        if (!$opname)
            return response()->json(['success' => false, 'message' => 'Opname tidak ditemukan'], 404);

        if ($opname->status === 'approved')
            return response()->json(['success' => false, 'message' => 'Opname yang sudah disetujui tidak bisa dihapus'], 422);

        $opname->delete();

        return response()->json(['success' => true, 'message' => 'Opname berhasil dihapus']);
    }
}
