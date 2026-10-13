<?php

namespace Tests\Unit\Square;

use App\Services\Square\Mirror\SquareCandidateScorer;
use PHPUnit\Framework\TestCase;

final class SquareCandidateScorerTest extends TestCase
{
    public function test_the_expected_annual_occurrence_is_suggested_with_explainable_points_capped_at_one_hundred(): void
    {
        $result = SquareCandidateScorer::rank($this->invoice(), 'cust_fixture_a', [$this->candidate()])[0];

        $this->assertSame(100, $result['score']);
        $this->assertTrue($result['suggested']);
        $this->assertSame(['amount' => 'exact', 'period_slot' => true, 'cadence' => true, 'due_within_7_days' => true, 'customer_mapped' => true], $result['signals']);
        $this->assertCount(5, $result['reasons']);
        $this->assertSame(0, $result['date_distance_days']);
        $this->assertFalse(SquareCandidateScorer::ambiguous([$result]));
    }

    public function test_a_skipped_month_and_price_change_do_not_make_the_previous_occurrence_win(): void
    {
        $invoice = $this->invoice(['cadence' => 'monthly', 'cycle_end' => '2040-01-31']);
        $previous = $this->candidate(['square_invoice_id' => 'inv_fixture_old', 'due_on' => '2039-11-15', 'series_cadence' => 'monthly']);
        $current = $this->candidate(['square_invoice_id' => 'inv_fixture_current', 'total_amount' => 20_200, 'series_cadence' => 'monthly']);

        $ranked = SquareCandidateScorer::rank($invoice, 'cust_fixture_a', [$previous, $current]);

        $this->assertSame(['inv_fixture_current', 'inv_fixture_old'], array_column($ranked, 'square_invoice_id'));
        $this->assertSame([85, 70], array_column($ranked, 'score'));
        $this->assertSame('within_1_percent', $ranked[0]['signals']['amount']);
        $this->assertFalse($ranked[1]['signals']['period_slot']);
        $this->assertFalse(SquareCandidateScorer::ambiguous($ranked));
    }

    public function test_seventy_is_the_suggestion_boundary_and_two_matching_series_are_ambiguous(): void
    {
        $invoice = $this->invoice(['cycle_start' => null, 'cycle_end' => null]);
        $one = $this->candidate(['due_on' => '2040-01-22']);
        $two = $this->candidate(['square_invoice_id' => 'inv_fixture_2', 'series_key' => '000002']);

        $ranked = SquareCandidateScorer::rank($invoice, null, [$one, $two]);

        $this->assertSame([70, 70], array_column($ranked, 'score'));
        $this->assertTrue($ranked[0]['suggested']);
        $this->assertTrue(SquareCandidateScorer::ambiguous($ranked));
        $below = SquareCandidateScorer::rank($invoice, null, [$this->candidate(['due_on' => '2040-01-23'])])[0];
        $this->assertSame(60, $below['score']);
        $this->assertFalse($below['suggested']);
    }

    public function test_status_only_breaks_a_tie_and_missing_dates_or_amounts_add_no_points(): void
    {
        $paid = $this->candidate(['square_invoice_id' => 'inv_fixture_paid', 'status' => 'PAID']);
        $open = $this->candidate(['square_invoice_id' => 'inv_fixture_open']);
        $ranked = SquareCandidateScorer::rank($this->invoice(), null, [$paid, $open]);
        $this->assertSame(['inv_fixture_open', 'inv_fixture_paid'], array_column($ranked, 'square_invoice_id'));
        $settled = SquareCandidateScorer::rank($this->invoice(['settled' => true]), null, [$open, $paid]);
        $this->assertSame('inv_fixture_paid', $settled[0]['square_invoice_id']);
        $unknown = SquareCandidateScorer::rank($this->invoice(), null, [$this->candidate([
            'total_amount' => null, 'due_on' => null, 'series_key' => null,
        ])])[0];
        $this->assertSame(0, $unknown['score']);
        $this->assertSame('unknown', $unknown['signals']['amount']);
        $this->assertNull($unknown['date_distance_days']);
    }

    public function test_equal_scores_use_date_distance_before_invoice_number_and_put_unknown_dates_last(): void
    {
        $invoice = $this->invoice(['due_date' => null, 'cycle_start' => null, 'cycle_end' => null]);
        $rows = [];
        foreach ([null, '2040-01-20', '2040-01-14', '2040-01-16', '2040-01-15'] as $index => $date) {
            $rows[] = $this->candidate(['square_invoice_id' => 'inv_fixture_distance_'.$index, 'due_on' => $date, 'invoice_number' => '000001-R-000'.($index + 1)]);
        }
        $ranked = SquareCandidateScorer::rank($invoice, null, $rows);
        $this->assertSame([60, 60, 60, 60, 60], array_column($ranked, 'score'));
        $this->assertSame(['inv_fixture_distance_4', 'inv_fixture_distance_2', 'inv_fixture_distance_3', 'inv_fixture_distance_1', 'inv_fixture_distance_0'], array_column($ranked, 'square_invoice_id'));
        $this->assertSame([0, 1, 1, 5, null], array_column($ranked, 'date_distance_days'));
        $this->assertFalse(SquareCandidateScorer::ambiguous($ranked));
    }

    public function test_equal_scores_prefer_an_exact_amount_before_status_or_invoice_number(): void
    {
        $invoice = $this->invoice(['due_date' => null, 'cycle_start' => null, 'cycle_end' => null, 'service_start' => '2040-01-01', 'service_end' => '2040-01-31']);
        $exact = $this->candidate(['square_invoice_id' => 'inv_fixture_exact', 'status' => 'PAID', 'series_cadence' => 'monthly', 'invoice_number' => '999999-R-0001']);
        $different = $this->candidate(['square_invoice_id' => 'inv_fixture_different', 'total_amount' => 40_000, 'sale_or_service_date' => '2040-01-15', 'square_customer_id' => 'cust_fixture_b']);
        $ranked = SquareCandidateScorer::rank($invoice, 'cust_fixture_a', [$different, $exact]);
        $this->assertSame([50, 50], array_column($ranked, 'score'));
        $this->assertSame(['inv_fixture_exact', 'inv_fixture_different'], array_column($ranked, 'square_invoice_id'));
        $this->assertSame(['exact', 'different'], array_column(array_column($ranked, 'signals'), 'amount'));
    }

    public function test_every_open_and_settled_status_can_break_an_otherwise_equal_tie(): void
    {
        foreach (['UNPAID', 'SCHEDULED', 'PARTIALLY_PAID', 'PAYMENT_PENDING'] as $status) {
            $ranked = SquareCandidateScorer::rank($this->invoice(), null, [
                $this->candidate(['square_invoice_id' => 'inv_fixture_settled', 'status' => 'PAID']),
                $this->candidate(['square_invoice_id' => 'inv_fixture_open', 'status' => $status, 'invoice_number' => '999999-R-0001']),
            ]);
            $this->assertSame('inv_fixture_open', $ranked[0]['square_invoice_id'], $status);
        }
        foreach (['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'] as $status) {
            $ranked = SquareCandidateScorer::rank($this->invoice(['settled' => true]), null, [
                $this->candidate(['square_invoice_id' => 'inv_fixture_open']),
                $this->candidate(['square_invoice_id' => 'inv_fixture_settled', 'status' => $status, 'invoice_number' => '999999-R-0001']),
            ]);
            $this->assertSame('inv_fixture_settled', $ranked[0]['square_invoice_id'], $status);
        }
    }

    public function test_due_dates_take_precedence_over_scheduling_and_service_slots_are_independent(): void
    {
        $due = SquareCandidateScorer::rank($this->invoice(), null, [$this->candidate(['scheduled_on' => '2039-12-15'])])[0];
        $this->assertSame(0, $due['date_distance_days']);
        $scheduled = SquareCandidateScorer::rank($this->invoice(), null, [$this->candidate(['due_on' => null, 'scheduled_on' => '2040-01-15'])])[0];
        $this->assertSame(90, $scheduled['score']);
        $this->assertSame(0, $scheduled['date_distance_days']);
        $this->assertFalse($scheduled['signals']['due_within_7_days']);
        $service = SquareCandidateScorer::rank($this->invoice(['cycle_start' => null, 'cycle_end' => null, 'service_start' => '2040-01-01', 'service_end' => '2040-01-31']), null,
            [$this->candidate(['due_on' => null, 'sale_or_service_date' => '2040-01-31'])])[0];
        $this->assertTrue($service['signals']['period_slot']);
        $this->assertSame(90, $service['score']);
    }

    public function test_amount_tolerance_boundaries_are_symmetric_and_zero_is_not_unknown(): void
    {
        foreach ([[20_000, 20_000, 'exact', 40], [20_000, 20_200, 'within_1_percent', 15], [20_000, 20_201, 'different', 0],
            [20_000, 19_800, 'within_1_percent', 15], [20_000, 19_799, 'different', 0], [-20_000, -20_200, 'within_1_percent', 15], [0, 0, 'exact', 40], [0, 1, 'different', 0]] as [$expected, $actual, $signal, $points]) {
            $ranked = SquareCandidateScorer::rank($this->invoice(['total_amount' => $expected, 'due_date' => null, 'reference_date' => null, 'cycle_start' => null, 'cycle_end' => null]), null,
                [$this->candidate(['total_amount' => $actual, 'due_on' => null, 'series_key' => null])])[0];
            $this->assertSame($points, $ranked['score']);
            $this->assertSame($signal, $ranked['signals']['amount']);
            $this->assertCount($points === 0 ? 0 : 1, $ranked['reasons']);
        }
    }

    public function test_cadence_requires_a_series_and_customer_mapping_requires_an_exact_non_null_identifier(): void
    {
        foreach (['monthly', 'quarterly', 'semi_annual', 'annual'] as $cadence) {
            $result = SquareCandidateScorer::rank($this->invoice(['cadence' => $cadence]), null, [$this->candidate(['series_cadence' => $cadence])])[0];
            $this->assertTrue($result['signals']['cadence']);
            $this->assertContains('Series cadence is '.str_replace('_', '-', $cadence).", as the agreement's (+20)", $result['reasons']);
            $withoutSeries = SquareCandidateScorer::rank($this->invoice(['cadence' => $cadence]), null, [$this->candidate(['series_cadence' => $cadence, 'series_key' => null])])[0];
            $this->assertFalse($withoutSeries['signals']['cadence']);
        }
        $unknown = SquareCandidateScorer::rank($this->invoice(['cadence' => null]), null, [$this->candidate(['series_cadence' => null, 'square_customer_id' => null])])[0];
        $this->assertFalse($unknown['signals']['cadence']);
        $this->assertFalse($unknown['signals']['customer_mapped']);
        $this->assertFalse(SquareCandidateScorer::ambiguous([]));
    }

    public function test_invoice_numbers_finish_a_tie_and_internal_ranking_fields_are_never_returned(): void
    {
        $ranked = SquareCandidateScorer::rank($this->invoice(), 'cust_fixture_a', [
            $this->candidate(['square_invoice_id' => 'inv_fixture_number_2', 'invoice_number' => '000002-R-0001']),
            $this->candidate(['square_invoice_id' => 'inv_fixture_number_1']),
            $this->candidate(['square_invoice_id' => 'inv_fixture_number_missing', 'invoice_number' => null]),
        ]);
        $this->assertSame(['inv_fixture_number_missing', 'inv_fixture_number_1', 'inv_fixture_number_2'], array_column($ranked, 'square_invoice_id'));
        foreach ($ranked as $result) {
            $this->assertSame(['square_invoice_id', 'score', 'suggested', 'signals', 'reasons', 'date_distance_days'], array_keys($result));
        }
    }

    public function test_a_period_slot_requires_both_bounds_and_includes_the_first_day(): void
    {
        foreach (['cycle', 'service'] as $kind) {
            foreach ([[null, '2040-01-31', '2040-01-15', false], ['2040-01-01', null, '2040-01-15', false], ['2040-01-01', '2040-01-31', null, false], ['2040-01-01', '2040-01-31', '2040-01-01', true]] as [$start, $end, $date, $expected]) {
                $invoice = $this->invoice(['cycle_start' => null, 'cycle_end' => null, $kind.'_start' => $start, $kind.'_end' => $end]);
                $candidate = $this->candidate(['due_on' => $kind === 'cycle' ? $date : null, 'sale_or_service_date' => $kind === 'service' ? $date : null]);
                $result = SquareCandidateScorer::rank($invoice, null, [$candidate])[0];
                $this->assertSame($expected, $result['signals']['period_slot'], $kind);
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(array $overrides = []): array
    {
        return array_replace([
            'currency' => 'USD', 'total_amount' => 20_000, 'due_date' => '2040-01-15',
            'reference_date' => '2040-01-15', 'cycle_start' => '2040-01-01', 'cycle_end' => '2040-12-31',
            'service_start' => null, 'service_end' => null, 'cadence' => 'annual', 'settled' => false,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function candidate(array $overrides = []): array
    {
        return array_replace([
            'square_invoice_id' => 'inv_fixture_1', 'status' => 'UNPAID', 'currency' => 'USD',
            'total_amount' => 20_000, 'due_on' => '2040-01-15', 'scheduled_on' => null, 'sale_or_service_date' => null,
            'square_customer_id' => 'cust_fixture_a', 'series_key' => '000001', 'series_cadence' => 'annual',
            'invoice_number' => '000001-R-0001',
        ], $overrides);
    }
}
