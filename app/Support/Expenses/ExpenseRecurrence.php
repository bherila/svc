<?php

namespace App\Support\Expenses;

use App\Support\Billing\BillingCadence;
use Carbon\CarbonImmutable;
use DomainException;

/** Anchored calendar arithmetic; a short month never changes the original day. */
final readonly class ExpenseRecurrence
{
    public const BATCH_LIMIT = 24;

    public function __construct(public CarbonImmutable $start, public BillingCadence $cadence) {}

    public function occurrence(int $index): CarbonImmutable
    {
        if ($index < 0 || $index > 100000) {
            throw new DomainException('The expense recurrence cursor is outside the supported calendar.');
        }

        return $this->start->addMonthsNoOverflow($index * $this->cadence->monthsInCycle());
    }
}
