<?php

namespace App\Support\Billing;

/**
 * Classification for a client invoice.
 *
 * `cadence_period` is the standard full-cycle invoice (monthly, quarterly, or annual).
 * `interim_overage` is an intra-cycle invoice for overage hours only.
 * `terminal` is a closing invoice generated at agreement termination.
 * `ad_hoc` is a one-off fixed-price invoice not tied to a recurring agreement.
 */
enum InvoiceKind: string
{
    case CadencePeriod = 'cadence_period';
    case InterimOverage = 'interim_overage';
    case Terminal = 'terminal';
    case AdHoc = 'ad_hoc';

    /**
     * Invoice kinds that are not tied to a recurring agreement cycle and must not
     * block cadence invoice generation when their periods overlap.
     *
     * @return list<string>
     */
    public static function cycleGuardExclusions(): array
    {
        return [self::InterimOverage->value, self::AdHoc->value];
    }

    /**
     * Invoice kinds a cycle-matching predicate will actually read.
     *
     * Not the inverse of {@see cycleGuardExclusions()}, which answers a
     * different question - an interim overage does not block a cadence invoice
     * whose period it overlaps, but it is very much matched to a cycle, because
     * `InterimOverageGenerator::cycleInvoices()` looks it up by one.
     *
     * The distinction matters because every one of those lookups filters by
     * kind *before* it compares cycle dates. A row of any other kind is
     * excluded before its `cycle_start` and `cycle_end` are read at all, so a
     * null there cannot reach a sum or a duplicate guard.
     *
     * A null kind is not representable in this list and belongs with these:
     * a migrated invoice carries none, and the cadence resell guard reads it
     * deliberately for that reason. Callers add `whereNull` themselves.
     *
     * @return list<string>
     */
    public static function matchedByCycle(): array
    {
        return [self::CadencePeriod->value, self::InterimOverage->value];
    }
}
