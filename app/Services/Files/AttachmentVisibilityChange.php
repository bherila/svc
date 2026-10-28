<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class AttachmentVisibilityChange
{
    public function __construct(public bool $visibleToClient, public string $expectedVersion, public string $expectedParentVersion) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        if (array_diff(array_keys($input), ['is_visible_to_client', 'expected_version', 'expected_parent_version']) !== []) {
            throw ValidationException::withMessages(['attachment' => 'Unsupported attachment sharing field.']);
        }
        $data = Validator::make($input, ['is_visible_to_client' => ['required', 'boolean'],
            'expected_version' => ['required', 'string', 'size:64'], 'expected_parent_version' => ['required', 'string', 'size:64']])->validate();

        // HTTP form 0/1 are booleans after validation; no PHP truthiness of arbitrary strings.
        return new self(in_array($data['is_visible_to_client'], [true, 1, '1'], true), $data['expected_version'], $data['expected_parent_version']);
    }
}
