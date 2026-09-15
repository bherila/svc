<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            // Present *and* nullable, which are different claims. Nullable
            // alone said an empty billing address is legal - true, and clearing
            // one is a thing an operator does. It did not say the key has to
            // arrive, so a PATCH omitting it validated and `validated()`
            // returned null, and the controller wrote that null over a real
            // address. Absent and empty are different intentions and this is a
            // full update, so the caller has to state which one it means.
            'billing_email' => ['present', 'nullable', 'email', 'max:255'],
            'automatic_invoice_email_enabled' => ['sometimes', 'boolean'],
            // Calendar days in the workspace timezone. A year is deliberately
            // the upper bound: this is a review window, not archival workflow.
            'automatic_invoice_email_delay_days' => [
                'nullable',
                'required_if:automatic_invoice_email_enabled,true',
                'integer',
                'min:0',
                'max:365',
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
