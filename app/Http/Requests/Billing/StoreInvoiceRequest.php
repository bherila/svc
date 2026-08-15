<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:80'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'service_period_start' => ['nullable', 'date'],
            'service_period_end' => ['nullable', 'date', 'after_or_equal:service_period_start'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'is_visible_to_client' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.type' => ['required', 'string', 'max:40'],
            'lines.*.description' => ['required', 'string', 'max:10000'],
            'lines.*.quantity' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'lines.*.unit_amount' => ['required', 'integer', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'integer', 'min:0'],
            'lines.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
