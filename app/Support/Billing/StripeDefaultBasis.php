<?php

namespace App\Support\Billing;

/**
 * What SVC held for one Stripe customer's default method just before Stripe
 * was asked what it is now: the workspace that owns the customer, and the
 * revision of the default. The answer is applied only while the revision
 * is unchanged, so a read overtaken by another write is retried and never
 * rewinds a newer one.
 */
final readonly class StripeDefaultBasis
{
    public function __construct(public int $workspaceId, public int $revision) {}
}
