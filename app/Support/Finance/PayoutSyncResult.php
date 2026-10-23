<?php

namespace App\Support\Finance;

/**
 * What applying one payout report did (or, in a dry run, would do): the
 * workspaces it reached, and how many of their payout rows and payments it
 * changed. Counts only, so it is safe to print.
 */
final readonly class PayoutSyncResult
{
    /** @param list<int> $workspaceIds */
    public function __construct(
        public array $workspaceIds,
        public int $payoutRowsChanged,
        public int $paymentsChanged,
    ) {}

    /** The one workspace it reached, or null for none or several. */
    public function soleWorkspaceId(): ?int
    {
        return count($this->workspaceIds) === 1 ? $this->workspaceIds[0] : null;
    }
}
