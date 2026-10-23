<?php

namespace App\Services\Files;

/** Authorized native metadata with finished navigation URLs. */
final readonly class AttachmentFilePage
{
    /** @param list<array<string, mixed>> $files */
    public function __construct(public array $files, public ?string $nextHref, public ?string $previousHref) {}
}
