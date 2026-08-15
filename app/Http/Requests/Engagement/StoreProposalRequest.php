<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'terms' => ['nullable', 'string', 'max:30000'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'is_visible_to_client' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*' => ['array'],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'items.*.unit_amount' => ['required', 'integer', 'min:0', 'max:900000000000'],
            'items.*.cadence' => ['required', Rule::in(['one_time', 'monthly', 'quarterly', 'semi_annual', 'annual'])],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
