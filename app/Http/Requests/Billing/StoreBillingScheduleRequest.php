<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class StoreBillingScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_agreement' => ['required', 'uuid'],
            'cadence' => ['required', 'in:monthly,quarterly,semi_annual,annual'],
            'anchor_month' => ['nullable', 'integer', 'between:1,12'],
            'anchor_day' => ['nullable', 'integer', 'between:1,31'],
            'next_run_on' => ['required', 'date'],
            'due_days' => ['required', 'integer', 'min:0', 'max:365'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'line_template' => ['required', 'array', 'min:1'],
            'line_template.*.type' => ['required', 'string', 'max:40'],
            'line_template.*.description' => ['required', 'string', 'max:10000'],
            'line_template.*.quantity' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'line_template.*.unit_amount' => ['required', 'integer', 'min:0'],
            'line_template.*.tax_amount' => ['nullable', 'integer', 'min:0'],
            'line_template.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
