<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\ProformaInvoice;

use App\Models\Payment;
use App\Models\DeliveryNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class InvoiceController extends Controller
{
    /**
     * Display a listing of invoices
     */
public function index(Request $request)
{
    try {
        $query = Invoice::with([
            'company:company_id,company_name,company_code',
            'customer:customer_id,customer_name,address,phone,email',
            'purchase_order:po_id,po_number',
            'proforma_invoice:proforma_id,proforma_number',
            'items',
            'payments' => function($q) {
                $q->where('status', 'success'); // Only success payments
            },
            'delivery_notes:delivery_note_id,invoice_id,delivery_note_number,delivery_date',
            'created_by_user:user_id,full_name'
        ]);

        // Filters
        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('invoice_number', 'LIKE', "%{$request->search}%")
                  ->orWhereHas('customer', function($q2) use ($request) {
                      $q2->where('customer_name', 'LIKE', "%{$request->search}%");
                  });
            });
        }

        if ($request->company_id) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->payment_status) {
            $statuses = explode(',', $request->payment_status);
            $query->whereIn('payment_status', $statuses);
        }

        if ($request->overdue_only === 'true' || $request->overdue_only === true) {
            $query->where('due_date', '<', now())
                  ->whereNotIn('payment_status', ['paid', 'completed']);
        }

        if ($request->start_date && $request->end_date) {
            $query->whereBetween('invoice_date', [$request->start_date, $request->end_date]);
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'invoice_date';
        $sortOrder = $request->sort_order ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
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
 * ✅ NEW: Create invoice from Proforma Invoice
 * Dipanggil otomatis saat PI di-convert
 */
public function createFromProformaInvoice(Request $request)
{
    $validator = Validator::make($request->all(), [
        'proforma_invoice_id' => 'required|exists:proforma_invoices,proforma_id',
        'invoice_number' => 'required|string|max:100|unique:invoices,invoice_number',
        'invoice_date' => 'required|date',
        'due_date' => 'required|date|after_or_equal:invoice_date',
        'payment_terms' => 'nullable|string|max:255',
        'delivery_terms' => 'nullable|string|max:255',
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

        // Get PI with items
        $pi = \App\Models\ProformaInvoice::with('items')->findOrFail($request->proforma_invoice_id);

        // Check if already converted
        if ($pi->converted_to_invoice_id) {
            return response()->json([
                'success' => false,
                'message' => 'Proforma Invoice sudah dikonversi ke Invoice'
            ], 422);
        }

        // Create invoice
        $invoice = Invoice::create([
            'company_id' => $pi->company_id,
            'customer_id' => $pi->customer_id,
            'po_id' => $pi->po_id,
            'proforma_invoice_id' => $pi->proforma_id,
            'invoice_number' => $request->invoice_number,
            'invoice_date' => $request->invoice_date,
            'due_date' => $request->due_date,
            'subtotal' => $pi->subtotal,
            'tax_percentage' => $pi->tax_percentage,
            'tax_amount' => $pi->tax_amount,
            'discount_amount' => $pi->discount_amount,
            'total_amount' => $pi->total_amount,
            'currency' => $pi->currency ?? 'IDR',
            'payment_status' => 'unpaid',
            'payment_terms' => $request->payment_terms ?? $pi->payment_terms,
            'delivery_terms' => $request->delivery_terms ?? $pi->delivery_terms,
            'notes' => $pi->notes,
            'created_by' => Auth::id(),
        ]);

        // Copy items from PI to Invoice
        foreach ($pi->items as $piItem) {
            InvoiceItem::create([
                'invoice_id' => $invoice->invoice_id,
                'product_id' => $piItem->product_id,
                'product_name' => $piItem->product_name,
                'quantity' => $piItem->quantity,
                'unit' => $piItem->unit,
                'unit_price' => $piItem->unit_price,
                'notes' => $piItem->notes,
            ]);
        }

        // Update PI status
        $pi->update([
            'status' => 'converted',
            'converted_to_invoice_id' => $invoice->invoice_id,
            'converted_at' => now(),
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Invoice created successfully from Proforma Invoice',
            'data' => $invoice->load(['items', 'customer', 'proformaInvoice'])
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
     * Store a newly created invoice
     */
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|exists:companies,company_id',
        'customer_id' => 'required|exists:customers,customer_id',
        
        // ✅ FIX: PO ID opsional dan nullable
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
        // ✅ FIX: Check PO belongs to company (hanya jika po_id diisi)
        if ($request->po_id) {
            $po = PurchaseOrder::where('po_id', $request->po_id)
                ->where('company_id', $request->company_id)
                ->first();
                
            if (!$po) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase order tidak ditemukan atau tidak sesuai dengan perusahaan'
                ], 404);
            }
        }

        // ✅ Check Proforma Invoice (hanya jika diisi)
        if ($request->proforma_invoice_id) {
            $pi = ProformaInvoice::where('proforma_id', $request->proforma_invoice_id)
                ->where('company_id', $request->company_id)
                ->first();
                
            if (!$pi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proforma invoice tidak ditemukan atau tidak sesuai dengan perusahaan'
                ], 404);
            }
        }

        // Create invoice
        $invoice = Invoice::create([
            'company_id' => $request->company_id,
            'customer_id' => $request->customer_id,
            'po_id' => $request->po_id, // ✅ Bisa NULL
            'proforma_invoice_id' => $request->proforma_invoice_id, // ✅ Bisa NULL
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
        ]);

        // Create invoice items
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

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Invoice created successfully',
            'data' => $invoice->load(['company', 'customer', 'items'])
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
     * Display the specified invoice
     */
public function show($id)
{
    try {
        $invoice = Invoice::with([
            'company',
            'customer',
            'purchase_order',
            'proforma_invoice',
            'items.product',
            'payments' => function($q) {
                $q->where('status', 'success')
                  ->with('created_by_user:user_id,full_name');
            },
            'delivery_notes',
            'created_by_user:user_id,full_name'
        ])->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $invoice
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch invoice',
            'error' => $e->getMessage()
        ], 500);
    }
}


    /**
     * Update the specified invoice
     */
    public function update(Request $request, $id)
    {
        $invoice = Invoice::find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found'
            ], 404);
        }

        // Prevent update if ada payment
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
    public function destroy($id)
    {
        $invoice = Invoice::find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found'
            ], 404);
        }

        // Check dependencies
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

            // Delete items first
            $invoice->items()->delete();
            
            // Delete invoice
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
        $query = Invoice::query();

        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
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

    /**
     * ✅ NEW: Create delivery note from invoice
     */
    public function createDeliveryNote(Request $request, $id)
    {
        $invoice = Invoice::with('purchaseOrder.customer')->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found'
            ], 404);
        }

        // Check if invoice is paid
        if ($invoice->payment_status === 'unpaid') {
            return response()->json([
                'success' => false,
                'message' => 'Invoice belum dibayar, tidak bisa membuat surat jalan'
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

            // Generate delivery note number
            $lastDN = DeliveryNote::where('company_id', $invoice->company_id)
                                  ->orderBy('delivery_note_id', 'desc')
                                  ->first();
            
            $nextNumber = $lastDN ? (int)substr($lastDN->delivery_note_number, -4) + 1 : 1;
            $dnNumber = 'SJ-' . $invoice->company->company_code . '-' . date('Ym') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            $deliveryNote = DeliveryNote::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->invoice_id,
                'delivery_note_number' => $dnNumber,
                'delivery_date' => $request->delivery_date,
                'recipient_name' => $request->recipient_name ?? $invoice->purchaseOrder->customer->customer_name,
                'recipient_address' => $request->recipient_address,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Delivery note created successfully',
                'data' => $deliveryNote->load('invoice')
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
public function downloadPdf($id)
{
    try {
        $invoice = Invoice::with([
            'company',
            'customer',
            'items',
            'payments' => function($q) {
                $q->where('status', 'success');
            }
        ])->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan'
            ], 404);
        }

        // Load PDF view
        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice
        ]);

        // Filename
        $filename = 'Invoice-' . $invoice->invoice_number . '.pdf';

        // Download
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