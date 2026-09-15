<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class CorrectIssuedInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'due_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'uuid', 'distinct'],
            'lines.*.description' => ['required', 'string', 'max:10000'],
            'lines.*.quantity' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'lines.*.unit_amount' => ['required', 'integer', 'min:0'],
            'lines.*.tax_amount' => ['required', 'integer', 'min:0'],
        ];
    }
}
