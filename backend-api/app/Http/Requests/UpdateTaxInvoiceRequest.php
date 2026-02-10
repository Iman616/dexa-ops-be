<?php
// app/Http/Requests/UpdateTaxInvoiceRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $taxInvoiceId = $this->route('tax_invoice');

        return [
            'tax_invoice_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('tax_invoices', 'tax_invoice_number')->ignore($taxInvoiceId, 'tax_invoice_id')
            ],
            'tax_invoice_date' => 'nullable|date',
            'tax_type' => 'nullable|in:ppn,pph_21,pph_22,pph_23',
            'dpp_amount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'notes' => 'nullable|string',
        ];
    }
}
