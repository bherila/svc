<?php

namespace App\Support\Billing;

/** Why a deferred backlog states less than its full figure. */
enum DeferredBacklogIssue: string
{
    /**
     * An entry of the client points at a project outside the client. The
     * termination path refuses to bill through it, so no total is honest.
     */
    case ProjectChainMismatch = 'project_chain_mismatch';

    /**
     * The hours are known but the termination pricing refuses them: the
     * agreement states no hourly rate, or a flat-hourly snapshot is missing
     * or in another currency.
     */
    case Unpriced = 'unpriced';

    /**
     * The agreement's capacity ledger refuses to build, so any re-carried
     * balance termination would also bill is unknown and not included.
     */
    case LedgerUnreadable = 'ledger_unreadable';

    public function message(): string
    {
        return match ($this) {
            self::ProjectChainMismatch => 'A deferred time entry points to a project outside this client, so the backlog cannot be stated. Correct the entry first.',
            self::Unpriced => 'The deferred hours cannot be priced at the hourly rate as they stand.',
            self::LedgerUnreadable => 'The capacity ledger cannot be read, so any re-carried deferred balance is unknown and not included.',
        };
    }
}
