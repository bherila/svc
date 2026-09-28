<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\CapacityLedgerInputs;
use App\Services\Billing\RolloverCalculator;
use App\Support\Billing\CarriedDeferredLine;
use App\Support\Billing\InvoiceLineType;
use Tests\TestCase;

/**
 * Deferred work draws only on free capacity; what an invoice applied beyond
 * it is carried forward again as a quantity, and later lines settle it.
 */
final class RecarriedDeferredLedgerTest extends TestCase
{
    public function test_deferred_work_applied_beyond_free_capacity_is_carried_not_owed(): void
    {
        // 1 hour of April debt reaches May; May's ordinary quarter-hour leaves
        // 8.75 free, and an invoice applied 10 deferred hours there.
        [$april, $may] = (new RolloverCalculator)->calculateMultipleMonths([
            ['year_month' => '2026-04', 'retainer_hours' => 10.0, 'hours_worked' => 11.0],
            ['year_month' => '2026-05', 'retainer_hours' => 10.0, 'hours_worked' => 0.25, 'deferred_hours' => 10.0],
        ], 1);

        $this->assertSame(1.0, $april->closing->negativeBalance);
        $this->assertSame(0.0, $may->closing->negativeBalance, 'Deferred work never becomes debt');
        $this->assertSame(0.0, $may->closing->unusedHours);
        $this->assertSame(9.0, $may->hoursWorked);
        $this->assertSame(1.25, $may->recarriedDeferredHours);
        $this->assertSame(0.0, $april->recarriedDeferredHours);
    }

    public function test_later_lines_settle_the_carried_hours_only_from_what_is_free(): void
    {
        $months = (new RolloverCalculator)->calculateMultipleMonths([
            ['year_month' => '2026-05', 'retainer_hours' => 10.0, 'hours_worked' => 8.0, 'deferred_hours' => 5.0],
            // A line applied 2.5 carried hours, but only 1 was free: 1.5 return.
            ['year_month' => '2026-06', 'retainer_hours' => 10.0, 'hours_worked' => 9.0, 'carried_deferred_hours' => 2.5],
            ['year_month' => '2026-07', 'retainer_hours' => 10.0, 'hours_worked' => 0.0, 'carried_deferred_hours' => 1.5],
            ['year_month' => '2026-08', 'retainer_hours' => 0.0, 'hours_worked' => 0.0, 'carried_deferred_billed_hours' => 0.5],
        ], 0);

        // 3 carried from May; June applies 2.5 of it but only 1 was free, so
        // 1.5 returns (3 - 2.5 + 1.5 = 2); July applies 1.5; August bills 0.5.
        $this->assertSame([3.0, 2.0, 0.5, 0.0], array_map(fn ($month): float => $month->recarriedDeferredHours, $months));
        $this->assertSame(10.0, $months[1]->hoursWorked);
        $this->assertSame(1.5, $months[2]->hoursWorked);
        $this->assertSame(0.0, $months[1]->closing->negativeBalance);
        $this->assertSame(0.0, $months[3]->hoursWorked, 'Billed at rate on termination, not drawn');
    }

    public function test_nothing_is_drawn_for_deferred_work_when_ordinary_work_already_exceeds_the_pool(): void
    {
        [$month] = (new RolloverCalculator)->calculateMultipleMonths([
            ['year_month' => '2026-05', 'retainer_hours' => 10.0, 'hours_worked' => 12.0, 'deferred_hours' => 2.0],
        ], 1);

        $this->assertSame(12.0, $month->hoursWorked);
        $this->assertSame(2.0, $month->closing->negativeBalance, 'Only ordinary work is owed');
        $this->assertSame(2.0, $month->recarriedDeferredHours);
    }

    public function test_a_summary_carries_nothing_unless_told(): void
    {
        [$month] = (new RolloverCalculator)->calculateMultipleMonths([
            ['year_month' => '2026-05', 'retainer_hours' => 10.0, 'hours_worked' => 4.0],
        ], 1);
        $plain = new MonthSummary($month->opening, $month->closing, 4.0, '2026-05', 10.0);

        $this->assertSame(0.0, $month->recarriedDeferredHours);
        $this->assertSame(0.0, $plain->recarriedDeferredHours);
    }

    public function test_a_month_row_reads_its_own_hours_and_zero_for_a_month_with_none(): void
    {
        $byMonth = ['2026-05' => ['ordinary' => 2.0, 'deferred' => 1.5, 'carried' => 0.75, 'carried_billed' => 0.25]];

        $this->assertSame(
            ['hours_worked' => 2.0, 'deferred_hours' => 1.5, 'carried_deferred_hours' => 0.75, 'carried_deferred_billed_hours' => 0.25],
            CapacityLedgerInputs::monthRow($byMonth, '2026-05'),
        );
        $this->assertSame(
            ['hours_worked' => 0.0, 'deferred_hours' => 0.0, 'carried_deferred_hours' => 0.75, 'carried_deferred_billed_hours' => 0.25],
            CapacityLedgerInputs::monthRow($byMonth, '2026-05', countsWork: false),
        );
        $this->assertSame(
            ['hours_worked' => 0.0, 'deferred_hours' => 0.0, 'carried_deferred_hours' => 0.0, 'carried_deferred_billed_hours' => 0.0],
            CapacityLedgerInputs::monthRow($byMonth, '2026-06'),
        );
    }

    public function test_lines_of_one_month_add_up_and_are_held_to_four_places(): void
    {
        $third = 1 / 3;
        $inputs = CapacityLedgerInputs::fold(
            [
                ['month' => '2026-05', 'hours' => $third, 'deferred' => false],
                ['month' => '2026-05', 'hours' => $third, 'deferred' => false],
            ],
            [
                ['month' => '2026-05', 'hours' => $third, 'kind' => CarriedDeferredLine::Applied],
                ['month' => '2026-05', 'hours' => $third, 'kind' => CarriedDeferredLine::Applied],
                ['month' => '2026-05', 'hours' => 0.5, 'kind' => CarriedDeferredLine::BilledOnTermination],
                ['month' => '2026-05', 'hours' => 0.25, 'kind' => CarriedDeferredLine::BilledOnTermination],
            ],
        );

        $this->assertSame(['ordinary' => 0.6667, 'deferred' => 0.0, 'carried' => 0.6667, 'carried_billed' => 0.75], $inputs['2026-05']);
    }

    public function test_the_carried_lines_are_recognised_by_their_system_only_type_alone(): void
    {
        $this->assertSame('Carried deferred work applied to retainer (2:30)', CarriedDeferredLine::Applied->describe(2.5));
        $this->assertSame(CarriedDeferredLine::Applied, CarriedDeferredLine::of('carried_deferred_applied'));
        $this->assertSame(CarriedDeferredLine::BilledOnTermination, CarriedDeferredLine::of('carried_deferred_billed'));
        $this->assertSame(InvoiceLineType::CarriedDeferredApplied, CarriedDeferredLine::Applied->lineType());
        $this->assertSame(InvoiceLineType::CarriedDeferredBilled, CarriedDeferredLine::BilledOnTermination->lineType());
        // The wording is display text: an ordinary type with the same words is not one.
        $this->assertNull(CarriedDeferredLine::of('prior_month_retainer'));
        $this->assertNull(CarriedDeferredLine::of('additional_hours'));
        $this->assertSame(['carried_deferred_applied', 'carried_deferred_billed'], InvoiceLineType::systemOnlyValues());
        foreach (InvoiceLineType::systemOnlyValues() as $type) {
            $this->assertContains($type, InvoiceLineType::systemGeneratedValues(), 'Released when a draft is rebuilt');
            $this->assertContains($type, InvoiceLineType::definingTheWorkPeriod());
        }
    }

    public function test_the_inputs_split_ordinary_deferred_and_carried_hours_by_month(): void
    {
        $inputs = CapacityLedgerInputs::fold(
            [
                ['month' => '2026-05', 'hours' => 1.5, 'deferred' => false],
                ['month' => '2026-05', 'hours' => 2.25, 'deferred' => true],
                ['month' => '2026-05', 'hours' => 0.5, 'deferred' => false],
                ['month' => '2026-06', 'hours' => 1.0, 'deferred' => true],
            ],
            [
                ['month' => '2026-06', 'hours' => 0.75, 'kind' => CarriedDeferredLine::Applied],
                ['month' => '2026-06', 'hours' => 9.0, 'kind' => null],
                ['month' => '2026-07', 'hours' => 1.25, 'kind' => CarriedDeferredLine::BilledOnTermination],
            ],
        );

        $this->assertSame([
            '2026-05' => ['ordinary' => 2.0, 'deferred' => 2.25, 'carried' => 0.0, 'carried_billed' => 0.0],
            '2026-06' => ['ordinary' => 0.0, 'deferred' => 1.0, 'carried' => 0.75, 'carried_billed' => 0.0],
            '2026-07' => ['ordinary' => 0.0, 'deferred' => 0.0, 'carried' => 0.0, 'carried_billed' => 1.25],
        ], $inputs);
    }
}
