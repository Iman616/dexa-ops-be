<?php

namespace App\Http\Controllers\Api;

use App\Models\ProformaInvoice;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class ProformaInvoiceController extends BaseController  // ✅ extends BaseController
{
    /* =========================
     * LIST
     * ========================= */
    public function index(Request $request)
    {
        try {
            $companyId = $this->getCompanyId($request); // ✅ dari BaseController

            $query = ProformaInvoice::with([
                'company:company_id,company_name,company_code',
                'customer:customer_id,customer_name,address,email,phone',
                'createdBy:user_id,full_name',
                // ✅ Load PO dengan relasi activityType-nya
                'purchaseOrder:po_id,po_number,activity_type_id',
                'purchaseOrder.activityType:activity_type_id,type_name,type_code',
            ])
                ->where('company_id', $companyId);



            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('proforma_number', 'like', "%{$request->search}%")
                        ->orWhereHas(
                            'customer',
                            fn($q2) =>
                            $q2->where('customer_name', 'like', "%{$request->search}%")
                        );
                });
            }

            if ($request->filled('date_from')) {
                $query->whereDate('proforma_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('proforma_date', '<=', $request->date_to);
            }

            $allowedSorts = ['proforma_date', 'proforma_number', 'total_amount', 'status', 'created_at'];
            $sortBy = in_array($request->get('sort_by'), $allowedSorts)
                ? $request->get('sort_by')
                : 'proforma_date';
            $sortOrder = $request->get('sort_order', 'desc');

            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->get('per_page', 15);
            $result = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data proforma invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* =========================
     * DETAIL
     * ========================= */
    public function show(Request $request, $id)
    {
        try {
            $companyId = $this->getCompanyId($request);

            $pi = ProformaInvoice::with([
                'company',
                'customer',
                'items.product',
                'createdBy:user_id,full_name',
                'approvedBy:user_id,full_name',
                'invoices:invoice_id,invoice_number,payment_status,total_amount,proforma_invoice_id',
            ])
                ->where('company_id', $companyId)
                ->find($id);

            if (!$pi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proforma invoice tidak ditemukan'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $pi,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail proforma invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* =========================
     * CREATE
     * ========================= */
    public function store(Request $request)
    {

        $companyId = $this->getCompanyId($request);
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,customer_id',
            'po_id' => 'nullable|exists:purchase_orders,po_id',
            'proforma_date' => 'required|date',
            'valid_until' => 'nullable|date|after:proforma_date',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|string|max:255',
            'delivery_terms' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'use_ppn' => 'nullable|boolean',
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
            $companyId = $this->getCompanyId($request);

            $customer = DB::table('customers')
                ->where('customer_id', $request->customer_id)
                ->first();

            $subtotal = collect($request->items)->sum(fn($i) => $i['quantity'] * $i['unit_price']);
            $taxPercentage = $request->tax_percentage ?? 11;
            $taxAmount = $subtotal * ($taxPercentage / 100);
            $discountAmount = $request->discount_amount ?? 0;
            $totalAmount = $subtotal + $taxAmount - $discountAmount;

            $proformaNumber = $this->generateProformaNumber($companyId);

            $validUntil = $request->valid_until
                ?: date('Y-m-d', strtotime('+30 days', strtotime($request->proforma_date)));

            $pi = ProformaInvoice::create([
                'company_id' => $companyId,
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
                'payment_terms' => $request->payment_terms,
                'delivery_terms' => $request->delivery_terms,
                'status' => 'draft',
                'notes' => $request->notes,
                'created_by' => Auth::id(),
                'use_ppn' => $request->has('use_ppn') ? $request->boolean('use_ppn') : true,
            ]);

            foreach ($request->items as $item) {
                $product = DB::table('products')
                    ->where('product_id', $item['product_id'])
                    ->first();

                $pi->items()->create([
                    'product_id' => $item['product_id'],
                    'product_description' => $product->description ?? null,
                    'product_code' => $product->product_code ?? null,
                    'brand' => $product->brand ?? null,
                    'product_name' => $product->product_name,
                    'quantity' => $item['quantity'],
                    'unit' => $product->unit,
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['quantity'] * $item['unit_price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Proforma invoice berhasil dibuat',
                'data' => $pi->load(['company', 'customer', 'items.product']),
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error creating proforma invoice: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat proforma invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* =========================
     * UPDATE (DRAFT ONLY)
     * ========================= */
    public function update(Request $request, $id)
    {
        try {
            $companyId = $this->getCompanyId($request);

            $pi = ProformaInvoice::where('company_id', $companyId)->find($id);

            if (!$pi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proforma invoice tidak ditemukan'
                ], 404);
            }

            if ($pi->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya status draft yang bisa diedit'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,customer_id',
                'proforma_date' => 'required|date',
                'valid_until' => 'nullable|date|after:proforma_date',
                'tax_percentage' => 'nullable|numeric|min:0|max:100',
                'discount_amount' => 'nullable|numeric|min:0',
                'payment_terms' => 'nullable|string|max:255',
                'delivery_terms' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,product_id',
                'items.*.quantity' => 'required|numeric|min:0.01',
                'items.*.unit_price' => 'required|numeric|min:0',
                'use_ppn' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $subtotal = collect($request->items)->sum(fn($i) => $i['quantity'] * $i['unit_price']);
            $taxPercentage = $request->tax_percentage ?? $pi->tax_percentage ?? 11;
            $taxAmount = $subtotal * ($taxPercentage / 100);
            $discountAmount = $request->discount_amount ?? 0;
            $totalAmount = $subtotal + $taxAmount - $discountAmount;

            $customer = DB::table('customers')
                ->where('customer_id', $request->customer_id)
                ->first();

            $pi->update([
                'customer_id' => $request->customer_id,
                'customer_name' => $customer->customer_name,
                'customer_address' => $customer->address,
                'proforma_date' => $request->proforma_date,
                'valid_until' => $request->valid_until ?? $pi->valid_until,
                'subtotal' => $subtotal,
                'tax_percentage' => $taxPercentage,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'payment_terms' => $request->payment_terms,
                'delivery_terms' => $request->delivery_terms,
                'notes' => $request->notes,
                 'use_ppn' => $request->has('use_ppn') ? $request->boolean('use_ppn') : $pi->use_ppn, // ✅ fallback ke nilai lama
            ]);

            $pi->items()->delete();

            foreach ($request->items as $item) {
                $product = DB::table('products')
                    ->where('product_id', $item['product_id'])
                    ->first();

                $pi->items()->create([
                    'product_id' => $item['product_id'],
                    'product_name' => $product->product_name,
                    'product_description' => $product->description ?? null,
                    'product_code' => $product->product_code ?? null,
                    'brand' => $product->brand ?? null,
                    'quantity' => $item['quantity'],
                    'unit' => $product->unit,
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['quantity'] * $item['unit_price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Proforma invoice berhasil diupdate',
                'data' => $pi->fresh(['company', 'customer', 'items.product']),
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate proforma invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* =========================
     * ISSUE (DRAFT → ISSUED)
     * ========================= */
    /* =========================
     * ISSUE (DRAFT → ISSUED)
     * ========================= */
    public function issue(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'signed_name' => 'required|string|max:100',
            'signed_position' => 'required|string|max:100',
            'signed_city' => 'required|string|max:50',
            // ✅ Tambah validasi signature_image (base64 string atau file upload)
            'signature_image' => 'nullable|string', // jika base64
            // atau gunakan ini jika upload file:
            // 'signature_image' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $companyId = $this->getCompanyId($request);

            $pi = ProformaInvoice::where('company_id', $companyId)->find($id);

            if (!$pi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proforma invoice tidak ditemukan'
                ], 404);
            }

            if ($pi->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya status draft yang bisa di-issue'
                ], 422);
            }

            // ✅ Handle signature_image
            $signatureImagePath = $pi->signature_image; // pertahankan yang lama jika tidak diupdate

            if ($request->filled('signature_image')) {
                // Jika base64 → decode dan simpan sebagai file
                $base64 = $request->signature_image;

                // Strip prefix "data:image/png;base64," jika ada
                if (str_contains($base64, ';base64,')) {
                    [, $base64] = explode(';base64,', $base64);
                }

                $decoded = base64_decode($base64);
                $filename = 'signatures/proforma_' . $id . '_' . time() . '.png';

                \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $decoded);

                $signatureImagePath = $filename;
            }

            // Jika menggunakan file upload (multipart/form-data), gunakan ini sebagai alternatif:
            // if ($request->hasFile('signature_image')) {
            //     $signatureImagePath = $request->file('signature_image')
            //         ->store('signatures', 'public');
            // }

            $pi->update([
                'status' => 'issued',
                'signed_name' => $request->signed_name,
                'signed_position' => $request->signed_position,
                'signed_city' => $request->signed_city,
                'signed_at' => now(),
                'issued_by' => Auth::id(),
                'issued_at' => now(),
                'signature_image' => $signatureImagePath, // ✅ Tambah ini
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proforma invoice berhasil di-issue',
                'data' => $pi->fresh(['company', 'customer']),
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal issue proforma invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /* =========================
     * UPDATE STATUS
     * ========================= */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:sent,approved,rejected,cancelled',
            'cancellation_reason' => 'required_if:status,cancelled|nullable|string|min:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $companyId = $this->getCompanyId($request);

            $pi = ProformaInvoice::where('company_id', $companyId)->find($id);

            if (!$pi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proforma invoice tidak ditemukan'
                ], 404);
            }

            $allowedTransitions = [
                'issued' => ['sent', 'cancelled'],
                'sent' => ['approved', 'rejected'],
                'rejected' => ['sent'],
            ];

            if (
                !isset($allowedTransitions[$pi->status]) ||
                !in_array($request->status, $allowedTransitions[$pi->status])
            ) {
                return response()->json([
                    'success' => false,
                    'message' => "Tidak bisa ubah status dari '{$pi->status}' ke '{$request->status}'"
                ], 422);
            }

            $updateData = ['status' => $request->status];

            if ($request->status === 'approved') {
                $updateData['approved_by'] = Auth::id();
                $updateData['approved_at'] = now();
            }

            if ($request->status === 'cancelled') {
                $updateData['cancellation_reason'] = $request->cancellation_reason;
            }

            $pi->update($updateData);

            return response()->json([
                'success' => true,
                'message' => "Status berhasil diubah ke '{$request->status}'",
                'data' => $pi->fresh(),
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* =========================
     * DELETE (DRAFT ONLY)
     * ========================= */
    public function destroy(Request $request, $id)
    {
        try {
            $companyId = $this->getCompanyId($request);

            $pi = ProformaInvoice::where('company_id', $companyId)->find($id);

            if (!$pi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proforma invoice tidak ditemukan'
                ], 404);
            }

            if ($pi->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya proforma invoice draft yang bisa dihapus'
                ], 422);
            }

            if ($pi->invoices()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak bisa dihapus karena sudah memiliki invoice',
                    'data' => [
                        'invoices_count' => $pi->invoices()->count(),
                        'invoice_numbers' => $pi->invoices()->pluck('invoice_number'),
                    ]
                ], 409);
            }

            DB::beginTransaction();
            $pi->items()->delete();
            $pi->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Proforma invoice berhasil dihapus',
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus proforma invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* =========================
     * CONVERT TO INVOICE
     * ========================= */
    /* =========================
     * CONVERT TO INVOICE
     * ========================= */
 // CONVERT TO INVOICE
public function convertToInvoice(Request $request, $id)
{
    try {
        $companyId = $this->getCompanyId($request);

        $proforma = ProformaInvoice::with(['items.product', 'company', 'customer'])
            ->where('company_id', $companyId)
            ->find($id);

        if (!$proforma) {
            return response()->json(['success' => false, 'message' => 'Proforma invoice tidak ditemukan'], 404);
        }

        if ($proforma->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya proforma invoice yang sudah disetujui (approved) yang bisa dikonversi ke Invoice',
                'current_status' => $proforma->status,
            ], 422);
        }

        if ($proforma->converted_to_invoice_id) {
            return response()->json([
                'success' => false,
                'message' => 'Proforma invoice ini sudah pernah dikonversi ke Invoice.',
                'data' => [
                    'invoice_id'   => $proforma->converted_to_invoice_id,
                    'converted_at' => $proforma->converted_at,
                ],
            ], 409);
        }

        $invoiceNumber = $this->generateInvoiceNumber($companyId);

        $invoiceController = new InvoiceController();

        // ✅ Pass SEMUA data keuangan dari proforma + use_ppn
        $newRequest = new Request([
            'company_id'          => $companyId,
            'customer_id'         => $proforma->customer_id,         // ✅
            'po_id'               => $proforma->po_id,               // ✅
            'proforma_invoice_id' => $proforma->proforma_id,
            'invoice_number'      => $invoiceNumber,
            'invoice_date'        => now()->format('Y-m-d'),
            'due_date'            => now()->addDays(30)->format('Y-m-d'),
            'subtotal'            => $proforma->subtotal,
            'tax_percentage'      => $proforma->tax_percentage,      // ✅
            'tax_amount'          => $proforma->tax_amount,          // ✅
            'discount_amount'     => $proforma->discount_amount,     // ✅
            'total_amount'        => $proforma->total_amount,        // ✅
            'use_ppn'             => (bool) $proforma->use_ppn,      // ✅ KUNCI
            'payment_terms'       => $proforma->payment_terms ?? 'Net 30 hari',
            'delivery_terms'      => $proforma->delivery_terms ?? 'FOB Destination',
            'payment_reference'   => $request->payment_reference,
            'create_tax_invoice'  => true,
            'currency'            => $proforma->currency ?? 'IDR',   // ✅
        ]);

        $newRequest->setUserResolver($request->getUserResolver());

        return $invoiceController->createFromProformaInvoice($newRequest);

    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengkonversi ke invoice',
            'error'   => $e->getMessage(),
        ], 500);
    }
}

    /* =========================
     * INVOICE NUMBER GENERATOR
     * ========================= */
    private function generateInvoiceNumber(int $companyId): string
    {
        $company = DB::table('companies')
            ->where('company_id', $companyId)
            ->first();

        $code = $company->company_code ?? 'UNK';
        $year = date('Y');
        $month = date('m');

        $last = Invoice::where('company_id', $companyId)
            ->whereYear('invoice_date', $year)
            ->whereMonth('invoice_date', $month)
            ->orderByDesc('invoice_id')
            ->first();

        $num = $last
            ? (int) substr($last->invoice_number, -5) + 1
            : 1;

        return "INV/{$code}/{$year}/{$month}/" . str_pad($num, 5, '0', STR_PAD_LEFT);
    }


    /* =========================
     * NUMBER GENERATOR
     * ========================= */
    private function generateProformaNumber(int $companyId): string
    {
        $company = DB::table('companies')
            ->where('company_id', $companyId)
            ->first();

        $code = $company->company_code ?? 'UNK';
        $year = date('Y');
        $month = date('m');

        $last = ProformaInvoice::where('company_id', $companyId)
            ->whereYear('proforma_date', $year)
            ->whereMonth('proforma_date', $month)
            ->orderByDesc('proforma_id')
            ->lockForUpdate()
            ->first();

        $num = $last
            ? (int) substr($last->proforma_number, -5) + 1
            : 1;

        return "PI/{$code}/{$year}/{$month}/" . str_pad($num, 5, '0', STR_PAD_LEFT);
    }
}
