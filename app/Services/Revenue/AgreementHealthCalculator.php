<?php

namespace App\Services\Revenue;

use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\BillingCycleResolver;
use App\Support\Revenue\AgreementHealth;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\RevenueAssumptions;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * An agreement's retainer use this period, read off the capacity ledger the
 * billing engine already builds (`InvoiceLedgerBuilder`), so the roster and
 * the invoice agree about what was used. Database-free: the ledger rows are
 * handed in.
 *
 * - The period is the billing cycle containing the day read
 *   ({@see BillingCycleResolver::cycleContaining()}).
 * - Hours used and included are the ledger's work and granted retainer hours
 *   in that period's rows.
 * - Over retainer means the period's latest row closes owing hours: work past
 *   the retainer and whatever rollover it could draw on.
 */
final class AgreementHealthCalculator
{
    public function __construct(private readonly BillingCycleResolver $cycles = new BillingCycleResolver) {}

    /**
     * @param  list<MonthSummary>|null  $ledger  null when the ledger refused to build
     */
    public function health(ProjectedAgreement $agreement, ?array $ledger, CarbonImmutable $asOf): AgreementHealth
    {
        $cycle = $this->cycles->cycleContaining($agreement, Carbon::instance($asOf->max($agreement->startsOn)));
        $start = CarbonImmutable::instance($cycle->start)->startOfDay();
        $end = CarbonImmutable::instance($cycle->end)->startOfDay();
        $endsSoon = self::endsWithin($agreement, $asOf, RevenueAssumptions::ENDING_SOON_DAYS);

        if ($ledger === null) {
            return new AgreementHealth($agreement, $start, $end, null, null, null, $endsSoon, AgreementHealth::LEDGER_UNREADABLE);
        }
        if ($agreement->periodRetainerHours() <= 0) {
            return new AgreementHealth($agreement, $start, $end, null, null, null, $endsSoon, AgreementHealth::NO_RETAINER);
        }

        $rows = array_values(array_filter($ledger, static fn (MonthSummary $row): bool => $row->cycleStart !== null
            ? $row->cycleStart === $start->toDateString()
            : $row->yearMonth >= $start->format('Y-m') && $row->yearMonth <= $end->format('Y-m')));
        $latest = $rows === [] ? null : $rows[count($rows) - 1];

        return new AgreementHealth(
            $agreement,
            $start,
            $end,
            round(array_sum(array_map(static fn (MonthSummary $row): float => $row->hoursWorked, $rows)), 4),
            round(array_sum(array_map(static fn (MonthSummary $row): float => $row->retainerHours, $rows)), 4),
            $latest !== null && $latest->closing->negativeBalance > 0,
            $endsSoon,
            AgreementHealth::OK,
        );
    }

    /** Does the agreement end on a day in [$asOf, $asOf + $days]? */
    public static function endsWithin(ProjectedAgreement $agreement, CarbonImmutable $asOf, int $days): bool
    {
        $end = $agreement->effectiveEnd();

        return $end !== null && $end->betweenIncluded($asOf->startOfDay(), $asOf->startOfDay()->addDays($days));
    }
}
