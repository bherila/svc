<?php

namespace Tests\Unit\Packages;

use App\Support\Billing\BillingCadence;
use App\Support\Packages\Migration\ContinuedRecurrence;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * The charge schedule a move's successor continues (docs/service-packages.md,
 * slice 6, MIG-3): read from the old agreement's items as the billing engine
 * reads them, refused when they share none.
 */
final class ContinuedRecurrenceTest extends TestCase
{
    /** @return iterable<string, array{BillingCadence, string, list<array{cadence: string, effective_on: string|null, anchor_day: int|null, anchor_month: int|null}>, array{effective_on: string, anchor_day: int, anchor_month: int|null}|null}> */
    public static function schedules(): iterable
    {
        $item = static fn (string $cadence, ?string $start = null, ?int $day = null, ?int $month = null): array => ['cadence' => $cadence, 'effective_on' => $start, 'anchor_day' => $day, 'anchor_month' => $month];

        yield 'a package item started with a quarterly agreement' => [BillingCadence::Quarterly, '2026-11-09', [$item('quarterly')], ['effective_on' => '2026-11-09', 'anchor_day' => 1, 'anchor_month' => 11]];
        yield 'no items: the schedule one started with the agreement would have' => [BillingCadence::Annual, '2026-03-31', [], ['effective_on' => '2026-03-31', 'anchor_day' => 1, 'anchor_month' => 3]];
        yield 'a monthly schedule names only its day' => [BillingCadence::Monthly, '2026-01-31', [$item('monthly'), $item('monthly')], ['effective_on' => '2026-01-31', 'anchor_day' => 1, 'anchor_month' => null]];
        yield 'an item already continued keeps its anchors' => [BillingCadence::SemiAnnual, '2027-05-09', [$item('semi_annual', '2026-11-09', 1, 11)], ['effective_on' => '2026-11-09', 'anchor_day' => 1, 'anchor_month' => 11]];
        yield 'two items in the same place in the cycle, the earliest start kept' => [BillingCadence::Quarterly, '2026-01-01', [$item('quarterly', '2026-04-01', 1, 4), $item('quarterly', null, 1, 1)], ['effective_on' => '2026-01-01', 'anchor_day' => 1, 'anchor_month' => 4]];
        yield 'an anchor past the 28th is the 28th, as the engine bills it' => [BillingCadence::Monthly, '2026-01-01', [$item('monthly', null, 31), $item('monthly', null, 28)], ['effective_on' => '2026-01-01', 'anchor_day' => 28, 'anchor_month' => null]];
        yield 'items on different days' => [BillingCadence::Monthly, '2026-01-01', [$item('monthly'), $item('monthly', null, 15)], null];
        yield 'items in different places in the cycle' => [BillingCadence::Quarterly, '2026-01-01', [$item('quarterly'), $item('quarterly', '2026-02-01')], null];
        yield 'an item off the cycle' => [BillingCadence::Monthly, '2026-01-01', [$item('monthly'), $item('annual')], null];
        yield 'an item starting on or after the date' => [BillingCadence::Monthly, '2026-01-01', [$item('monthly', '2027-01-01')], null];
    }

    /**
     * @param  list<array{cadence: string, effective_on: string|null, anchor_day: int|null, anchor_month: int|null}>  $items
     * @param  array{effective_on: string, anchor_day: int, anchor_month: int|null}|null  $expected
     */
    #[DataProvider('schedules')]
    #[Requirement('MIG-3')]
    public function test_the_successor_continues_the_old_items_schedule_or_none(BillingCadence $cadence, string $startsOn, array $items, ?array $expected): void
    {
        $facts = array_map(static fn (array $item): array => [...$item, 'effective_on' => $item['effective_on'] === null ? null : CarbonImmutable::parse($item['effective_on'])], $items);
        $recurrence = ContinuedRecurrence::fromItems($cadence, CarbonImmutable::parse($startsOn), CarbonImmutable::parse('2027-01-01'), $facts);

        $this->assertSame($expected, $recurrence?->itemColumns());
    }
}
