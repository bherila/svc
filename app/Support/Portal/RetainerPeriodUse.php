<?php

declare(strict_types=1);

namespace App\Support\Portal;

use App\Services\Billing\Balances\MonthSummary;

/**
 * The portal's "This period" figures read off one agreement's capacity ledger:
 * hours used against included, rollover available at the start of the period,
 * and the hours worked past the retainer so far. Pure and database-free; the
 * ledger rows are handed in, already narrowed to the period by
 * {@see self::forPeriod()}.
 *
 * The same ledger the invoice and the operator's roster read, so the client's
 * number and the operator's agree.
 */
final readonly class RetainerPeriodUse
{
    public function __construct(
        public float $hoursUsed,
        public float $hoursIncluded,
        public float $rolloverHours,
        public float $overageHours,
    ) {}

    /**
     * The rows of the period starting `$periodStart`, as the roster's health
     * calculation selects them: by the cycle the ledger row records, or for the
     * legacy monthly ledger by the months the period touches.
     *
     * @param  list<MonthSummary>  $ledger
     */
    public static function forPeriod(array $ledger, string $periodStart, string $periodEnd): ?self
    {
        $rows = array_values(array_filter($ledger, static fn (MonthSummary $row): bool => $row->cycleStart !== null
            ? $row->cycleStart === $periodStart
            : self::touches($row->yearMonth, $periodStart, $periodEnd)));

        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $latest = $rows[count($rows) - 1];

        return new self(
            round(array_sum(array_map(static fn (MonthSummary $row): float => $row->hoursWorked, $rows)), 4),
            round(array_sum(array_map(static fn (MonthSummary $row): float => $row->retainerHours, $rows)), 4),
            max(0.0, $first->opening->rolloverHours),
            max(0.0, $latest->closing->negativeBalance),
        );
    }

    /** Whether a `Y-m` ledger month is one the period [start, end] (both `Y-m-d`) touches. */
    private static function touches(string $yearMonth, string $periodStart, string $periodEnd): bool
    {
        $notBefore = $yearMonth >= substr($periodStart, 0, 7);
        // @infection-ignore-all Equivalent: a `Y-m` string sorts before every `Y-m-d` of its own month, so `<`, `<=` and any prefix length of the end date agree.
        $notAfter = $yearMonth <= $periodEnd;

        return $notBefore && $notAfter;
    }

    /** @return array{minutes_used: int, minutes_included: int, rollover_minutes: int, overage_minutes: int} */
    public function toArray(): array
    {
        return [
            'minutes_used' => (int) round($this->hoursUsed * 60),
            'minutes_included' => (int) round($this->hoursIncluded * 60),
            'rollover_minutes' => (int) round($this->rolloverHours * 60),
            'overage_minutes' => (int) round($this->overageHours * 60),
        ];
    }
}
