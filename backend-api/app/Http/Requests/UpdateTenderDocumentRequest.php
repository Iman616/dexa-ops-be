<?php
// app/Http/Requests/UpdateTenderDocumentRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenderDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'File must be PDF, JPG, JPEG, or PNG',
            'file.max' => 'File size must not exceed 10MB',
        ];
    }
}
