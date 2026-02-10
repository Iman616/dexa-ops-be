<?php
// app/Http/Requests/StoreTenderDocumentRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenderDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_id' => 'required|exists:purchase_orders,po_id',
            'company_id' => 'required|exists:companies,company_id',
            'document_type' => [
                'required',
                Rule::in([
                    'kontrak_asli',
                    'kontrak_soft',
                    'ba_uji_fungsi',
                    'bahp',
                    'bast',
                    'sp2d',
                    'bukti_ppn',
                    'bukti_pph'
                ])
            ],
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // 10MB
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'po_id.required' => 'PO is required',
            'po_id.exists' => 'PO not found',
            'company_id.required' => 'Company is required',
            'document_type.required' => 'Document type is required',
            'document_type.in' => 'Invalid document type',
            'file.required' => 'File is required',
            'file.mimes' => 'File must be PDF, JPG, JPEG, or PNG',
            'file.max' => 'File size must not exceed 10MB',
        ];
    }
}
