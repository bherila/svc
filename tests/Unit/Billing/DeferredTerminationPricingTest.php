<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use App\Services\Billing\DeferredBacklogReadService;
use App\Services\Billing\MoneyService;
use App\Support\Billing\DeferredTerminationPricing;
use App\Support\Billing\FlatHourlyPricing;
use Carbon\CarbonImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * The termination invoice's price for deferred work, without a database.
 *
 * The invoice composer writes its lines from this and the deferred backlog
 * quotes it, so the rules are pinned once, here.
 */
#[CoversClass(ClientAgreement::class)]
#[CoversClass(ClientTimeEntry::class)]
#[CoversClass(ClosingBalance::class)]
#[CoversClass(MonthSummary::class)]
#[CoversClass(OpeningBalance::class)]
#[CoversClass(DeferredBacklogReadService::class)]
#[CoversClass(MoneyService::class)]
#[CoversClass(DeferredTerminationPricing::class)]
#[CoversClass(FlatHourlyPricing::class)]
final class DeferredTerminationPricingTest extends TestCase
{
    public function test_agreement_rate_work_is_priced_together_at_the_agreement_rate(): void
    {
        // Three 20-minute entries at 100.01/h: pooled, one hour is 10001;
        // priced one by one, each would round to 3334 and total 10002.
        $pricing = DeferredTerminationPricing::price([
            $this->entry(1, 20),
            $this->entry(2, 20, mode: 'retainer', rate: 99999),
            $this->entry(3, 20),
        ], $this->agreement(10001), 'USD', 'invoice SVC-TEST-1');

        $this->assertSame([1, 2, 3], array_map(static fn (ClientTimeEntry $entry): int => $entry->id, $pricing->agreementRateEntries));
        $this->assertSame(60, $pricing->agreementRateMinutes);
        $this->assertSame(1.0, $pricing->agreementRateHours());
        $this->assertSame(10001, $pricing->agreementRateUnitAmount);
        $this->assertSame(10001, $pricing->agreementRateTotal);
        $this->assertSame([], $pricing->flatHourlyGroups);
        $this->assertSame(10001, $pricing->totalAmount());
        $this->assertSame('USD', $pricing->currency);
    }

    public function test_flat_hourly_work_keeps_its_snapshot_one_group_per_person_project_and_rate(): void
    {
        $pricing = DeferredTerminationPricing::price([
            $this->entry(1, 60, mode: 'flat_hourly', cost: 5000),
            $this->entry(2, 30, mode: 'flat_hourly', cost: 5000),
            $this->entry(3, 60, mode: 'flat_hourly', cost: 6000),
            $this->entry(4, 60, mode: 'flat_hourly', cost: 5000, userId: 8),
            $this->entry(5, 60, mode: 'flat_hourly', cost: 5000, projectId: 12),
            $this->entry(6, 90),
        ], $this->agreement(10000), 'USD', 'invoice SVC-TEST-2');

        $groups = array_map(static fn ($group): array => [
            array_map(static fn (ClientTimeEntry $entry): int => $entry->id, $group->entries),
            $group->minutes,
            $group->unitAmount,
            $group->totalAmount,
            $group->currency,
            $group->sample()->id,
            $group->hours(),
        ], $pricing->flatHourlyGroups);

        $this->assertSame([
            [[1, 2], 90, 5000, 7500, 'USD', 1, 1.5],
            [[3], 60, 6000, 6000, 'USD', 3, 1.0],
            [[4], 60, 5000, 5000, 'USD', 4, 1.0],
            [[5], 60, 5000, 5000, 'USD', 5, 1.0],
        ], $groups);
        $this->assertSame(270, $pricing->flatHourlyMinutes());
        $this->assertSame(15000, $pricing->agreementRateTotal);
        $this->assertSame(15000 + 7500 + 6000 + 5000 + 5000, $pricing->totalAmount());
    }

    public function test_direct_work_is_the_subcontractors_to_bill(): void
    {
        $pricing = DeferredTerminationPricing::price([
            $this->entry(1, 60, mode: 'direct', cost: 5000),
        ], $this->agreement(null), 'USD', 'invoice SVC-TEST-3');

        $this->assertSame([], $pricing->agreementRateEntries);
        $this->assertSame([], $pricing->flatHourlyGroups);
        $this->assertNull($pricing->agreementRateUnitAmount);
        $this->assertSame(0, $pricing->totalAmount());
    }

    public function test_an_unstated_agreement_rate_refuses_only_when_agreement_rate_work_needs_it(): void
    {
        $flatOnly = DeferredTerminationPricing::price([
            $this->entry(1, 60, mode: 'flat_hourly', cost: 5000),
        ], $this->agreement(null), 'USD', 'invoice SVC-TEST-4');
        $this->assertSame(5000, $flatOnly->totalAmount());
        $this->assertNull($flatOnly->agreementRateUnitAmount);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('states no hourly rate');

        DeferredTerminationPricing::price([$this->entry(2, 60)], $this->agreement(null), 'USD', 'invoice SVC-TEST-4');
    }

    public function test_hours_are_stated_to_four_places_as_the_ledger_states_them(): void
    {
        $pricing = DeferredTerminationPricing::price([
            $this->entry(1, 1),
            $this->entry(2, 1, mode: 'flat_hourly', cost: 6000),
        ], $this->agreement(6000), 'USD', 'invoice SVC-TEST-11');

        $this->assertSame(0.0167, $pricing->agreementRateHours());
        $this->assertSame(0.0167, $pricing->flatHourlyGroups[0]->hours());
        $this->assertSame(100 + 100, $pricing->totalAmount());
    }

    public function test_flat_hourly_work_without_a_currency_refuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a snapshotted amount and currency');

        FlatHourlyPricing::groups([
            (new ClientTimeEntry)->setRawAttributes([
                'id' => 1, 'user_id' => 7, 'client_project_id' => 11, 'minutes' => 60,
                'subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000,
                'subcontractor_cost_currency' => null,
            ], true),
        ], 'USD', 'invoice SVC-TEST-12');
    }

    public function test_the_recarried_balance_is_priced_at_the_agreement_rate_on_its_own(): void
    {
        $this->assertSame(0, DeferredTerminationPricing::carriedTotal(0, $this->agreement(null)));
        $this->assertSame(0, DeferredTerminationPricing::carriedTotal(-5, $this->agreement(null)));
        $this->assertSame(10001, DeferredTerminationPricing::carriedTotal(60, $this->agreement(10001)));
        $this->assertSame(167, DeferredTerminationPricing::carriedTotal(1, $this->agreement(10001)));

        $this->expectException(DomainException::class);
        DeferredTerminationPricing::carriedTotal(1, $this->agreement(null));
    }

    public function test_the_ledger_closing_balance_is_what_is_carried(): void
    {
        $month = static fn (string $period, float $carried): MonthSummary => new MonthSummary(
            new OpeningBalance(10, 0, 0, 10, 0, 0, 10, 0),
            new ClosingBalance(0, 0, 10, 0, 0, 0),
            hoursWorked: 0,
            yearMonth: $period,
            retainerHours: 10,
            recarriedDeferredHours: $carried,
        );

        $march = CarbonImmutable::parse('2026-03-15');
        $ledger = [$month('2026-02', 3.0), $month('2026-03', 0.75), $month('2026-04', 9.0)];

        $this->assertSame(0, DeferredBacklogReadService::carriedMinutesOf([], $march));
        // The month the backlog's last day falls in, never a later one.
        $this->assertSame(45, DeferredBacklogReadService::carriedMinutesOf($ledger, $march));
        $this->assertSame(180, DeferredBacklogReadService::carriedMinutesOf($ledger, CarbonImmutable::parse('2026-02-28')));
        $this->assertSame(540, DeferredBacklogReadService::carriedMinutesOf($ledger, CarbonImmutable::parse('2026-05-01')));
        $this->assertSame(0, DeferredBacklogReadService::carriedMinutesOf($ledger, CarbonImmutable::parse('2026-01-31')));
        $this->assertSame(1, DeferredBacklogReadService::carriedMinutesOf([$month('2026-03', 0.0084)], $march));
        // To the nearest minute, as termination carries it: neither up nor down.
        $this->assertSame(0, DeferredBacklogReadService::carriedMinutesOf([$month('2026-03', 0.0081)], $march));
        $this->assertSame(1, DeferredBacklogReadService::carriedMinutesOf([$month('2026-03', 0.0166)], $march));
    }

    public function test_lines_that_together_exceed_the_integer_range_refuse_rather_than_becoming_a_float(): void
    {
        $rate = intdiv(PHP_INT_MAX, 2) + 1;
        $pricing = DeferredTerminationPricing::price([
            $this->entry(1, 60, mode: 'flat_hourly', cost: $rate),
            $this->entry(2, 60, mode: 'flat_hourly', cost: $rate, userId: 8),
        ], $this->agreement(null), 'USD', 'invoice SVC-TEST-13');

        $this->assertSame(1, MoneyService::sum(1));
        $this->assertSame(0, MoneyService::sum());
        $this->assertSame(PHP_INT_MAX, MoneyService::sum(PHP_INT_MAX - 2, 1, 1));

        $this->expectException(InvalidArgumentException::class);
        $pricing->totalAmount();
    }

    public function test_a_zero_rate_is_a_stated_rate(): void
    {
        $pricing = DeferredTerminationPricing::price([$this->entry(1, 60)], $this->agreement(0), 'USD', 'invoice SVC-TEST-5');

        $this->assertSame(0, $pricing->agreementRateUnitAmount);
        $this->assertSame(0, $pricing->totalAmount());
    }

    public function test_it_refuses_an_unknown_billing_mode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported subcontractor billing mode');

        DeferredTerminationPricing::price([$this->entry(1, 60, mode: 'synthetic-unknown')], $this->agreement(10000), 'USD', 'invoice SVC-TEST-6');
    }

    public function test_it_refuses_a_cost_without_a_billing_mode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('subcontractor cost without a billing mode');

        DeferredTerminationPricing::price([$this->entry(1, 60, cost: 5000)], $this->agreement(10000), 'USD', 'invoice SVC-TEST-7');
    }

    public function test_flat_hourly_work_without_a_snapshot_refuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a snapshotted amount and currency');

        FlatHourlyPricing::groups([$this->entry(1, 60, mode: 'flat_hourly', cost: 5000, costCurrency: ' ')], 'USD', 'invoice SVC-TEST-8');
    }

    public function test_flat_hourly_work_without_a_cost_refuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a snapshotted amount and currency');

        FlatHourlyPricing::groups([$this->entry(1, 60, mode: 'flat_hourly')], 'USD', 'invoice SVC-TEST-9');
    }

    public function test_flat_hourly_work_in_another_currency_refuses_by_the_label_it_was_given(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Subcontractor time is costed in EUR but the termination invoice is in USD; convert it before billing.');

        FlatHourlyPricing::groups([
            $this->entry(1, 60, mode: 'flat_hourly', cost: 5000),
            $this->entry(2, 60, mode: 'flat_hourly', cost: 5000, costCurrency: 'EUR'),
        ], 'USD', 'the termination invoice');
    }

    public function test_a_flat_hourly_group_of_no_minutes_is_not_a_line(): void
    {
        $this->assertSame([], FlatHourlyPricing::groups([
            $this->entry(1, 0, mode: 'flat_hourly', cost: 5000),
        ], 'USD', 'invoice SVC-TEST-10'));
    }

    private function agreement(?int $rate): ClientAgreement
    {
        return (new ClientAgreement)->setRawAttributes([
            'public_id' => '00000000-0000-4000-8000-000000000366',
            'hourly_rate_amount' => $rate,
            'currency' => 'USD',
        ], true);
    }

    private function entry(
        int $id,
        int $minutes,
        ?string $mode = null,
        ?int $cost = null,
        ?string $costCurrency = 'USD',
        int $rate = 10000,
        int $userId = 7,
        int $projectId = 11,
    ): ClientTimeEntry {
        return (new ClientTimeEntry)->setRawAttributes([
            'id' => $id,
            'user_id' => $userId,
            'client_project_id' => $projectId,
            'worked_on' => '2026-03-01',
            'minutes' => $minutes,
            'status' => 'approved',
            'is_billable' => true,
            'is_deferred' => true,
            'billing_rate_amount' => $rate,
            'currency' => 'USD',
            'subcontractor_billing_mode' => $mode,
            'subcontractor_cost_amount' => $cost,
            'subcontractor_cost_currency' => $cost === null ? null : $costCurrency,
        ], true);
    }
}
