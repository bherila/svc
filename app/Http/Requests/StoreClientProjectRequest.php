<?php

namespace App\Http\Requests;

use App\Rules\NormalizableRepository;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Optional at creation: most projects are not worked in a
            // repository, and the ones that are are usually mapped later, once
            // somebody notices they are logging time by hand.
            'repository' => ['nullable', 'string', 'max:255', new NormalizableRepository],
            'is_visible_to_client' => ['sometimes', 'boolean'],
        ];
    }
}
