<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Where a verified Stripe event is allowed to land: the key for reading back
 * from the account it came from, and the workspaces that account serves.
 * `workspaceIds` is null only on the legacy environment path, before the
 * migration command has run, when the one account served every workspace.
 */
final readonly class StripeEventRoute
{
    /** @param  list<int>|null  $workspaceIds */
    public function __construct(public ?StripeAccess $access, public ?array $workspaceIds) {}

    public function permits(?int $workspaceId): bool
    {
        if ($workspaceId === null) {
            return false;
        }

        return $this->workspaceIds === null || in_array($workspaceId, $this->workspaceIds, true);
    }

    public function access(): StripeAccess
    {
        return $this->access ?? throw new RuntimeException('Stripe is not connected for this event.');
    }
}
