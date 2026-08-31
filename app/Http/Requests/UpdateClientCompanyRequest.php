<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            // Nullable rather than optional: clearing a billing address is a
            // thing an operator does, and `sometimes` would make an empty
            // field indistinguishable from an untouched one.
            'billing_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
