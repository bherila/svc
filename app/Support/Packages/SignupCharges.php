<?php

namespace App\Support\Packages;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\RecurringItemBiller;
use App\Services\Billing\RetainerCalculator;
use App\Services\Packages\PackageAgreementTerms;
use App\Support\Billing\BillingCadence;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * What sign-up charges, line by line: the "Due now" a client reads before
 * accepting (ACC-3, EDGE-6).
 *
 * An estimate, made with the billing engine's own pure parts on an agreement
 * that is never saved: the cycle the engine bills first
 * ({@see BillingCycleResolver}), the retainer's opening-month proration by the
 * agreement's `first_cycle_proration` ({@see RetainerCalculator}), and each
 * recurring item's incidences in that cycle ({@see RecurringItemBiller}). The
 * invoice acceptance issues is the engine's own, and once it exists the page
 * shows its lines instead; the golden tests hold the two to the same totals.
 * No database, so this is tested without one.
 */
final readonly class SignupCharges
{
    /** @param list<SignupChargeLine> $lines */
    public function __construct(public string $currency, public array $lines) {}

    public static function estimate(PackageTerms $terms, CarbonImmutable $startsOn): self
    {
        $lines = [];
        if ($terms->chargeSetupFeeAtSignup) {
            foreach ($terms->components as $component) {
                if ($component->kind === PackageComponentKind::SetupFee) {
                    $lines[] = new SignupChargeLine(SignupChargeKind::SetupFee, $component->description, $component->total(), null, null);
                }
            }
        }
        if ($terms->chargeFirstPeriod && $terms->isRecurring()) {
            $lines[] = self::firstPeriod($terms, $startsOn);
        }

        return new self($terms->currency, $lines);
    }

    public function total(): int
    {
        return array_sum(array_map(static fn (SignupChargeLine $line): int => $line->amount, $this->lines));
    }

    /** @return array{currency: string, lines: list<array{kind: string, description: string, amount: int, period_start: string|null, period_end: string|null}>, total: int} */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'lines' => array_map(static fn (SignupChargeLine $line): array => $line->toArray(), $this->lines),
            'total' => $this->total(),
        ];
    }

    /**
     * The first billing period, as the engine bills it: for a monthly
     * agreement the calendar month the start falls in, its retainer prorated
     * from the start; for a longer cadence the cycle anchored on the start.
     */
    private static function firstPeriod(PackageTerms $terms, CarbonImmutable $startsOn): SignupChargeLine
    {
        $agreement = new ClientAgreement;
        $agreement->forceFill([...PackageAgreementTerms::agreementTerms($terms), 'starts_on' => $startsOn->toDateString()]);
        $items = [];
        foreach (PackageAgreementTerms::recurringItems($terms) as $index => $item) {
            $items[] = (new ClientAgreementRecurringItem)->forceFill([...$item, 'currency' => $terms->currency, 'sort_order' => $index, 'is_active' => true]);
        }
        $agreement->setRelation('recurringItems', new Collection($items));

        $resolver = new BillingCycleResolver;
        $start = Carbon::instance($startsOn)->startOfDay();
        $cycle = $resolver->cycleContaining($agreement, $start);
        $cycleStart = $cycle->start->copy()->startOfDay();
        $cycleEnd = $cycle->end->copy()->startOfDay();

        $biller = new RecurringItemBiller;
        $amount = 0;
        foreach ($biller->linesForCycle($agreement, $cycleStart, $cycleEnd) as $lineData) {
            $amount += (int) $biller->buildLine($lineData)->getAttribute('total_amount');
        }

        $calculator = new RetainerCalculator($resolver);
        $multiplier = $agreement->effectiveBillingCadence() === BillingCadence::Monthly
            ? $calculator->monthRetainerMultiplier($agreement, $cycleStart, $cycleEnd)
            : $calculator->cyclePeriodRetainerMultiplier($agreement, $cycle);
        $amount += (int) round(round($agreement->periodRetainerFee() * $multiplier, 2) * 100);

        return new SignupChargeLine(
            SignupChargeKind::FirstPeriod,
            $terms->name,
            $amount,
            ($start->gt($cycleStart) ? $start : $cycleStart)->toDateString(),
            $cycleEnd->toDateString(),
        );
    }
}
