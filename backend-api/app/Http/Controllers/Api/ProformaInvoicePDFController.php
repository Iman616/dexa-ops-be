<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;

class ProformaInvoicePDFController extends Controller
{
    private function safeFilename(string $value): string
    {
        return preg_replace('/[\/\\\\:*?"<>| ]+/', '_', $value);
    }

    /* =========================
     * ✅ SHARED: Build semua data yang dibutuhkan PDF
     * ========================= */
    private function buildProformaData(int $id): array
    {
        $proforma = DB::table('proforma_invoices as pi')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'pi.customer_id')
            ->leftJoin('users as creator', 'creator.user_id', '=', 'pi.created_by')
            ->leftJoin('purchase_orders as po', 'po.po_id', '=', 'pi.po_id')
            ->leftJoin('activity_types as at', 'at.activity_type_id', '=', 'po.activity_type_id')
            ->select(
                'pi.proforma_id',
                'pi.company_id',
                'pi.proforma_number',
                'pi.proforma_date',
                'pi.valid_until',
                'pi.subtotal',
                'pi.tax_percentage',
                'pi.tax_amount',
                'pi.discount_amount',
                'pi.total_amount',
                'pi.currency',
                'pi.notes',
                'pi.payment_terms',
                'pi.delivery_terms',
                'pi.signed_name',
                'pi.signed_position',
                'pi.signed_city',
                'pi.signed_at',
                'pi.signature_image',
                'c.customer_name',
                'c.address as customer_address',
                'c.phone as customer_phone',
                'c.email as customer_email',
                'creator.full_name as creator_name',
                'creator.username as creator_username',
                'po.po_number',
                'po.po_id',
                'at.type_name as project_name',
                'at.type_code as activity_type_code',
            )
            ->where('pi.proforma_id', $id)
            ->first();

        if (!$proforma) {
            return ['error' => 'Proforma invoice not found'];
        }

        // Default values
        $proforma->payment_terms = $proforma->payment_terms ?? 'Net 30 days';
        $proforma->delivery_terms = $proforma->delivery_terms ?? 'FOB Destination';
        $proforma->currency = $proforma->currency ?? 'IDR';

        // Signature → base64
        $proforma->signature_image_base64 = null;
        if ($proforma->signature_image) {
            $path = storage_path('app/public/' . $proforma->signature_image);
            if (file_exists($path)) {
                $mime = mime_content_type($path);
                $raw = base64_encode(file_get_contents($path));
                $proforma->signature_image_base64 = "data:{$mime};base64,{$raw}";
            }
        }

        // Company
        $company = DB::table('companies')
            ->where('company_id', $proforma->company_id)
            ->select([
                'company_name',
                'address',
                'phone',
                'email',
                'website',
                'city',
                'pic_name',
                'logo_path',
                'bank_name',
                'bank_account',
            ])
            ->first();

        if ($company) {
            $company->bank_name = $company->bank_name ?? 'Bank Mandiri';
            $company->bank_account = $company->bank_account ?? '-';

            // Logo → base64
            $company->logo_base64 = null;
            if ($company->logo_path) {
                $logoPath = storage_path('app/public/' . $company->logo_path);
                if (file_exists($logoPath)) {
                    $mime = mime_content_type($logoPath);
                    $raw = base64_encode(file_get_contents($logoPath));
                    $company->logo_base64 = "data:{$mime};base64,{$raw}";
                }
            }
        }

        // Items
   // Di buildProformaData(), bagian query items — TAMBAH pii.discount_percent
$items = DB::table('proforma_invoice_items as pii')
    ->leftJoin('products as p', 'p.product_id', '=', 'pii.product_id')
    ->select(
        'pii.item_id',
        'pii.product_name',
        'pii.product_description',
        'pii.quantity',
        'pii.unit',
        'pii.unit_price',
        'pii.subtotal',
        'pii.discount_percent', // ✅ TAMBAH INI
        'p.product_code',
        'p.brand'
    )
    ->where('pii.proforma_id', $id)
    ->orderBy('pii.item_id')
    ->get();

// Setelah query items, tambah flag apakah ada diskon per item
$hasItemDiscount = $items->contains(fn($i) => (float)($i->discount_percent ?? 0) > 0); // ✅ TAMBAH

// Kalkulasi (yang sudah ada)
$subtotal     = (float) $proforma->subtotal;
$dpp_lainnya  = $subtotal * (11 / 12);
$ppn          = (float) $proforma->tax_amount;
$total        = (float) $proforma->total_amount;
$terbilang    = $this->formatTerbilang($total);

$terms = DB::table('document_terms')
    ->where('company_id', $proforma->company_id)
    ->where('document_type', 'proforma_invoice')
    ->where('is_active', true)
    ->orderBy('sort_order')
    ->get();

return compact(
    'proforma',
    'items',
    'company',
    'subtotal',
    'dpp_lainnya',
    'ppn',
    'total',
    'terbilang',
    'terms',
    'hasItemDiscount' // ✅ TAMBAH
);
    }

    /* =========================
     * TERBILANG CORE (rekursif)
     * ========================= */
    private function terbilang(int $angka): string
    {
        if ($angka === 0)
            return '';

        $satuan = [
            '',
            'Satu',
            'Dua',
            'Tiga',
            'Empat',
            'Lima',
            'Enam',
            'Tujuh',
            'Delapan',
            'Sembilan',
            'Sepuluh',
            'Sebelas'
        ];

        if ($angka < 12)
            return $satuan[$angka];
        if ($angka < 20)
            return $satuan[$angka - 10] . ' Belas';
        if ($angka < 100)
            return $satuan[(int) ($angka / 10)] . ' Puluh ' . $this->terbilang($angka % 10);
        if ($angka < 200)
            return 'Seratus ' . $this->terbilang($angka - 100);
        if ($angka < 1000)
            return $satuan[(int) ($angka / 100)] . ' Ratus ' . $this->terbilang($angka % 100);
        if ($angka < 2000)
            return 'Seribu ' . $this->terbilang($angka - 1000);
        if ($angka < 1_000_000)
            return $this->terbilang((int) ($angka / 1000)) . ' Ribu ' . $this->terbilang($angka % 1000);
        if ($angka < 1_000_000_000)
            return $this->terbilang((int) ($angka / 1_000_000)) . ' Juta ' . $this->terbilang($angka % 1_000_000);
        if ($angka < 1_000_000_000_000)
            return $this->terbilang((int) ($angka / 1_000_000_000)) . ' Miliar ' . $this->terbilang($angka % 1_000_000_000);

        return $this->terbilang((int) ($angka / 1_000_000_000_000)) . ' Triliun ' . $this->terbilang($angka % 1_000_000_000_000);
    }

    /* =========================
     * FORMAT TERBILANG FINAL
     * Support desimal → Sen
     * ========================= */
    private function formatTerbilang(float $amount): string
    {
        $rounded = round($amount, 2);
        $rupiah = (int) floor($rounded);
        $sen = (int) round(($rounded - $rupiah) * 100);

        $result = trim($this->terbilang($rupiah));
        $result = preg_replace('/\s+/', ' ', $result);
        $result = trim($result) . ' Rupiah';

        if ($sen > 0) {
            $senStr = trim($this->terbilang($sen));
            $senStr = preg_replace('/\s+/', ' ', $senStr);
            $result .= ' ' . trim($senStr) . ' Sen';
        }

        return $result;
    }

    public function generate($id)
    {
        try {
            $data = $this->buildProformaData((int) $id);

            if (isset($data['error'])) {
                return response()->json(['success' => false, 'message' => $data['error']], 404);
            }

            $filename = $this->safeFilename($data['proforma']->proforma_number);

            $pdf = Pdf::loadView('pdf.proforma-invoice', $data)
                ->setPaper('A4', 'portrait')
                ->setOption('isHtml5ParserEnabled', false)
                ->setOption('isRemoteEnabled', false)
                ->setOption('enable_php', false)
                ->setOption('dpi', 96)
                ->setOption('defaultFont', 'Arial');

            return $pdf->stream("Proforma_Invoice_{$filename}.pdf");

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating PDF',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function download($id)
    {
        try {
            $data = $this->buildProformaData((int) $id);

            if (isset($data['error'])) {
                return response()->json(['success' => false, 'message' => $data['error']], 404);
            }

            $filename = $this->safeFilename($data['proforma']->proforma_number);

            $pdf = Pdf::loadView('pdf.proforma-invoice', $data)
                ->setPaper('A4', 'portrait')
                ->setOption('isHtml5ParserEnabled', false)
                ->setOption('isRemoteEnabled', false)
                ->setOption('enable_php', false)
                ->setOption('dpi', 96)
                ->setOption('defaultFont', 'Arial');

            return $pdf->download("Proforma_Invoice_{$filename}.pdf");

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error downloading PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
