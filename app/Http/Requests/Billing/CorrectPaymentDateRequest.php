<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The one field a payment correction may name.
 *
 * There is no payment-edit path in this application by design, and this does
 * not add one: the rules below are the whole request, so an amount, a currency,
 * a method, a status or a refunded amount sent alongside is not validated, not
 * read and not written. What the service is handed is a date and nothing else.
 *
 * The format is the read endpoint's, matching {@see StorePaymentRequest}. The
 * bounds are the service's, because a correction crosses the same boundary a
 * recording does.
 */
class CorrectPaymentDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'received_on' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
