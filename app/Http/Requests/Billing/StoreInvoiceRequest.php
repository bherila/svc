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
            // An invoice may be built from tracked time, from manual lines, or both -
            // but not from nothing. `required_without` keeps that mutual requirement
            // in the request rather than surfacing as a DomainException later.
            'time_entry_ids' => ['sometimes', 'array'],
            'time_entry_ids.*' => ['uuid'],
            'lines' => ['required_without:time_entry_ids', 'array'],
            'lines.*.type' => ['required', 'string', 'max:40'],
            'lines.*.project_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['required', 'string', 'max:10000'],
            'lines.*.quantity' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'lines.*.unit_amount' => ['required', 'integer', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'integer', 'min:0'],
            'lines.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
