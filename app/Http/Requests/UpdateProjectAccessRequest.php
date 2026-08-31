<?php

namespace App\Http\Requests;

use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectAccessRequest extends FormRequest
{
    /** Removing access is a role of its own rather than a second endpoint. */
    public const NONE = 'none';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'user' => ['required', 'string'],
            'role' => [
                'required',
                'string',
                Rule::in([...array_column(ProjectRole::cases(), 'value'), self::NONE]),
            ],
        ];
    }
}
