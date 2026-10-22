<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'billing_address' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:191'],
            'website_url' => ['sometimes', 'nullable', 'url:http,https', 'max:191'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/D'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
