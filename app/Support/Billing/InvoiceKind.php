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
     * Whether an invoice of this kind is a claim about a span of time, and so
     * may not be issued without stating both ends of it.
     *
     * Asked through {@see ServicePeriodRequirement}, which handles the two
     * values this enum cannot represent - a null kind and an unrecognised one -
     * and the question kind alone cannot answer: a row naming a billing
     * schedule is read by the period guards whatever kind it carries, so the
     * ad-hoc exemption below holds only while the row names none.
     *
     * `interim_overage` is included, and it is the one kind where this
     * deliberately does **not** mirror the guards.
     * `BillingPeriodCollisionResolver` clears an unlinked interim row, and
     * `UnplaceableInvoiceAuditor` leaves it out of the count operators gate on,
     * both via {@see self::cycleGuardExclusions()}. That is right for them and
     * is the reason this must differ: an unplaceable interim *draft* has
     * charged nobody, so suppressing the interim that would bill the work would
     * withhold money genuinely owed. The resolver clearing it is precisely why
     * the stale draft has to be stopped at the door instead - issue both and
     * the client is charged twice for the same hours, with nothing on either
     * invoice to show it (#218).
     *
     * `terminal` is included, and that is a decision rather than a default.
     * The enum calls it a closing invoice generated at agreement termination,
     * and termination-line composition reads `service_period_end` to decide
     * what the closing period covers; an undated one is the deferred-termination
     * defect waiting to happen rather than a legitimate shape. No test
     * establishes a deliberately undated terminal invoice, and production holds
     * none of this kind at all, so nothing argues for the exemption.
     *
     * Exhaustive with no `default`, so a fifth kind is a compile-time question
     * rather than one silently answered "exempt" - the safe direction here is
     * to require the period, and a `default` would pick the other one.
     */
    public function requiresCompleteServicePeriod(): bool
    {
        return match ($this) {
            self::CadencePeriod, self::InterimOverage, self::Terminal => true,
            self::AdHoc => false,
        };
    }

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
