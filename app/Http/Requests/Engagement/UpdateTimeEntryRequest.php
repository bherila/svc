<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'string'],
            'worked_on' => ['sometimes', 'date_format:Y-m-d'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'description' => ['sometimes', 'string', 'max:10000'],
            'is_billable' => ['sometimes', 'boolean'],
            'is_deferred' => ['sometimes', 'boolean'],
            'is_visible_to_client' => ['sometimes', 'boolean'],
            'client_visible_description' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
