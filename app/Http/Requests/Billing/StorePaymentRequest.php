<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

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
            'received_on' => ['nullable', 'date'],
            'method' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', 'in:pending,succeeded,failed,refunded,disputed'],
            'external_finance_transaction_uuid' => ['nullable', 'uuid'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
