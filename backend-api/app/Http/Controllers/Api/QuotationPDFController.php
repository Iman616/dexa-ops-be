<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\DocumentTerm;
use App\Models\Tax;
use Barryvdh\DomPDF\Facade\Pdf;

class QuotationPDFController extends Controller
{
    /* ── STREAM ── */
    public function generate($id)
    {
        [$quotation, $data] = $this->buildPdfData($id);

        if (!$quotation) {
            return response()->json(['success' => false, 'message' => 'Quotation not found'], 404);
        }

        if ($quotation->status === 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot generate PDF for draft quotation. Please issue the quotation first.',
            ], 422);
        }

        $pdf = Pdf::loadView('pdf.quotation', $data)->setPaper('A4', 'portrait');

        return $pdf->stream('Quotation-' . $this->sanitizeFilename($quotation->quotation_number) . '.pdf');
    }

    /* ── DOWNLOAD ── */
    public function download($id)
    {
        [$quotation, $data] = $this->buildPdfData($id);

        if (!$quotation) {
            return response()->json(['success' => false, 'message' => 'Quotation not found'], 404);
        }

        if ($quotation->status === 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot generate PDF for draft quotation. Please issue the quotation first.',
            ], 422);
        }

        $pdf = Pdf::loadView('pdf.quotation', $data)->setPaper('A4', 'portrait');

        return $pdf->download('Quotation-' . $this->sanitizeFilename($quotation->quotation_number) . '.pdf');
    }

    /* ── SHARED DATA BUILDER ── */
    private function buildPdfData($id): array
    {
        $quotation = Quotation::with([
            'company',
            'customer',
            'items.product',
            'createdByUser',
            'issuedByUser',
        ])->find($id);

        if (!$quotation) {
            return [null, []];
        }

        // ✅ Tax rate dari DB
        $taxRate = Tax::where('is_active', true)
            ->where('tax_name', 'LIKE', '%PPN%')
            ->orderByDesc('created_at')
            ->value('tax_rate') ?? 11.0;

        // ✅ Hitung total
        $subtotal = (float) $quotation->total_amount;
        $dpp      = round($subtotal * ($taxRate / ($taxRate + 1)));
        $ppn      = round($dpp * ($taxRate / 100));
        $total    = $subtotal + $ppn;

        // ✅ Ambil terms dinamis dari DB, replace placeholder
        $terms = DocumentTerm::where('company_id', $quotation->company_id)
            ->where('document_type', 'quotation')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn($term) => str_replace(
                ['{tax_rate}', '{tax_rate_plus_one}', '{company_name}', '{quotation_number}'],
                [
                    round($taxRate),
                    round($taxRate + 1),
                    $quotation->company->company_name ?? '',
                    $quotation->quotation_number,
                ],
                $term->term_content
            ));

        // ✅ Fallback jika DB kosong — pakai hardcoded default
        if ($terms->isEmpty()) {
            $terms = collect([
                'Dengan terbitnya Surat Pesanan atau Surat Perintah Kerja, kami anggap telah mengerti dan menyetujui segala informasi produk yang tercantum dalam Quotation.',
                'Item ready stock tidak mengikat.',
                'Kondisi lamanya waktu indent dapat berubah-ubah sesuai dengan kondisi dari prinsipal dan kendala lainnya.',
                'Berdasarkan PMK No. 131 PPN ' . round($taxRate) . '% x ' . round($taxRate) . '/' . round($taxRate + 1) . ' x Harga Jual',
            ]);
        }

        return [
            $quotation,
            [
                'quotation' => $quotation,
                'subtotal'  => $subtotal,
                'dpp'       => $dpp,
                'ppn'       => $ppn,
                'total'     => $total,
                'taxRate'   => $taxRate,
                'terms'     => $terms,  // ✅ inject ke blade
            ],
        ];
    }

    /* ── SANITIZE FILENAME ── */
    private function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $filename);
        $filename = preg_replace('/\s+/', '_', $filename);
        $filename = preg_replace('/[^A-Za-z0-9\-_]/', '', $filename);

        return $filename;
    }
}
