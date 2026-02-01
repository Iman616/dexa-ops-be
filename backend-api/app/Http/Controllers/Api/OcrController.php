<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
class OcrController extends Controller
{
    public function extractDeliveryNote(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
        ]);

        try {
            $file = $request->file('file');
            $filename = $file->getClientOriginalName();

            // Option 1: Parse filename (basic)
            $data = $this->parseFilename($filename);

            // Option 2: Use OCR library (advanced)
            // if ($file->getMimeType() === 'application/pdf') {
            //     $data = $this->parsePDF($file);
            // } else {
            //     $data = $this->parseImage($file);
            // }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membaca file',
            ], 500);
        }
    }

    private function parseFilename($filename)
    {
        $data = [
            'company_id' => null,
            'delivery_note_number' => null,
            'delivery_note_date' => null,
        ];

        // Pattern: SJ067-2025-DXA-20260123.pdf

        // Extract DN Number
        if (preg_match('/(?:SJ|DN)[\s\/\-]?([\d\/\-]+)/i', $filename, $matches)) {
            $data['delivery_note_number'] = $matches[0];
        }

        // Extract Date
        if (preg_match('/(\d{4}-\d{2}-\d{2}|\d{8})/', $filename, $matches)) {
            $dateStr = $matches[1];
            if (strlen($dateStr) === 8) {
                $dateStr = substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
            }
            $data['delivery_note_date'] = $dateStr;
        }

        // Extract Company
        if (preg_match('/\b(DXA|PTD|ABC)\b/i', $filename, $matches)) {
            $companyCode = strtoupper($matches[1]);
            $company = \App\Models\Company::where('company_code', $companyCode)->first();
            if ($company) {
                $data['company_id'] = $company->company_id;
            }
        }

        return $data;
    }

    // Advanced: Parse PDF dengan thiagoalessio/tesseract_ocr
    private function parsePDF($file)
    {
        // Install: composer require thiagoalessio/tesseract_ocr
        // Install Tesseract: apt-get install tesseract-ocr

        // Convert PDF to images lalu OCR
        // ...

        return [];
    }

    // Advanced: Parse Image dengan OCR
    private function parseImage($file)
    {
        // Use Tesseract OCR
        // $text = (new TesseractOCR($file->path()))->run();
        // Parse $text untuk extract data

        return [];
    }
}