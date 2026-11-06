<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/** One entry of an accounting feed: where it sits, and the wire shape it is served as. */
final readonly class FeedEntry
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public FeedPosition $position,
        public array $payload,
    ) {}
}
