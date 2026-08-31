<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientProjectRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'string', 'in:active,archived'],
            // Narrowing what a client sees is a disclosure decision, so it is
            // always sent rather than defaulted - an omitted checkbox must not
            // quietly re-expose a project someone hid.
            'is_visible_to_client' => ['required', 'boolean'],
        ];
    }
}
