<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;

class QuotationPDFController extends Controller
{
    /**
     * Generate PDF Quotation
     */
public function generate($id)
{
    $quotation = Quotation::with([
        'company', 
        'customer', 
        'items.product', // ✅ Pastikan ini ada
        'createdByUser',
        'issuedByUser'
    ])->find($id);

    if (!$quotation) {
        return response()->json([
            'success' => false,
            'message' => 'Quotation not found'
        ], 404);
    }

    // Validasi: tidak bisa generate PDF jika masih draft
    if ($quotation->status === 'draft') {
        return response()->json([
            'success' => false,
            'message' => 'Cannot generate PDF for draft quotation. Please issue the quotation first.'
        ], 422);
    }

    // Calculate totals
    $subtotal = $quotation->total_amount;
    $dpp = $subtotal / 1.12;
    $ppn = $dpp * 0.12;
    $total = $dpp + $ppn;

    $data = [
        'quotation' => $quotation,
        'subtotal' => $subtotal,
        'dpp' => $dpp,
        'ppn' => $ppn,
        'total' => $total,
    ];

    $pdf = Pdf::loadView('pdf.quotation', $data);
    $pdf->setPaper('A4', 'portrait');
    
    $filename = $this->sanitizeFilename($quotation->quotation_number);
    
    return $pdf->stream('Quotation-' . $filename . '.pdf');
}


    /**
     * Download PDF
     */
    public function download($id)
    {
        $quotation = Quotation::with([
            'company', 
            'customer', 
            'items.product', 
            'createdByUser',
            'issuedByUser'
        ])->find($id);

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'Quotation not found'
            ], 404);
        }

        // Validasi: tidak bisa generate PDF jika masih draft
        if ($quotation->status === 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot generate PDF for draft quotation. Please issue the quotation first.'
            ], 422);
        }

        // Calculate totals
        $subtotal = $quotation->total_amount;
        $dpp = $subtotal / 1.12; // DPP = Total / 1.12
        $ppn = $dpp * 0.12;       // PPN 12% dari DPP
        $total = $dpp + $ppn;     // Total = DPP + PPN

        $data = [
            'quotation' => $quotation,
            'subtotal' => $subtotal,
            'dpp' => $dpp,
            'ppn' => $ppn,
            'total' => $total,
        ];

        $pdf = Pdf::loadView('pdf.quotation', $data);
        $pdf->setPaper('A4', 'portrait');
        
        // Sanitize filename untuk menghindari error karakter / atau \
        $filename = $this->sanitizeFilename($quotation->quotation_number);
        
        return $pdf->download('Quotation-' . $filename . '.pdf');
    }

    /**
     * Sanitize filename untuk menghapus karakter yang tidak diizinkan
     * Karakter yang dihapus: / \ : * ? " < > |
     */
    private function sanitizeFilename($filename)
    {
        // Replace karakter yang tidak diizinkan dengan dash
        $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $filename);
        
        // Ganti spasi dengan underscore
        $filename = preg_replace('/\s+/', '_', $filename);
        
        // Hapus karakter special lainnya, hanya izinkan: huruf, angka, dash, underscore
        $filename = preg_replace('/[^A-Za-z0-9\-_]/', '', $filename);
        
        return $filename;
    }
}
