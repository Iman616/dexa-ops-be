<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;

class ProformaInvoicePDFController extends Controller
{
    private function safeFilename(string $value): string
    {
        return preg_replace('/[\/\\\\:*?"<>| ]+/', '_', $value);
    }

    public function generate($id)
    {
        try {
            // =========================
            // HEADER : PROFORMA + CUSTOMER + CREATOR
            // =========================
            $proforma = DB::table('proforma_invoices as pi')
                ->leftJoin('customers as c', 'c.customer_id', '=', 'pi.customer_id')
                ->leftJoin('users as creator', 'creator.user_id', '=', 'pi.created_by')
                ->select(
                    // PI fields
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
                    
                    // Signature
                    'pi.signed_name',
                    'pi.signed_position',
                    'pi.signed_city',
                    'pi.signed_at',
                    
                    // Customer info
                    'c.customer_name',
                    'c.address as customer_address',
                    'c.phone as customer_phone',
                    'c.email as customer_email',
                    
                    // Creator info
                    'creator.full_name as creator_name',
                    'creator.username as creator_username'
                )
                ->where('pi.proforma_id', $id)
                ->first();

            if (!$proforma) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proforma invoice not found'
                ], 404);
            }

            // ✅ Set default values
            $proforma->payment_terms = $proforma->payment_terms ?? 'Net 30 days';
            $proforma->delivery_terms = $proforma->delivery_terms ?? 'FOB Destination';
            $proforma->currency = $proforma->currency ?? 'IDR';

            // =========================
            // DETAIL : ITEMS
            // =========================
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
                    'p.product_code'
                )
                ->where('pii.proforma_id', $id)
                ->orderBy('pii.item_id')
                ->get();

            // =========================
            // COMPANY
            // =========================
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
            }

            // =========================
            // ✅ HITUNGAN - GUNAKAN DATA DARI DATABASE
            // =========================
            $subtotal = $proforma->subtotal;
            
            // DPP Lainnya (konversi 11/12) - ini untuk keperluan pajak Indonesia
            $dpp_lainnya = $subtotal * (11 / 12);
            
            // PPN dihitung dari DPP
            $ppn = $proforma->tax_amount;
            
            // Total
            $total = $proforma->total_amount;

            // =========================
            // COMPILE DATA
            // =========================
            $data = compact(
                'proforma',
                'items',
                'company',
                'subtotal',
                'dpp_lainnya',
                'ppn',
                'total'
            );

            $filename = $this->safeFilename($proforma->proforma_number);

            $pdf = Pdf::loadView('pdf.proforma-invoice', $data)
                ->setPaper('A4', 'portrait')
                ->setOption('enable_php', true)
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', true);

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
            // ✅ SAMA PERSIS dengan generate(), hanya beda di akhir
            $proforma = DB::table('proforma_invoices as pi')
                ->leftJoin('customers as c', 'c.customer_id', '=', 'pi.customer_id')
                ->leftJoin('users as creator', 'creator.user_id', '=', 'pi.created_by')
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
                    'c.customer_name',
                    'c.address as customer_address',
                    'c.phone as customer_phone',
                    'c.email as customer_email',
                    'creator.full_name as creator_name',
                    'creator.username as creator_username'
                )
                ->where('pi.proforma_id', $id)
                ->first();

            if (!$proforma) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proforma invoice not found'
                ], 404);
            }

            $proforma->payment_terms = $proforma->payment_terms ?? 'Net 30 days';
            $proforma->delivery_terms = $proforma->delivery_terms ?? 'FOB Destination';
            $proforma->currency = $proforma->currency ?? 'IDR';

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
                    'p.product_code'
                )
                ->where('pii.proforma_id', $id)
                ->orderBy('pii.item_id')
                ->get();

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
            }

            $subtotal = $proforma->subtotal;
            $dpp_lainnya = $subtotal * (11 / 12);
            $ppn = $proforma->tax_amount;
            $total = $proforma->total_amount;

            $data = compact(
                'proforma',
                'items',
                'company',
                'subtotal',
                'dpp_lainnya',
                'ppn',
                'total'
            );

            $filename = $this->safeFilename($proforma->proforma_number);

            $pdf = Pdf::loadView('pdf.proforma-invoice', $data)
                ->setPaper('A4', 'portrait')
                ->setOption('enable_php', true)
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', true);

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
