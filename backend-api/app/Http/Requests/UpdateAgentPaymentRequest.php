<?php
// app/Http/Requests/UpdateAgentPaymentRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'due_date' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0.01',
            'agent_invoice_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ];
    }
}
