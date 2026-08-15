<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class SendInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['recipients' => ['nullable', 'array', 'min:1'], 'recipients.*' => ['email', 'max:255']];
    }
}
