<?php

namespace App\Http\Requests;

use App\Support\Access\PortalAccessScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a client's portal access is to reach: the whole client, or only some
 * of its projects. Adding someone also names them by email.
 */
class PortalAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'email', 'max:255'],
            'scope' => ['required', 'string', Rule::enum(PortalAccessScope::class)],
            // Limiting access to no project at all would reach nothing; remove it instead.
            'projects' => ['array', 'max:500', Rule::requiredIf(fn (): bool => $this->input('scope') === PortalAccessScope::Projects->value), Rule::prohibitedIf(fn (): bool => $this->input('scope') === PortalAccessScope::Company->value && $this->input('projects') !== [])],
            'projects.*' => ['required', 'string', 'distinct', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'projects.required' => 'Choose at least one project, or give access to the whole client.',
            'projects.prohibited' => 'Access to the whole client is not limited to projects.',
        ];
    }

    public function scope(): PortalAccessScope
    {
        return PortalAccessScope::from($this->string('scope')->toString());
    }

    /** @return list<string> */
    public function projectPublicIds(): array
    {
        return $this->scope() === PortalAccessScope::Projects ? array_values(array_map('strval', (array) $this->input('projects', []))) : [];
    }
}
