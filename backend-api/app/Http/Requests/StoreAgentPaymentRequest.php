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
            'company_id' => 'required|exists:companies,company_id',
            'supplier_id' => 'required|exists:suppliers,supplier_id',
            'supplier_po_id' => 'nullable|exists:supplier_purchase_orders,supplier_po_id',
            'due_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'agent_invoice_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ];
    }
}
