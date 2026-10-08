<?php

namespace App\Http\Requests\Billing;

use App\Support\Billing\ReceivedPaymentData;
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
        return ReceivedPaymentData::rules(requireDate: false, includeBookkeeping: true);
    }
}
