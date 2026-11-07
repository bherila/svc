<?php

declare(strict_types=1);

namespace App\Support\Billing\Autopay;

use App\Models\User;

/**
 * What a person asks when they turn autopay on or change its limit: who they
 * are, which side they act for, the limit in minor units, and the evidence
 * that goes on the record - the exact wording a client agreed to, or the
 * attestation a provider owner or admin entered.
 */
final readonly class AutopayConsentRequest
{
    public function __construct(
        public AutopayActorRole $role,
        public User $actor,
        public int $limit,
        public ?string $wording = null,
        public ?string $attestation = null,
    ) {}
}
