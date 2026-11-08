<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\ConsultantTimeRelease;
use App\Support\Billing\SubcontractorBillingMode;
use PHPUnit\Framework\TestCase;

final class ConsultantTimeReleaseTest extends TestCase
{
    private function releases(
        string $status = 'draft',
        bool $billable = true,
        ?string $mode = null,
        bool $cost = false,
        string $workedOn = '2026-11-06',
        string $today = '2026-11-06',
        bool $linked = false,
        bool $disputed = false,
    ): bool {
        return ConsultantTimeRelease::releases($status, $billable, $mode, $cost, $workedOn, $today, $linked, $disputed);
    }

    public function test_unbilled_billable_consultant_time_worked_today_is_released(): void
    {
        $this->assertTrue($this->releases());
    }

    public function test_the_day_before_today_is_released_and_the_day_after_is_not(): void
    {
        $this->assertTrue($this->releases(workedOn: '2026-11-05'));
        $this->assertFalse($this->releases(workedOn: '2026-11-07'));
    }

    public function test_the_month_boundary_is_a_date_comparison_not_a_string_prefix(): void
    {
        $this->assertTrue($this->releases(workedOn: '2026-10-31', today: '2026-11-01'));
        $this->assertFalse($this->releases(workedOn: '2026-11-01', today: '2026-10-31'));
    }

    public function test_only_draft_time_is_released(): void
    {
        foreach (['approved', 'invoiced', 'submitted', ''] as $status) {
            $this->assertFalse($this->releases(status: $status), $status);
        }
    }

    public function test_time_that_is_not_billable_is_left_alone(): void
    {
        $this->assertFalse($this->releases(billable: false));
    }

    public function test_a_subcontractors_time_is_never_released_in_any_mode(): void
    {
        foreach (SubcontractorBillingMode::cases() as $mode) {
            $this->assertFalse($this->releases(mode: $mode->value), $mode->value);
        }
    }

    public function test_a_stored_subcontractor_cost_keeps_an_entry_out_even_with_no_mode(): void
    {
        $this->assertFalse($this->releases(cost: true));
    }

    public function test_time_already_on_an_invoice_line_is_not_released_again(): void
    {
        $this->assertFalse($this->releases(linked: true));
    }

    public function test_a_disputed_hour_waits_for_the_dispute_to_be_settled(): void
    {
        $this->assertFalse($this->releases(disputed: true));
    }

    public function test_the_client_approves_every_mode_except_direct(): void
    {
        $this->assertTrue(ConsultantTimeRelease::clientApproves(null));
        $this->assertTrue(ConsultantTimeRelease::clientApproves(SubcontractorBillingMode::FlatHourly));
        $this->assertTrue(ConsultantTimeRelease::clientApproves(SubcontractorBillingMode::Retainer));
        $this->assertFalse(ConsultantTimeRelease::clientApproves(SubcontractorBillingMode::Direct));
    }
}
