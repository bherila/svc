<?php

namespace App\Support\Finance;

/** The door a finance reconciliation call came through; see {@see FinanceCaller}. */
enum FinanceDoor: string
{
    case OAuth = 'oauth';
    case FinanceToken = 'finance_token';
    case Console = 'console';

    /** Whether a workspace member who does not manage the workspace may list its payments and allocations. */
    public function membersMayRead(): bool
    {
        return match ($this) {
            // The contract the Sanctum finance integration has always had.
            self::FinanceToken => true,
            // New doors (#457) are for the people who may also reconcile.
            self::OAuth, self::Console => false,
        };
    }
}
