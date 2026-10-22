<?php

declare(strict_types=1);

namespace App\Services\Clients;

/** Presence is intentional: omitted fields survive, explicit null clears. */
final readonly class ContactPatch
{
    /** @param array<string, string|bool|null> $attributes */
    public function __construct(public array $attributes) {}
}
