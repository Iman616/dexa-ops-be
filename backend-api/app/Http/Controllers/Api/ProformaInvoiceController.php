<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ProformaInvoice;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class ProformaInvoiceController extends Controller
{
    /* =========================
     * LIST
     * ========================= */
    public function index(Request $request)
    {
        $query = DB::table('proforma_invoices as pi')
            ->join('companies as co', 'pi.company_id', '=', 'co.company_id')
            ->join('customers as cu', 'pi.customer_id', '=', 'cu.customer_id')
            ->select(
                'pi.proforma_id',
                'pi.proforma_number',
                'pi.proforma_date',
                'pi.status',
                'pi.subtotal',
                'pi.tax_amount',
                'pi.discount_amount',
                'pi.total_amount',
                'co.company_name',
                'cu.customer_name',
                'pi.valid_until',
                'pi.signed_name',
                'pi.signed_at',
                'pi.created_at'
            );

        // Filters
        if ($request->filled('company_id')) {
            $query->where('pi.company_id', $request->company_id);
        }

        if ($request->filled('status')) {
            $query->where('pi.status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('pi.proforma_number', 'like', "%{$request->search}%")
                  ->orWhere('cu.customer_name', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('pi.proforma_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('pi.proforma_date', '<=', $request->date_to);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'proforma_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy("pi.{$sortBy}", $sortOrder);

        $perPage = $request->get('per_page', 15);
        $result = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $result->items(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
            'per_page' => $result->perPage(),
            'total' => $result->total(),
        ]);
    }

    

    /* =========================
     * DETAIL
     * ========================= */
    public function show($id)
    {
        $pi = DB::table('proforma_invoices as pi')
            ->join('companies as co', 'pi.company_id', '=', 'co.company_id')
            ->join('customers as cu', 'pi.customer_id', '=', 'cu.customer_id')
            ->leftJoin('users as creator', 'pi.created_by', '=', 'creator.user_id')
            ->leftJoin('users as approver', 'pi.approved_by', '=', 'approver.user_id')
            ->where('pi.proforma_id', $id)
            ->select(
                'pi.*',
                'co.company_name',
                'co.company_code',
                'co.address as company_address',
                'cu.customer_name',
                'cu.address as customer_address',
                'cu.email as customer_email',
                'cu.phone as customer_phone',
                'creator.full_name as created_by_name',
                'approver.full_name as approved_by_name'
            )
            ->first();

        if (!$pi) {
            return response()->json([
                'success' => false,
                'message' => 'Proforma invoice not found'
            ], 404);
        }

        $items = DB::table('proforma_invoice_items')
            ->join('products as p', 'proforma_invoice_items.product_id', '=', 'p.product_id')
            ->where('proforma_id', $id)
            ->select(
                'proforma_invoice_items.*',
                'p.product_code',
                'p.unit'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'header' => $pi,
                'items' => $items
            ]
        ]);
    }

    /* =========================
     * CREATE
     * ========================= */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
            'customer_id' => 'required|exists:customers,customer_id',
            'po_id' => 'nullable|exists:purchase_orders,po_id',
            'proforma_date' => 'required|date',
            'valid_until' => 'nullable|date|after:proforma_date',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $customer = DB::table('customers')
                ->where('customer_id', $request->customer_id)
                ->first();

            // Calculate amounts
            $subtotal = collect($request->items)
                ->sum(fn($i) => $i['quantity'] * $i['unit_price']);

            $taxPercentage = $request->tax_percentage ?? 11;
            $taxAmount = $subtotal * ($taxPercentage / 100);
            $discountAmount = $request->discount_amount ?? 0;
            $totalAmount = $subtotal + $taxAmount - $discountAmount;

            // Generate proforma number
            $proformaNumber = $this->generateProformaNumber($request->company_id);

            // Calculate valid_until (default 30 days)
            $validUntil = $request->valid_until 
                ? $request->valid_until 
                : date('Y-m-d', strtotime("+30 days", strtotime($request->proforma_date)));

            $proformaId = DB::table('proforma_invoices')->insertGetId([
                'company_id' => $request->company_id,
                'customer_id' => $request->customer_id,
                'po_id' => $request->po_id,
                'customer_name' => $customer->customer_name,
                'customer_address' => $customer->address,
                'currency' => 'IDR',
                'proforma_number' => $proformaNumber,
                'proforma_date' => $request->proforma_date,
                'valid_until' => $validUntil,
                'subtotal' => $subtotal,
                'tax_percentage' => $taxPercentage,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'status' => 'draft',
                'notes' => $request->notes,
                'created_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($request->items as $item) {
                $product = DB::table('products')
                    ->where('product_id', $item['product_id'])
                    ->first();

                DB::table('proforma_invoice_items')->insert([
                    'proforma_id' => $proformaId,
                    'product_id' => $item['product_id'],
                    'product_name' => $product->product_name,
                    'quantity' => $item['quantity'],
                    'unit' => $product->unit,
                    'unit_price' => $item['unit_price'],
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Proforma invoice created',
                'proforma_id' => $proformaId
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error creating proforma invoice: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create proforma invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* =========================
     * UPDATE DATA (DRAFT ONLY)
     * ========================= */
    public function update(Request $request, $id)
    {
        $pi = DB::table('proforma_invoices')
            ->where('proforma_id', $id)
            ->first();

        if (!$pi) {
            return response()->json([
                'success' => false,
                'message' => 'Proforma invoice not found'
            ], 404);
        }

        if ($pi->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft can be edited'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,company_id',
            'customer_id' => 'required|exists:customers,customer_id',
            'proforma_date' => 'required|date',
            'items' => 'required|array|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Recalculate
            $subtotal = collect($request->items)
                ->sum(fn($i) => $i['quantity'] * $i['unit_price']);
            
            $taxPercentage = $request->tax_percentage ?? 11;
            $taxAmount = $subtotal * ($taxPercentage / 100);
            $discountAmount = $request->discount_amount ?? 0;
            $totalAmount = $subtotal + $taxAmount - $discountAmount;

            DB::table('proforma_invoices')
                ->where('proforma_id', $id)
                ->update([
                    'company_id' => $request->company_id,
                    'customer_id' => $request->customer_id,
                    'proforma_date' => $request->proforma_date,
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'total_amount' => $totalAmount,
                    'notes' => $request->notes,
                    'updated_at' => now()
                ]);

            // Delete old items
            DB::table('proforma_invoice_items')
                ->where('proforma_id', $id)
                ->delete();

            // Insert new items
            foreach ($request->items as $item) {
                $product = DB::table('products')
                    ->where('product_id', $item['product_id'])
                    ->first();

                DB::table('proforma_invoice_items')->insert([
                    'proforma_id' => $id,
                    'product_id' => $item['product_id'],
                    'product_name' => $product->product_name,
                    'quantity' => $item['quantity'],
                    'unit' => $product->unit,
                    'unit_price' => $item['unit_price'],
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Proforma invoice updated'
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /* =========================
     * ISSUE PROFORMA (DRAFT → ISSUED)
     * ========================= */
    public function issue(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'signed_name' => 'required|string|max:100',
            'signed_position' => 'required|string|max:100',
            'signed_city' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $pi = DB::table('proforma_invoices')
            ->where('proforma_id', $id)
            ->first();

        if (!$pi) {
            return response()->json([
                'success' => false,
                'message' => 'Proforma invoice not found'
            ], 404);
        }

        if ($pi->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft can be issued'
            ], 422);
        }

        DB::table('proforma_invoices')
            ->where('proforma_id', $id)
            ->update([
                'status' => 'issued',
                'signed_name' => $request->signed_name,
                'signed_position' => $request->signed_position,
                'signed_city' => $request->signed_city,
                'signed_at' => now(),
                'issued_by' => Auth::id(),
                'issued_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Proforma invoice issued successfully'
        ]);
    }

    /* =========================
     * UPDATE STATUS
     * ========================= */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:sent,approved,rejected,cancelled'
        ]);

        $pi = DB::table('proforma_invoices')
            ->where('proforma_id', $id)
            ->first();

        if (!$pi) {
            return response()->json([
                'success' => false,
                'message' => 'Not found'
            ], 404);
        }

        // Status flow validation
        $allowedTransitions = [
            'issued' => ['sent', 'cancelled'],
            'sent' => ['approved', 'rejected'],
            'rejected' => ['sent'], // bisa dikirim ulang
        ];

        $currentStatus = $pi->status;
        $newStatus = $request->status;

        if (!isset($allowedTransitions[$currentStatus]) || 
            !in_array($newStatus, $allowedTransitions[$currentStatus])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status transition'
            ], 422);
        }

        $updateData = [
            'status' => $newStatus,
            'updated_at' => now(),
        ];

        if ($newStatus === 'approved') {
            $updateData['approved_by'] = Auth::id();
            $updateData['approved_at'] = now();
        }

        DB::table('proforma_invoices')
            ->where('proforma_id', $id)
            ->update($updateData);

        return response()->json(['success' => true]);
    }

    /* =========================
     * DELETE (DRAFT ONLY)
     * ========================= */
    // Di ProformaInvoiceController destroy()
public function destroy($id)
{
    $pi = ProformaInvoice::find($id);

    if (!$pi) {
        return response()->json([
            'success' => false,
            'message' => 'Proforma Invoice tidak ditemukan'
        ], 404);
    }

    // ✅ CHECK: Jika sudah ada invoice, tidak bisa dihapus
    if ($pi->invoices()->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Proforma Invoice tidak bisa dihapus karena sudah memiliki invoice',
            'data' => [
                'invoices_count' => $pi->invoices()->count(),
                'invoice_numbers' => $pi->invoices()->pluck('invoice_number')
            ]
        ], 409);
    }

    try {
        $pi->delete();
        return response()->json([
            'success' => true,
            'message' => 'Proforma Invoice berhasil dihapus'
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal menghapus Proforma Invoice',
            'error' => $e->getMessage()
        ], 500);
    }
}


    /* =========================
     * CONVERT TO INVOICE
     * ========================= */
   /**
 * Convert proforma invoice to invoice
 */
public function convertToInvoice($id)
{
    $proforma = ProformaInvoice::find($id);

    if (!$proforma) {
        return response()->json([
            'success' => false,
            'message' => 'Proforma invoice not found'
        ], 404);
    }

    // Validasi status
    if ($proforma->status !== 'approved') {
        return response()->json([
            'success' => false,
            'message' => 'Only approved proforma invoices can be converted to invoice'
        ], 422);
    }

    if ($proforma->converted_to_invoice_id) {
        return response()->json([
            'success' => false,
            'message' => 'Proforma invoice already converted to invoice',
            'data' => [
                'invoice_id' => $proforma->converted_to_invoice_id
            ]
        ], 422);
    }

    try {
        DB::beginTransaction();

        // Generate invoice number
        $lastInvoice = \App\Models\Invoice::where('company_id', $proforma->company_id)
            ->orderBy('invoice_id', 'desc')
            ->first();
        
        $nextNumber = $lastInvoice ? (int)substr($lastInvoice->invoice_number, -4) + 1 : 1;
        $invoiceNumber = 'INV-' . date('Ym') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        // Calculate due date (default 30 days dari invoice date)
        $invoiceDate = now();
        $dueDate = now()->addDays(30);

        // Call InvoiceController method
        $invoiceController = new \App\Http\Controllers\Api\InvoiceController();
        $response = $invoiceController->createFromProformaInvoice(new \Illuminate\Http\Request([
            'proforma_invoice_id' => $proforma->proforma_id,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate->format('Y-m-d'),
            'due_date' => $dueDate->format('Y-m-d'),
            'payment_terms' => $proforma->payment_terms,
            'delivery_terms' => $proforma->delivery_terms,
        ]));

        DB::commit();

        return $response;

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Failed to convert to invoice',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /* =========================
     * NUMBER GENERATOR
     * ========================= */
    private function generateProformaNumber($companyId)
    {
        $company = DB::table('companies')
            ->where('company_id', $companyId)
            ->first();

        $code = $company->company_code ?? 'UNK';
        $year = date('Y');
        $month = date('m');

        $last = DB::table('proforma_invoices')
            ->where('company_id', $companyId)
            ->whereYear('proforma_date', $year)
            ->whereMonth('proforma_date', $month)
            ->orderByDesc('proforma_id')
            ->first();

        $num = $last
            ? intval(substr($last->proforma_number, -5)) + 1
            : 1;

        return "PI/{$code}/{$year}/{$month}/" .
            str_pad($num, 5, '0', STR_PAD_LEFT);
    }
}
