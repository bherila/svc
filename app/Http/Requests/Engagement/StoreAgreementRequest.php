<?php

namespace App\Http\Requests\Engagement;

use App\Support\Engagement\AgreementTermRules;
use Illuminate\Foundation\Http\FormRequest;

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
            'agreement_text' => AgreementTermRules::rule('agreement_text', 'nullable'),
            'is_visible_to_client' => ['sometimes', 'boolean'],
            'show_deferred_backlog_to_client' => ['sometimes', 'boolean'],
            'billing_cadence' => AgreementTermRules::rule('billing_cadence', 'sometimes'),
            'currency' => AgreementTermRules::rule('currency', 'required'),
            'hourly_rate_amount' => AgreementTermRules::rule('hourly_rate_amount', 'nullable'),
            'retainer_amount' => AgreementTermRules::rule('retainer_amount', 'nullable'),
            'retainer_minutes' => AgreementTermRules::rule('retainer_minutes', 'nullable'),
            'source_proposal' => ['nullable', 'uuid'],
        ];
    }
}
