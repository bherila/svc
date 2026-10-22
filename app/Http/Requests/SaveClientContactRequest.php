<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveClientContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'first_name' => ['nullable', 'required_without:last_name', 'string', 'max:100'],
            'last_name' => ['nullable', 'required_without:first_name', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'is_archived' => ['required', 'boolean'],
        ];
        if ($this->routeIs('contacts.update')) {
            $rules['lock_version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }
}
