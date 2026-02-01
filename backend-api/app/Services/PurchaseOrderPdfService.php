<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseOrderPdfService
{
    protected $disk;

    public function __construct()
    {
        // Force pakai disk local supaya konsisten
        $this->disk = Storage::disk('local');
    }

    /**
     * Generate PDF temporary (preview / download)
     */
    public function generatePurchaseOrderPdf(PurchaseOrder $po)
    {
        $data = $this->preparePdfData($po);

        $pdf = Pdf::loadView('pdf.purchase_order', $data)
                    ->setPaper('a4', 'portrait');

        // sanitize nomor PO (hindari slash path bug)
        $safePoNumber = preg_replace('/[\/\\\\]/', '-', $po->po_number);

        $this->disk->makeDirectory('temp');

        $filename = 'PO_' . $safePoNumber . '_' . time() . '.pdf';
        $path = 'temp/' . $filename;

        $this->disk->put($path, $pdf->output());

        if (!$this->disk->exists($path)) {
            throw new \Exception("PDF gagal dibuat: {$path}");
        }

        return $path;
    }

    /**
     * Generate PDF permanen dan simpan ke storage
     */
    public function generateAndSavePurchaseOrderPdf(PurchaseOrder $po)
    {
        $data = $this->preparePdfData($po);

        $pdf = Pdf::loadView('pdf.purchase_order', $data)
                    ->setPaper('a4', 'portrait');

        if ($po->has_po_file) {
            $this->disk->delete($po->po_file_path);
        }

        $safePoNumber = preg_replace('/[\/\\\\]/', '-', $po->po_number);

        $this->disk->makeDirectory('public/purchase_orders');

        $filename = 'PO_' . $safePoNumber . '_' . time() . '.pdf';
        $path = 'public/purchase_orders/' . $filename;

        $this->disk->put($path, $pdf->output());

        if (!$this->disk->exists($path)) {
            throw new \Exception("PDF gagal dibuat: {$path}");
        }

        $po->update(['po_file_path' => $path]);

        return $path;
    }

    /**
     * Prepare data PDF (pakai accessor model biar rapi & cepat)
     */
    private function preparePdfData(PurchaseOrder $po)
    {
        $po->load(['company', 'customer', 'items']);

        return [
            'po' => $po,
            'company' => $po->company,
            'customer' => $po->customer,
            'items' => $po->items,
            'subtotal' => $po->subtotal,
            'ppn' => $po->tax_amount,
            'ppn_percent' => 11,
            'grand_total' => $po->grand_total,
        ];
    }

    /**
     * Helper format rupiah
     */
    public static function formatCurrency($number)
    {
        return 'Rp ' . number_format($number, 0, ',', '.');
    }

    /**
     * Helper format tanggal Indonesia
     */
    public static function formatDate($date)
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        $timestamp = strtotime($date);

        return date('d', $timestamp) . ' ' .
               $months[(int) date('m', $timestamp)] . ' ' .
               date('Y', $timestamp);
    }
}
