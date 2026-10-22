<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Validator;

/** Public read pagination; audience and parent authority are always server-owned. */
final readonly class AttachmentPageQuery
{
    private function __construct(public int $limit, public ?string $cursor) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $values = Validator::make($input, ['limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:4096']])->validate();

        return new self((int) ($values['limit'] ?? 100), $values['cursor'] ?? null);
    }
}
