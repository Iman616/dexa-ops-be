<?php

namespace App\Http\Controllers\Api;

use App\Models\DocumentTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DocumentTermController extends BaseController
{
    /* ── INDEX ── */
    public function index(Request $request)
    {
        $companyId = $this->getCompanyId($request);

        $query = DocumentTerm::where('company_id', $companyId);

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $terms = $query->orderBy('document_type')->orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'message' => 'Document terms retrieved successfully',
            'data'    => $terms,
        ]);
    }

    /* ── STORE ── */
    public function store(Request $request)
    {
        $companyId = $this->getCompanyId($request);

        $validator = Validator::make($request->all(), [
            'document_type' => 'required|in:quotation,invoice,purchase_order,proforma_invoice',
            'term_content'  => 'required|string',
            'sort_order'    => 'nullable|integer|min:0',
            'is_active'     => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Auto sort_order jika tidak diisi
        $maxSort = DocumentTerm::where('company_id', $companyId)
            ->where('document_type', $request->document_type)
            ->max('sort_order') ?? 0;

        $term = DocumentTerm::create([
            'company_id'    => $companyId,
            'document_type' => $request->document_type,
            'term_content'  => $request->term_content,
            'sort_order'    => $request->sort_order ?? ($maxSort + 1),
            'is_active'     => $request->is_active ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Term berhasil ditambahkan',
            'data'    => $term,
        ], 201);
    }

    /* ── SHOW ── */
    public function show(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $term = DocumentTerm::where('company_id', $companyId)->find($id);

        if (!$term) {
            return response()->json(['success' => false, 'message' => 'Term tidak ditemukan'], 404);
        }

        return response()->json(['success' => true, 'data' => $term]);
    }

    /* ── UPDATE ── */
    public function update(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $term = DocumentTerm::where('company_id', $companyId)->find($id);

        if (!$term) {
            return response()->json(['success' => false, 'message' => 'Term tidak ditemukan'], 404);
        }

        $validator = Validator::make($request->all(), [
            'document_type' => 'sometimes|in:quotation,invoice,purchase_order,proforma_invoice',
            'term_content'  => 'sometimes|string',
            'sort_order'    => 'nullable|integer|min:0',
            'is_active'     => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $term->update($request->only([
            'document_type', 'term_content', 'sort_order', 'is_active',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Term berhasil diperbarui',
            'data'    => $term->fresh(),
        ]);
    }

    /* ── DESTROY ── */
    public function destroy(Request $request, $id)
    {
        $companyId = $this->getCompanyId($request);

        $term = DocumentTerm::where('company_id', $companyId)->find($id);

        if (!$term) {
            return response()->json(['success' => false, 'message' => 'Term tidak ditemukan'], 404);
        }

        $term->delete();

        return response()->json(['success' => true, 'message' => 'Term berhasil dihapus']);
    }

    /* ── REORDER ── */
    public function reorder(Request $request)
    {
        $companyId = $this->getCompanyId($request);

        $validator = Validator::make($request->all(), [
            'items'           => 'required|array|min:1',
            'items.*.term_id' => 'required|integer',
            'items.*.sort_order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        foreach ($request->items as $item) {
            DocumentTerm::where('company_id', $companyId)
                ->where('term_id', $item['term_id'])
                ->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['success' => true, 'message' => 'Urutan berhasil disimpan']);
    }

    /* ── BULK SEED DEFAULT ── */
    public function seedDefaults(Request $request)
    {
        $companyId = $this->getCompanyId($request);

        $validator = Validator::make($request->all(), [
            'document_type' => 'required|in:quotation,invoice,purchase_order,proforma_invoice',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $defaults = $this->getDefaults($request->document_type);

        // Hapus existing dulu
        DocumentTerm::where('company_id', $companyId)
            ->where('document_type', $request->document_type)
            ->delete();

        foreach ($defaults as $item) {
            DocumentTerm::create([
                'company_id'    => $companyId,
                'document_type' => $request->document_type,
                'term_content'  => $item['content'],
                'sort_order'    => $item['order'],
                'is_active'     => true,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Default terms berhasil dimuat',
            'data'    => DocumentTerm::where('company_id', $companyId)
                            ->where('document_type', $request->document_type)
                            ->orderBy('sort_order')
                            ->get(),
        ]);
    }

    private function getDefaults(string $type): array
    {
        $defaults = [
            'quotation' => [
                ['order' => 1, 'content' => 'Dengan terbitnya Surat Pesanan atau Surat Perintah Kerja, kami anggap telah mengerti dan menyetujui segala informasi produk yang tercantum dalam Quotation.'],
                ['order' => 2, 'content' => 'Item ready stock tidak mengikat.'],
                ['order' => 3, 'content' => 'Kondisi lamanya waktu indent dapat berubah-ubah sesuai dengan kondisi dari prinsipal dan kendala lainnya.'],
                ['order' => 4, 'content' => 'Berdasarkan PMK No. 131 PPN {tax_rate}% x {tax_rate}/{tax_rate_plus_one} x Harga Jual'],
            ],
            'invoice' => [
                ['order' => 1, 'content' => 'Pembayaran dilakukan paling lambat sesuai dengan tanggal jatuh tempo yang tertera.'],
                ['order' => 2, 'content' => 'Keterlambatan pembayaran dapat dikenakan denda sesuai kesepakatan.'],
            ],
            'purchase_order' => [
                ['order' => 1, 'content' => 'Barang yang telah dipesan tidak dapat dibatalkan tanpa persetujuan tertulis.'],
                ['order' => 2, 'content' => 'Pengiriman dilakukan sesuai dengan jadwal yang telah disepakati.'],
            ],
            'proforma_invoice' => [
                ['order' => 1, 'content' => 'Proforma invoice ini bukan merupakan tagihan resmi.'],
                ['order' => 2, 'content' => 'Harga berlaku selama 14 hari sejak tanggal penerbitan.'],
            ],
        ];

        return $defaults[$type] ?? [];
    }
}
