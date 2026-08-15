<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['open', 'in_progress', 'completed'])],
            'is_visible_to_client' => ['sometimes', 'boolean'],
        ];
    }
}
