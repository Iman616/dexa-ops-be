<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;

class SupplierDeliveryNotePDFController extends Controller
{
    /* =========================
     * SAFE FILENAME
     * ========================= */
    private function safeFilename(string $value): string
    {
        return preg_replace('/[\/\\\\:*?"<>| ]+/', '_', $value);
    }

    /* =========================
     * BUILD DATA (shared)
     * ========================= */
    private function buildDeliveryNoteData(int $id): array
    {
        $deliveryNote = DB::table('supplier_delivery_notes as sdn')
            ->leftJoin('suppliers as s', 's.supplier_id', '=', 'sdn.supplier_id')
            ->leftJoin('supplier_purchase_orders as spo', 'spo.supplier_po_id', '=', 'sdn.supplier_po_id')
            ->leftJoin('activity_types as at', 'at.activity_type_id', '=', 'spo.activity_type_id')
            ->leftJoin('users as creator', 'creator.user_id', '=', 'sdn.created_by')
            ->select(
                // DN fields
                'sdn.supplier_delivery_note_id',
                'sdn.company_id',
                'sdn.delivery_note_number',
                'sdn.delivery_note_date',
                'sdn.status',
                'sdn.notes',
                'sdn.receiver_name',
                'sdn.receiver_position',
                'sdn.received_datetime',

                // Signature fields
                'sdn.signed_name',
                'sdn.signed_position',
                'sdn.signed_city',
                'sdn.signed_at',
                'sdn.signature_image',

                // Supplier info
                's.supplier_name',
                's.address as supplier_address',
                's.phone as supplier_phone',
                's.email as supplier_email',

                // PO info
                'spo.po_number',
                'spo.supplier_po_id',

                // Activity type (project name)
                'at.type_name as project_name',
                'at.type_code as activity_type_code',

                // Creator
                'creator.full_name as creator_name',
            )
            ->where('sdn.supplier_delivery_note_id', $id)
            ->first();

        if (!$deliveryNote) {
            return ['error' => 'Supplier delivery note not found'];
        }

        // Fallback delivery_date → delivery_note_date
        $deliveryNote->delivery_date = $deliveryNote->delivery_note_date;

        // Recipient fallback → supplier name
        $deliveryNote->recipient_name = $deliveryNote->supplier_name ?? '-';

        // Signature → base64
        $deliveryNote->signature_image_base64 = null;
        if ($deliveryNote->signature_image) {
            $path = storage_path('app/public/' . $deliveryNote->signature_image);
            if (file_exists($path)) {
                $mime = mime_content_type($path);
                $raw  = base64_encode(file_get_contents($path));
                $deliveryNote->signature_image_base64 = "data:{$mime};base64,{$raw}";
            }
        }

        // Company
        $company = DB::table('companies')
            ->where('company_id', $deliveryNote->company_id)
            ->select([
                'company_id',
                'company_name',
                'address',
                'phone',
                'email',
                'website',
                'city',
                'npwp',
                'pic_name',
                'logo_path',
                'bank_name',
                'bank_account',
                DB::raw("NULL as tagline"),
                DB::raw("NULL as whatsapp"),
            ])
            ->first();

        if ($company) {
            // Logo → base64
            $company->logo_base64 = null;
            if ($company->logo_path) {
                $logoPath = storage_path('app/public/' . $company->logo_path);
                if (file_exists($logoPath)) {
                    $mime = mime_content_type($logoPath);
                    $raw  = base64_encode(file_get_contents($logoPath));
                    $company->logo_base64 = "data:{$mime};base64,{$raw}";
                }
            }
        }

        // Items
        $items = DB::table('supplier_delivery_note_items as sdni')
            ->leftJoin('products as p', 'p.product_id', '=', 'sdni.product_id')
            ->select(
                'sdni.item_id',
                'sdni.product_id',
                'sdni.batch_number',
                'sdni.quantity',
                'sdni.purchase_price',
                'sdni.manufacture_date',
                'sdni.expiry_date',
                'sdni.notes',
                'p.product_name',
                'p.product_code',
                'p.unit',
            )
            ->where('sdni.supplier_delivery_note_id', $id)
            ->orderBy('sdni.item_id')
            ->get();

        // Attach company ke deliveryNote agar bisa diakses di blade
        // seperti $deliveryNote->company->logo_base64
        $deliveryNote->company = $company;

        return compact('deliveryNote', 'items', 'company');
    }

    /* =========================
     * PDF OPTIONS (shared)
     * ========================= */
  private function pdfOptions(\Barryvdh\DomPDF\PDF $pdf): \Barryvdh\DomPDF\PDF
{
    return $pdf
        ->setPaper('A4', 'portrait')
        ->setOption('isHtml5ParserEnabled', false)
        ->setOption('isRemoteEnabled', false)
        ->setOption('enable_php', false)
        ->setOption('dpi', 96)
        ->setOption('defaultFont', 'Arial');
}

/* =========================
 * STREAM / PREVIEW
 * ========================= */
public function generate($id)
{
    try {
        $data = $this->buildDeliveryNoteData((int) $id);

        if (isset($data['error'])) {
            return response()->json([
                'success' => false,
                'message' => $data['error'],
            ], 404);
        }

        $filename = $this->safeFilename($data['deliveryNote']->delivery_note_number);
        $pdf      = $this->pdfOptions(Pdf::loadView('pdf.delivery-note-supplier', $data));

        return $pdf->stream("Surat_Jalan_{$filename}.pdf");

    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error generating PDF',
            'error'   => $e->getMessage(),
            'line'    => $e->getLine(),
        ], 500);
    }
}

/* =========================
 * DOWNLOAD
 * ========================= */
public function download($id)
{
    try {
        $data = $this->buildDeliveryNoteData((int) $id);

        if (isset($data['error'])) {
            return response()->json([
                'success' => false,
                'message' => $data['error'],
            ], 404);
        }

        $filename = $this->safeFilename($data['deliveryNote']->delivery_note_number);
        $pdf      = $this->pdfOptions(Pdf::loadView('pdf.delivery-note-supplier', $data));

        return $pdf->download("Surat_Jalan_{$filename}.pdf");

    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error downloading PDF',
            'error'   => $e->getMessage(),
        ], 500);
    }
}
}
