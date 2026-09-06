<?php

namespace App\Http\Requests;

use App\Rules\NormalizableRepository;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientProjectRequest extends FormRequest
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
            // Present as well as nullable, for the same reason as the client's
            // billing address: nullable says an empty description is legal, not
            // that an absent key means one. Without `present` a PATCH that only
            // meant to rename the project erased its description.
            'description' => ['present', 'nullable', 'string', 'max:5000'],
            // Present for the same reason as the description above: without
            // it, a PATCH that only meant to rename the project would unmap it
            // from its repository, and the operator would find out the next
            // time an agent asked them which project this checkout was.
            'repository' => ['present', 'nullable', 'string', 'max:255', new NormalizableRepository],
            'status' => ['required', 'string', 'in:active,archived'],
            // Narrowing what a client sees is a disclosure decision, so it is
            // always sent rather than defaulted - an omitted checkbox must not
            // quietly re-expose a project someone hid.
            'is_visible_to_client' => ['required', 'boolean'],
            // The version the operator was looking at when they opened the
            // form. Required, because the field it protects is a disclosure
            // decision: two managers with the Manage page open, one hides a
            // project, and the other's stale form still carries
            // `is_visible_to_client: true` and re-exposes it. Every field here
            // passes validation in that scenario - the payload is not
            // malformed, it is out of date - so nothing but the version can
            // tell the difference.
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
