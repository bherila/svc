<?php

namespace App\Support\Billing;

use SensitiveParameter;

/**
 * A complete current group, bound to a local payment revision captured before HTTP.
 * Raw dispute objects stay in memory for the balance-movement reader; never log
 * or persist them. They are not response DTOs for any public transport.
 */
final readonly class StripeDisputeSnapshot
{
    /** @param  list<array<string, mixed>>  $disputes */
    public function __construct(
        public int $paymentId,
        public int $workspaceId,
        #[SensitiveParameter] public string $providerIdentifier,
        public int $version,
        public string $currency,
        #[SensitiveParameter] public int $paymentAmount,
        #[SensitiveParameter] public int $withheldMinor,
        #[SensitiveParameter] public array $disputes,
    ) {}
}
