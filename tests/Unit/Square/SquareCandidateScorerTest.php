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
