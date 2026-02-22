<?php
// app/Http/Requests/StoreAgentPaymentRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id'            => 'required|exists:companies,company_id',
            'supplier_id'           => 'required|exists:suppliers,supplier_id',
            'supplier_po_id'        => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'due_date'              => 'required|date',

            // ✅ FIX: contract_value + commission_percentage wajib diisi
            // amount akan auto-dihitung di service (contract_value × percentage / 100)
            'contract_value'        => 'required|numeric|min:0.01',
            'commission_percentage' => 'required|numeric|min:0.01|max:100',

            // amount tidak diterima dari frontend — dihitung di service
            'agent_invoice_number'  => 'nullable|string|max:100',
            'notes'                 => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'contract_value.required'        => 'Nilai kontrak wajib diisi',
            'contract_value.min'             => 'Nilai kontrak harus lebih besar dari 0',
            'commission_percentage.required' => 'Persentase komisi wajib diisi',
            'commission_percentage.min'      => 'Persentase komisi minimal 0.01%',
            'commission_percentage.max'      => 'Persentase komisi maksimal 100%',
        ];
    }
}