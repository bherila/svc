<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

class AcceptProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'signer_name' => ['required', 'string', 'max:200'],
            'signer_title' => ['nullable', 'string', 'max:200'],
        ];
    }
}
