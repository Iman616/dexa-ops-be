<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\DeliveryNoteItem;

use App\Models\TaxInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\ProformaInvoice;
use App\Models\Payment;
use App\Models\DeliveryNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class InvoiceController extends BaseController  // ✅ extends BaseController
{
    /**
     * Display a listing of invoices
     */
    public function index(Request $request)
    {
        try {
            $companyId = $this->getCompanyId($request);

            $query = Invoice::with([
                'company:company_id,company_name,company_code',
                'customer:customer_id,customer_name,address,phone,email',

                // ✅ Load PO + activityType sekaligus
                'purchase_order' => function ($q) {
                    $q->select('po_id', 'po_number', 'activity_type_id')
                        ->with('activityType:activity_type_id,type_name');
                },

                'proforma_invoice:proforma_id,proforma_number',
                'items',

                'payments' => function ($q) {
                    $q->latest()->limit(1);
                },
                'delivery_notes' => function ($q) {
                    $q->latest('delivery_date')->limit(1);
                },
                'created_by_user:user_id,full_name',
            ])
                ->where('company_id', $companyId);

            // 🔍 Search
            if ($request->filled('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('invoice_number', 'LIKE', "%{$request->search}%")
                        ->orWhereHas('customer', function ($q2) use ($request) {
                            $q2->where('customer_name', 'LIKE', "%{$request->search}%");
                        });
                });
            }

            // 🎯 Filter Customer
            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            // 💰 Filter Payment Status
            if ($request->filled('payment_status')) {
                $statuses = explode(',', $request->payment_status);
                $query->whereIn('payment_status', $statuses);
            }

            // ⏰ Overdue Filter
            if ($request->overdue_only === 'true' || $request->overdue_only === true) {
                $query->where('due_date', '<', now())
                    ->whereNotIn('payment_status', ['paid', 'completed']);
            }

            // 📅 Filter Tanggal
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('invoice_date', [
                    $request->start_date,
                    $request->end_date
                ]);
            }

            /*
            ✅ SORTING PALING BARU
            Kalau ada update item/payment/delivery
            biasanya invoice ikut update timestamp
            */
            $sortBy = $request->sort_by ?? 'updated_at';
            $sortOrder = $request->sort_order ?? 'desc';

            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->per_page ?? 15;

            $invoices = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $invoices
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch invoices',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * ✅ UPDATED: Create invoice from Proforma Invoice
     * + Auto-create Tax Invoice (Faktur Pajak)
     */
    public function createFromProformaInvoice(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $validator = Validator::make($request->all(), [
            'proforma_invoice_id' => 'required|exists:proforma_invoices,proforma_id',
            'invoice_number' => 'required|string|max:100|unique:invoices,invoice_number',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'payment_terms' => 'nullable|string|max:255',
            'delivery_terms' => 'nullable|string|max:255',
            'create_tax_invoice' => 'boolean',
            'tax_invoice_number' => 'nullable|string|max:100',
            'tax_invoice_date' => 'nullable|date',
            'use_ppn' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $pi = ProformaInvoice::with('items')
                ->where('company_id', $companyId) // ✅ guard cross-company
                ->findOrFail($request->proforma_invoice_id);

            if ($pi->converted_to_invoice_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proforma Invoice sudah dikonversi ke Invoice'
                ], 422);
            }

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'customer_id' => $request->customer_id,
                'po_id' => $request->po_id,
                'proforma_invoice_id' => $request->proforma_invoice_id,
                'invoice_number' => $request->invoice_number,
                'invoice_date' => $request->invoice_date,
                'due_date' => $request->due_date,
                'subtotal' => $request->subtotal,
                'tax_percentage' => $request->tax_percentage,
                'tax_amount' => $request->tax_amount,
                'discount_amount' => $request->discount_amount ?? 0,
                'total_amount' => $request->total_amount,
                'currency' => $request->currency ?? 'IDR',
                'payment_status' => 'unpaid',
                'payment_terms' => $request->payment_terms,
                'delivery_terms' => $request->delivery_terms,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
                // ✅ FIX: Simpan use_ppn dari request, default true
                'use_ppn' => $request->has('use_ppn') ? $request->boolean('use_ppn') : true,
            ]);

          foreach ($pi->items as $piItem) {
    InvoiceItem::create([
        'invoice_id'          => $invoice->invoice_id,
        'product_id'          => $piItem->product_id,
        'product_name'        => $piItem->product_name,
        'product_description' => $piItem->product_description ?? null,
        'quantity'            => $piItem->quantity,
        'unit'                => $piItem->unit,
        'unit_price'          => $piItem->unit_price,
        'discount_percent'    => $piItem->discount_percent ?? 0,
        'notes'               => $piItem->notes,
    ]);
}
            $pi->update([
                'status' => 'converted',
                'converted_to_invoice_id' => $invoice->invoice_id,
                'converted_at' => now(),
            ]);

           $taxInvoice = null;
if ($request->boolean('create_tax_invoice', true) && (bool) $invoice->use_ppn) {
    $taxInvoice = $this->autoCreateTaxInvoice($invoice, $request);
}

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully from Proforma Invoice',
                'data' => $invoice->load(['items', 'customer', 'proformaInvoice']),
                'tax_invoice' => $taxInvoice,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create invoice from proforma invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ UPDATED: Store a newly created invoice
     * + Auto-create Tax Invoice option
     */
    public function store(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $validator = Validator::make($request->all(), [
            // ✅ company_id tidak perlu dari request, diambil dari session
            'customer_id' => 'required|exists:customers,customer_id',
            'po_id' => 'nullable|exists:purchase_orders,po_id',
            'proforma_invoice_id' => 'nullable|exists:proforma_invoices,proforma_id',
            'invoice_number' => 'required|string|max:50|unique:invoices,invoice_number',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'subtotal' => 'required|numeric|min:0',
            'tax_percentage' => 'required|numeric|min:0|max:100',
            'tax_amount' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'payment_terms' => 'nullable|string|max:255',
            'delivery_terms' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,product_id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_description' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.unit' => 'required|string|max:50',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'create_tax_invoice' => 'boolean',
            'tax_invoice_number' => 'nullable|string|max:100',
            'tax_invoice_date' => 'nullable|date',
            'use_ppn' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            if ($request->po_id) {
                $po = PurchaseOrder::where('po_id', $request->po_id)
                    ->where('company_id', $companyId) // ✅ pakai $companyId dari session
                    ->first();

                if (!$po) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Purchase order tidak ditemukan atau tidak sesuai dengan perusahaan'
                    ], 404);
                }
            }

            if ($request->proforma_invoice_id) {
                $pi = ProformaInvoice::where('proforma_id', $request->proforma_invoice_id)
                    ->where('company_id', $companyId) // ✅ pakai $companyId dari session
                    ->first();

                if (!$pi) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Proforma invoice tidak ditemukan atau tidak sesuai dengan perusahaan'
                    ], 404);
                }
            }

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'customer_id' => $request->customer_id,
                'po_id' => $request->po_id,
                'proforma_invoice_id' => $request->proforma_invoice_id,
                'invoice_number' => $request->invoice_number,
                'invoice_date' => $request->invoice_date,
                'due_date' => $request->due_date,
                'subtotal' => $request->subtotal,
                'tax_percentage' => $request->tax_percentage,
                'tax_amount' => $request->tax_amount,
                'discount_amount' => $request->discount_amount ?? 0,
                'total_amount' => $request->total_amount,
                'currency' => $request->currency ?? 'IDR',
                'payment_status' => 'unpaid',
                'payment_terms' => $request->payment_terms,
                'delivery_terms' => $request->delivery_terms,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
                // ✅ FIX: Simpan use_ppn dari request, default true
                'use_ppn' => $request->has('use_ppn') ? $request->boolean('use_ppn') : true,
            ]);

            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->invoice_id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'product_description' => $item['product_description'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

          $taxInvoice = null;
if ($request->boolean('create_tax_invoice', true) && (bool) $invoice->use_ppn) {
    $taxInvoice = $this->autoCreateTaxInvoice($invoice, $request);
}
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => $invoice->load(['company', 'customer', 'items']),
                'tax_invoice' => $taxInvoice,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NEW: Auto-create Tax Invoice (Faktur Pajak)
     */
    private function autoCreateTaxInvoice(Invoice $invoice, Request $request)
    {
        try {
            $taxInvoiceNumber = $request->tax_invoice_number ?? $this->generateTaxInvoiceNumber($invoice);

            $exists = TaxInvoice::where('tax_invoice_number', $taxInvoiceNumber)->exists();
            if ($exists) {
                throw new \Exception("Tax invoice number {$taxInvoiceNumber} already exists");
            }

            $dppAmount = $invoice->total_amount - $invoice->tax_amount;

            $taxInvoice = TaxInvoice::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->invoice_id,
                'tax_invoice_number' => $taxInvoiceNumber,
                'tax_invoice_date' => $request->tax_invoice_date ?? $invoice->invoice_date,
                'tax_type' => 'ppn',
                'dpp_amount' => $dppAmount,
                'tax_rate' => $invoice->tax_percentage,
                'tax_amount' => $invoice->tax_amount,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            Log::info("✅ Auto-created Tax Invoice: {$taxInvoiceNumber} for Invoice: {$invoice->invoice_number}");

            return $taxInvoice;

        } catch (\Exception $e) {
            Log::error("❌ Failed to auto-create Tax Invoice: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ NEW: Generate Tax Invoice Number
     * Format: FP-{COMPANY_CODE}-{YYYYMM}-{XXXX}
     */
    private function generateTaxInvoiceNumber(Invoice $invoice): string
    {
        $company = $invoice->company;
        $companyCode = $company->company_code ?? 'XXX';
        $yearMonth = now()->format('Ym');

        $lastTax = TaxInvoice::where('company_id', $invoice->company_id)
            ->where('tax_invoice_number', 'LIKE', "FP-{$companyCode}-{$yearMonth}-%")
            ->orderBy('tax_invoice_id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastTax) {
            $lastNum = (int) substr($lastTax->tax_invoice_number, -4);
            $nextNumber = $lastNum + 1;
        }

        return sprintf('FP-%s-%s-%04d', $companyCode, $yearMonth, $nextNumber);
    }

    /**
     * Display the specified invoice
     */
    public function show(Request $request, $id)
    {
        try {
            $companyId = $this->getCompanyId($request);

            $invoice = Invoice::with([
                'company',
                'customer',
                'purchase_order',
                'proforma_invoice',
                'items.product',
                'payments',
                'delivery_notes',
                'created_by_user:user_id,full_name',

                // ✅ TAMBAH: Load semua tax invoices + file bukti
                'taxInvoices' => function ($q) {
                    $q->with(['createdBy:user_id,full_name', 'approvedBy:user_id,full_name'])
                        ->orderBy('tax_invoice_date', 'desc');
                },
            ])
                ->where('company_id', $companyId)
                ->find($id);

            if (!$invoice) {
                return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan'], 404);
            }

            // ✅ Compute tax breakdown (sudah via accessor, tapi pastikan payments di-load dulu)
            $data = $invoice->toArray();
            $data['tax_breakdown'] = $invoice->tax_breakdown;

            return response()->json(['success' => true, 'data' => $data], 200);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch invoice', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified invoice
     */
    public function update(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $invoice = Invoice::where('company_id', $companyId)->find($id); // ✅ guard

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found'
            ], 404);
        }

        if ($invoice->payments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat mengubah invoice yang sudah ada pembayaran'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'invoice_date' => 'sometimes|required|date',
            'due_date' => 'sometimes|required|date|after_or_equal:invoice_date',
            'total_amount' => 'sometimes|required|numeric|min:0',
            'dp_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $invoice->update($request->only([
                'invoice_date',
                'due_date',
                'total_amount',
                'dp_amount',
                'notes'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Invoice updated successfully',
                'data' => $invoice->load('items')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified invoice
     */
    public function destroy(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $invoice = Invoice::where('company_id', $companyId)->find($id); // ✅ guard

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found'
            ], 404);
        }

        if ($invoice->payments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat menghapus invoice yang sudah ada pembayaran'
            ], 409);
        }

        if ($invoice->deliveryNotes()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat menghapus invoice yang sudah ada surat jalan'
            ], 409);
        }

        try {
            DB::beginTransaction();

            $invoice->items()->delete();
            TaxInvoice::where('invoice_id', $invoice->invoice_id)->delete();
            $invoice->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Invoice deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get invoice summary/statistics
     */
    public function summary(Request $request)
    {
        $companyId = $this->getCompanyId($request); // ✅

        $query = Invoice::where('company_id', $companyId); // ✅ auto-filter

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('invoice_date', [$request->start_date, $request->end_date]);
        }

        $summary = [
            'total_invoices' => $query->count(),
            'total_amount' => $query->sum('total_amount'),
            'total_paid' => Payment::whereIn('invoice_id', $query->pluck('invoice_id'))->sum('amount_paid'),
            'unpaid' => (clone $query)->unpaid()->count(),
            'partial' => (clone $query)->partial()->count(),
            'paid' => (clone $query)->paid()->count(),
            'overdue' => (clone $query)->overdue()->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Invoice summary retrieved successfully',
            'data' => $summary
        ], 200);
    }

    public function createDeliveryNote(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        // ✅ Load semua relasi yang dibutuhkan termasuk po & quotation
        $invoice = Invoice::with([
            'purchaseOrder.customer',
            'purchaseOrder.quotation',  // ✅ untuk ambil quotation_id
            'items.product',
            'customer',
            'company',
            'payments',
        ])
            ->where('company_id', $companyId)
            ->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found'
            ], 404);
        }

        if ($invoice->payment_status === 'unpaid') {
            return response()->json([
                'success' => false,
                'message' => 'Invoice belum dibayar, tidak bisa membuat surat jalan'
            ], 422);
        }

        if ($invoice->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak memiliki item, tidak dapat membuat surat jalan'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'delivery_date' => 'required|date',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_address' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // ✅ Generate DN number
            $lastDN = DeliveryNote::where('company_id', $invoice->company_id)
                ->orderBy('delivery_note_id', 'desc')
                ->lockForUpdate()  // ✅ hindari race condition
                ->first();
            $nextNumber = $lastDN ? (int) substr($lastDN->delivery_note_number, -4) + 1 : 1;
            $dnNumber = 'SJ-' . $invoice->company->company_code
                . '-' . date('Ym')
                . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            // ✅ Resolve quotation_id dari PO jika ada
            $quotationId = $invoice->purchaseOrder?->quotation_id ?? null;

            // ✅ Fallback recipient_name
            $recipientName = $request->recipient_name
                ?? $invoice->customer?->customer_name
                ?? $invoice->purchaseOrder?->customer?->customer_name
                ?? 'N/A';

            // ✅ FIX: Tambah po_id dan quotation_id
            $deliveryNote = DeliveryNote::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->invoice_id,
                'po_id' => $invoice->po_id,       // ✅ dari invoice
                'quotation_id' => $quotationId,           // ✅ dari PO → quotation
                'delivery_note_number' => $dnNumber,
                'delivery_date' => $request->delivery_date,
                'recipient_name' => $recipientName,
                'recipient_address' => $request->recipient_address,
                'notes' => $request->notes,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            // ✅ Copy items dari invoice ke delivery note items
            foreach ($invoice->items as $invoiceItem) {
                DeliveryNoteItem::create([
                    'delivery_note_id' => $deliveryNote->delivery_note_id,
                    'invoice_item_id' => $invoiceItem->item_id,
                    'product_id' => $invoiceItem->product_id,
                    'product_code' => $invoiceItem->product?->product_code ?? null,
                    'product_name' => $invoiceItem->product_name,
                    'quantity' => $invoiceItem->quantity,
                    'unit' => $invoiceItem->unit,
                    'notes' => $invoiceItem->notes ?? null,
                ]);
            }

            DB::commit();

            // ✅ Load semua relasi yang dibutuhkan untuk response
            return response()->json([
                'success' => true,
                'message' => 'Delivery note created successfully',
                'data' => $deliveryNote->load([
                    'invoice.customer',
                    'purchaseOrder.customer',
                    'quotation',
                    'items.product',
                    'company',
                ]),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create delivery note',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Download invoice as PDF
     */
    public function downloadPdf(Request $request, $id)
    {
        try {
            $companyId = $this->getCompanyId($request);

            $invoice = Invoice::with([
                'company',
                'customer',
                'items.product',        // ✅ TAMBAH .product (untuk kode & brand di blade)
                'payments' => function ($q) {
                    $q->where('status', 'success');
                },
                'taxInvoices',          // ✅ TAMBAH (untuk taxDeductions di blade)
                'purchase_order',       // ✅ TAMBAH (untuk poNumber di blade)
            ])
                ->where('company_id', $companyId)
                ->find($id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice tidak ditemukan'
                ], 404);
            }

            $pdf = Pdf::loadView('pdf.invoice', [
                'invoice' => $invoice,
                'company' => $invoice->company,  // ✅ TAMBAH pass $company ke view
            ]);

            // ✅ TAMBAH sanitize filename (hindari error karakter / atau \)
            $filename = 'Invoice-' . str_replace(['/', '\\'], '_', $invoice->invoice_number) . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal generate PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }



}
