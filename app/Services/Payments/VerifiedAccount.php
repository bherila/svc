<?php

namespace App\Services\Payments;

/** What a credential turned out to open. */
final readonly class VerifiedAccount
{
    public function __construct(
        public string $externalAccountId,
        public ?string $displayName,
        /** `live`/`test` for Stripe, `production`/`sandbox` for Square. */
        public ?string $environment,
        public ?string $locationId = null,
    ) {}
}
