<?php

namespace App\Http\Requests\Billing;

use App\Support\Billing\InvoicePaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            // The same format the read endpoint filters by. `date` accepted
            // anything `strtotime()` could make sense of, so "next friday"
            // reached the service and was stored as whatever day the request
            // happened to run on - a value the finance window that reads this
            // column could never have asked for. The bounds themselves live in
            // the service, where every caller crosses; this is the cheap format
            // rule the browser gets a field error from.
            'received_on' => ['nullable', 'date_format:Y-m-d'],
            'method' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(InvoicePaymentStatus::all())],
            'external_finance_transaction_uuid' => ['nullable', 'uuid'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
