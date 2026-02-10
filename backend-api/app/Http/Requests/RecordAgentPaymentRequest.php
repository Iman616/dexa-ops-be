<?php
// app/Http/Requests/RecordAgentPaymentRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordAgentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pay_amount' => 'required|numeric|min:0.01',
            'payment_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'transfer_date' => 'nullable|date',
            'transfer_proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ];
    }
}
