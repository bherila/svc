<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class CreateStripePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['payment_method_id' => ['nullable', 'string', 'max:255'], 'idempotency_key' => ['nullable', 'string', 'max:255']];
    }
}
