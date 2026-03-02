<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierProformaInvoice;
use App\Models\SupplierProformaInvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SupplierProformaInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = SupplierProformaInvoice::with([
            'company:company_id,company_name,company_code',
            'supplier:supplier_id,supplier_name',
            'supplierPo:supplier_po_id,po_number',
            'items.product',
            'createdBy:user_id,full_name'
        ])->byCompany($request->company_id);

        if ($request->status) $query->where('status', $request->status);
        if ($request->search) {
            $query->where('supplier_proforma_number', 'like', "%{$request->search}%")
                  ->orWhereHas('supplier', fn($q) => $q->where('supplier_name', 'like', "%{$request->search}%"));
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate($request->per_page ?? 15)
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,supplier_id',
            'supplier_po_id' => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'supplier_proforma_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $subtotal = collect($request->items)->sum(fn($i) => $i['quantity'] * $i['unit_price']);
            $taxAmount = $subtotal * 0.11; // 11% PPN
            $totalAmount = $subtotal + $taxAmount;

            $spiNumber = $this->generateNumber($request->company_id);

            $spi = SupplierProformaInvoice::create([
                'company_id' => $request->company_id,
                'supplier_id' => $request->supplier_id,
                'supplier_po_id' => $request->supplier_po_id,
                'supplier_proforma_number' => $spiNumber,
                'supplier_proforma_date' => $request->supplier_proforma_date,
                'valid_until' => now()->addDays(30),
                'subtotal' => $subtotal,
                'tax_percentage' => 11,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            foreach ($request->items as $item) {
                $product = DB::table('products')->where('product_id', $item['product_id'])->first();
                $spi->items()->create([
                    'product_id' => $item['product_id'],
                    'product_name' => $product->product_name,
                    'quantity' => $item['quantity'],
                    'unit' => $product->unit,
                    'unit_price' => $item['unit_price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Supplier Proforma Invoice dibuat',
                'data' => $spi->load(['supplier', 'items.product'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function issue(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'signed_name' => 'required|string|max:100',
            'signed_position' => 'required|string|max:100',
            'signed_city' => 'required|string|max:50',
            'signature_image' => 'nullable|string', // base64
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $spi = SupplierProformaInvoice::findOrFail($id);
        if ($spi->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Hanya draft yang bisa di-issue'], 422);
        }

        // Handle signature base64
        $signaturePath = null;
        if ($request->signature_image) {
            $base64 = explode(',', $request->signature_image)[1] ?? $request->signature_image;
            $decoded = base64_decode($base64);
            $filename = 'signatures/spi_' . $id . '_' . time() . '.png';
            Storage::disk('public')->put($filename, $decoded);
            $signaturePath = $filename;
        }

        $spi->markAsIssued([
            'signed_name' => $request->signed_name,
            'signed_position' => $request->signed_position,
            'signed_city' => $request->signed_city,
            'signature_image' => $signaturePath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Supplier PI berhasil di-issue'
        ]);
    }

    private function generateNumber($companyId): string
    {
        $company = DB::table('companies')->where('company_id', $companyId)->first();
        $code = $company->company_code ?? 'UNK';
        $last = SupplierProformaInvoice::where('company_id', $companyId)
            ->whereYear('supplier_proforma_date', now()->year)
            ->latest('supplier_proforma_id')->first();

        $num = $last ? (int)substr($last->supplier_proforma_number, -4) + 1 : 1;
        return "SPI/{$code}/" . now()->format('Ym') . '/' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
