<?php

namespace App\Support\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientTimeEntry;
use App\Services\Billing\MoneyService;
use Carbon\CarbonImmutable;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * The deferred work an agreement still has on the shelf, and what it costs.
 *
 * Computed without the database from what {@see DeferredBacklogReader} loaded:
 * the outstanding entries are exactly the set the termination invoice would
 * collect today, and the amount is that invoice's charge for them, priced by
 * {@see DeferredTerminationPricing} - the rule the invoice itself uses (#366).
 * The trend reads the same entries' history over the months the caller asks
 * for, normally the capacity ledger's.
 */
final readonly class DeferredBacklog
{
    /**
     * @param  list<int>  $entryIds  the outstanding entries, oldest first; internal ids, never presented
     * @param  list<DeferredBacklogMonth>  $trend
     */
    private function __construct(
        public string $through,
        public string $currency,
        public ?DeferredBacklogIssue $issue,
        public ?string $issueDetail,
        public ?int $minutes,
        public ?int $entryCount,
        public ?string $oldestWorkedOn,
        public ?int $agreementRateMinutes,
        public ?int $flatHourlyMinutes,
        public ?int $amount,
        /** Re-carried deferred minutes the ledger holds; null where it was not read. */
        public ?int $carriedMinutes,
        public array $entryIds,
        public array $trend,
    ) {}

    /**
     * Work dated after `through` is not on the shelf yet, and an allocation
     * dated after it has not taken anything off: both are left out, as the
     * capacity ledger leaves them out.
     *
     * @param  iterable<ClientTimeEntry>  $outstanding  the termination collection set, in FIFO order
     * @param  iterable<ClientTimeEntry>  $history  deferred work of the agreement loaded withCapacityPlacement()
     * @param  list<string>  $periods  `Y-m` months for the trend, oldest first
     * @param  int|null  $carriedMinutes  the ledger's re-carried deferred balance, which termination
     *                                    bills on its own line; null where the ledger was not read
     * @param  CarbonImmutable|null  $from  the first day of the agreement's segment where a predecessor
     *                                      for the same scope owns the work before it
     */
    public static function calculate(
        ClientAgreement $agreement,
        CarbonImmutable $through,
        bool $projectChainsAgree,
        iterable $outstanding,
        iterable $history,
        array $periods,
        ?int $carriedMinutes = 0,
        ?CarbonImmutable $from = null,
    ): self {
        $currency = (string) $agreement->currency;
        if (! $projectChainsAgree) {
            return new self(
                through: $through->toDateString(),
                currency: $currency,
                issue: DeferredBacklogIssue::ProjectChainMismatch,
                issueDetail: null,
                minutes: null,
                entryCount: null,
                oldestWorkedOn: null,
                agreementRateMinutes: null,
                flatHourlyMinutes: null,
                amount: null,
                carriedMinutes: null,
                entryIds: [],
                trend: [],
            );
        }

        $entries = [];
        $minutes = 0;
        $oldest = null;
        foreach ($outstanding as $entry) {
            if (! self::inSegment($entry, $from, $through)) {
                continue;
            }
            $entries[] = $entry;
            $minutes += $entry->minutes_worked;
            $workedOn = $entry->worked_on->toDateString();
            $oldest = $oldest === null ? $workedOn : min($oldest, $workedOn);
        }

        $issue = null;
        $detail = null;
        $amount = null;
        $agreementRateMinutes = null;
        $flatHourlyMinutes = null;
        try {
            $pricing = DeferredTerminationPricing::price($entries, $agreement, $currency, 'the termination invoice');
            $amount = MoneyService::sum($pricing->totalAmount(), DeferredTerminationPricing::carriedTotal($carriedMinutes ?? 0, $agreement));
            $agreementRateMinutes = $pricing->agreementRateMinutes;
            $flatHourlyMinutes = $pricing->flatHourlyMinutes();
        } catch (DomainException|RuntimeException|InvalidArgumentException $refused) {
            // The invoice would refuse the same entries. The hours stand; the
            // amount is withheld rather than guessed at.
            $issue = DeferredBacklogIssue::Unpriced;
            $detail = $refused->getMessage();
        }

        return new self(
            through: $through->toDateString(),
            currency: $currency,
            issue: $issue,
            issueDetail: $detail,
            minutes: $minutes,
            entryCount: count($entries),
            oldestWorkedOn: $oldest,
            agreementRateMinutes: $agreementRateMinutes,
            flatHourlyMinutes: $flatHourlyMinutes,
            amount: $amount,
            carriedMinutes: $carriedMinutes,
            entryIds: array_map(static fn (ClientTimeEntry $entry): int => $entry->id, $entries),
            trend: self::trend($history, $periods, $from, $through),
        );
    }

    /**
     * @param  iterable<ClientTimeEntry>  $history
     * @param  list<string>  $periods
     * @return list<DeferredBacklogMonth>
     */
    private static function trend(iterable $history, array $periods, ?CarbonImmutable $from, CarbonImmutable $through): array
    {
        /** @var array<string, array{0:int,1:int,2:int}> $months */
        $months = array_fill_keys($periods, [0, 0, 0]);
        foreach ($history as $entry) {
            if (! self::inSegment($entry, $from, $through)) {
                continue;
            }
            $added = $entry->worked_on->format('Y-m');
            if (isset($months[$added])) {
                $months[$added][0] += $entry->minutes_worked;
            }

            $left = match ($entry->deferredDisposition()) {
                DeferredWorkDisposition::Waiting => null,
                // Only agreement-rate work draws on a pool. A flat-hourly entry
                // on its own subcontractor line was billed at its snapshot.
                DeferredWorkDisposition::RetainerApplied => $entry->isAgreementRateBillable()
                    ? [1, $entry->capacityDate()]
                    : [2, $entry->allocatedOn()],
                DeferredWorkDisposition::BilledOnTermination,
                DeferredWorkDisposition::SettledOutsideRetainer => [2, $entry->allocatedOn()],
            };
            if ($left === null) {
                continue;
            }
            [$column, $on] = $left;
            if ($on->gt($through)) {
                continue;
            }
            $period = $on->format('Y-m');
            if (isset($months[$period])) {
                $months[$period][$column] += $entry->minutes_worked;
            }
        }

        $trend = [];
        foreach ($periods as $period) {
            [$added, $applied, $billed] = $months[$period];
            $trend[] = new DeferredBacklogMonth($period, $added, $applied, $billed);
        }

        return $trend;
    }

    /** Every hour termination would bill: the shelf and the re-carried balance. */
    public function hours(): ?float
    {
        return $this->minutes === null ? null : self::hoursOf($this->minutes + ($this->carriedMinutes ?? 0));
    }

    public function carriedHours(): ?float
    {
        return $this->carriedMinutes === null ? null : self::hoursOf($this->carriedMinutes);
    }

    public function agreementRateHours(): ?float
    {
        return $this->agreementRateMinutes === null ? null : self::hoursOf($this->agreementRateMinutes);
    }

    public function flatHourlyHours(): ?float
    {
        return $this->flatHourlyMinutes === null ? null : self::hoursOf($this->flatHourlyMinutes);
    }

    /** Average net growth of the shelf per trend month; null without a trend. */
    public function netHoursPerMonth(): ?float
    {
        if ($this->trend === []) {
            return null;
        }
        $net = 0;
        foreach ($this->trend as $month) {
            $net += $month->netMinutes();
        }

        return round($net / 60 / count($this->trend), 4);
    }

    public function hasBacklog(): bool
    {
        // A withheld backlog states no count, and is listed so it gets corrected.
        return $this->entryCount === null || $this->entryCount > 0 || $this->carriedMinutes > 0;
    }

    private static function inSegment(ClientTimeEntry $entry, ?CarbonImmutable $from, CarbonImmutable $through): bool
    {
        return ! $entry->worked_on->gt($through) && ! ($from instanceof CarbonImmutable && $entry->worked_on->lt($from));
    }

    private static function hoursOf(int $minutes): float
    {
        return round($minutes / 60, 4);
    }
}
