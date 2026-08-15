<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'worked_on' => ['required', 'date_format:Y-m-d'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'description' => ['required', 'string', 'max:10000'],
            'is_billable' => ['sometimes', 'boolean'],
            'is_deferred' => ['sometimes', 'boolean'],
            'billing_rate_amount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }
}
