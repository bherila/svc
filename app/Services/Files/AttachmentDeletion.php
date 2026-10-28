<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class AttachmentDeletion
{
    private function __construct(public string $expectedVersion, public string $expectedParentVersion) {}

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        if (array_diff(array_keys($input), ['confirm', 'expected_version', 'expected_parent_version']) !== []
            || ($input['confirm'] ?? null) !== true) {
            throw ValidationException::withMessages(['attachment' => 'Explicit confirmation and supported deletion fields are required.']);
        }
        $data = Validator::make($input, ['expected_version' => ['required', 'string', 'size:64'],
            'expected_parent_version' => ['required', 'string', 'size:64']])->validate();

        return new self($data['expected_version'], $data['expected_parent_version']);
    }
}
