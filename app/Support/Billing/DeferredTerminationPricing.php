<?php

namespace App\Support\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientTimeEntry;
use App\Services\Billing\MoneyService;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * What a termination invoice charges for a set of outstanding deferred work.
 *
 * Database-free, and the one place the rule lives: the termination invoice
 * writes its lines from this, and the deferred-backlog read model quotes this,
 * so the amount a reader is shown before termination is the amount the final
 * invoice charges (#366). Consultant and retainer-mode minutes are priced
 * together at the agreement's hourly rate; flat-hourly minutes keep the rate
 * snapshotted on each entry, one group per person, project and rate; direct
 * work is the subcontractor's to bill and is left out.
 */
final readonly class DeferredTerminationPricing
{
    /**
     * @param  list<ClientTimeEntry>  $agreementRateEntries
     * @param  list<FlatHourlyGroup>  $flatHourlyGroups
     */
    private function __construct(
        public string $currency,
        public array $agreementRateEntries,
        public int $agreementRateMinutes,
        public ?int $agreementRateUnitAmount,
        public int $agreementRateTotal,
        public array $flatHourlyGroups,
    ) {}

    /**
     * @param  iterable<ClientTimeEntry>  $entries
     *
     * @throws RuntimeException when an entry's billing mode or flat-hourly snapshot cannot be priced
     * @throws DomainException when agreement-rate work meets an agreement that states no hourly rate
     */
    public static function price(iterable $entries, ClientAgreement $agreement, string $currency, string $invoiceLabel): self
    {
        $agreementRate = [];
        $flatHourly = [];
        $agreementRateMinutes = 0;

        foreach ($entries as $entry) {
            $rawMode = $entry->getRawOriginal('subcontractor_billing_mode');
            $mode = is_string($rawMode) ? SubcontractorBillingMode::tryFrom($rawMode) : null;

            if ($rawMode !== null && ! $mode instanceof SubcontractorBillingMode) {
                throw new RuntimeException('Deferred time has an unsupported subcontractor billing mode.');
            }
            if ($mode === SubcontractorBillingMode::Direct) {
                continue;
            }
            if ($mode === SubcontractorBillingMode::FlatHourly) {
                $flatHourly[] = $entry;

                continue;
            }
            if ($mode === null && $entry->subcontractor_cost_amount !== null) {
                throw new RuntimeException('Deferred time has a subcontractor cost without a billing mode.');
            }

            $agreementRate[] = $entry;
            $agreementRateMinutes += $entry->minutes_worked;
        }

        $unitAmount = null;
        $agreementRateTotal = 0;
        if ($agreementRateMinutes > 0) {
            // A null rate refuses rather than billing at zero; see
            // {@see ClientAgreement::hourlyRateAmountOrFail()}.
            $unitAmount = $agreement->hourlyRateAmountOrFail();
            $agreementRateTotal = MoneyService::hourlyAmount($agreementRateMinutes, $unitAmount);
        }

        return new self(
            currency: $currency,
            agreementRateEntries: $agreementRate,
            agreementRateMinutes: $agreementRateMinutes,
            agreementRateUnitAmount: $unitAmount,
            agreementRateTotal: $agreementRateTotal,
            flatHourlyGroups: FlatHourlyPricing::groups($flatHourly, $currency, $invoiceLabel),
        );
    }

    /**
     * The termination charge for the ledger's re-carried deferred balance,
     * which is billed on its own line at the agreement's rate
     * ({@see CarriedDeferredLine::BilledOnTermination}).
     *
     * @throws DomainException when there is a balance and the agreement states no hourly rate
     */
    public static function carriedTotal(int $minutes, ClientAgreement $agreement): int
    {
        if ($minutes <= 0) {
            return 0;
        }

        return MoneyService::hourlyAmount($minutes, $agreement->hourlyRateAmountOrFail());
    }

    public function agreementRateHours(): float
    {
        return round($this->agreementRateMinutes / 60, 4);
    }

    public function flatHourlyMinutes(): int
    {
        $minutes = 0;
        foreach ($this->flatHourlyGroups as $group) {
            $minutes += $group->minutes;
        }

        return $minutes;
    }

    /**
     * Every line's total, in minor units of {@see $currency}.
     *
     * @throws InvalidArgumentException when the lines together exceed the integer range
     */
    public function totalAmount(): int
    {
        return MoneyService::sum(
            $this->agreementRateTotal,
            ...array_map(static fn (FlatHourlyGroup $group): int => $group->totalAmount, $this->flatHourlyGroups),
        );
    }
}
