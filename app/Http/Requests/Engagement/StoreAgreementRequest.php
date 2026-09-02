<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            // Required, not nullable (#147). `client_agreements.starts_on` is
            // `NOT NULL` because a null had seven incompatible readings; this is
            // the edge an operator could still push one through.
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'agreement_text' => ['nullable', 'string', 'max:30000'],
            'is_visible_to_client' => ['sometimes', 'boolean'],
            'billing_cadence' => ['sometimes', Rule::in(['one_time', 'monthly', 'quarterly', 'semi_annual', 'annual'])],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'hourly_rate_amount' => ['nullable', 'integer', 'min:0'],
            'retainer_amount' => ['nullable', 'integer', 'min:0'],
            'retainer_minutes' => ['nullable', 'integer', 'min:0'],
            'source_proposal' => ['nullable', 'uuid'],
        ];
    }
}
