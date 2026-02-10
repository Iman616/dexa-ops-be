<?php
// app/Http/Requests/StoreTaxInvoiceRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Handle authorization in controller/policy
    }

    public function rules(): array
    {
        return [
            'company_id' => 'required|exists:companies,company_id',
            'invoice_id' => 'required|exists:invoices,invoice_id',
            'tax_invoice_number' => 'nullable|string|max:100|unique:tax_invoices,tax_invoice_number',
            'tax_invoice_date' => 'required|date',
            'tax_type' => 'required|in:ppn,pph_21,pph_22,pph_23',
            'dpp_amount' => 'required|numeric|min:0',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'company_id.required' => 'Company is required',
            'invoice_id.required' => 'Invoice is required',
            'invoice_id.exists' => 'Invoice not found',
            'tax_invoice_date.required' => 'Tax invoice date is required',
            'tax_type.required' => 'Tax type is required',
            'dpp_amount.required' => 'DPP amount is required',
            'tax_rate.required' => 'Tax rate is required',
            'file.mimes' => 'File must be PDF, JPG, JPEG, or PNG',
            'file.max' => 'File size must not exceed 5MB',
        ];
    }
}
