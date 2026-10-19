<?php

namespace App\Http\Requests\Billing;

use App\Support\Billing\PaymentCorrection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The one field the screen's date correction may name, and why.
 *
 * There is no payment-edit path in this application by design, and this does
 * not add one: the rules below are the whole request, so an amount, a currency,
 * a method, a status or a refunded amount sent alongside is not validated, not
 * read and not written. What the service is handed is a date and a reason.
 *
 * The reason is required, as `payments.correct` requires it (#454): a payment
 * correction says why in the client history whichever door it came through.
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
            'reason' => ['required', 'string', 'max:'.PaymentCorrection::REASON_MAX_LENGTH],
        ];
    }
}
