<?php

namespace Tests\Unit\Submission;

use App\Support\Approval\ApprovalPeriod;
use App\Support\Approval\ClientApprovalVerdict;
use App\Support\Submission\SubmissionState;
use App\Support\Submission\TimesheetCsv;
use App\Support\Submission\TimesheetRules;
use App\Support\Workspaces\WorkspaceEdition;
use PHPUnit\Framework\TestCase;

final class TimesheetAndStateTest extends TestCase
{
    public function test_only_a_persons_approval_is_on_the_timesheet(): void
    {
        $this->assertTrue(TimesheetRules::includes(ClientApprovalVerdict::Approved));
        $this->assertFalse(TimesheetRules::includes(ClientApprovalVerdict::ApprovedByPayment));
        $this->assertFalse(TimesheetRules::includes(ClientApprovalVerdict::Pending));
        $this->assertFalse(TimesheetRules::includes(ClientApprovalVerdict::Disputed));
    }

    public function test_how_an_approval_was_given(): void
    {
        $this->assertSame('Reviewer', TimesheetRules::via('reviewer'));
        $this->assertSame('Client', TimesheetRules::via('portal'));
        $this->assertSame('Payment', TimesheetRules::via('payment'));
        $this->assertSame('Unknown', TimesheetRules::via('other'));
        $this->assertSame('Unknown', TimesheetRules::via(null));
    }

    public function test_the_state_an_author_sees(): void
    {
        $this->assertSame(SubmissionState::Draft, SubmissionState::of('draft', false, ClientApprovalVerdict::Pending));
        $this->assertSame(SubmissionState::Submitted, SubmissionState::of('draft', true, ClientApprovalVerdict::Pending));
        $this->assertSame(SubmissionState::Submitted, SubmissionState::of('approved', false, ClientApprovalVerdict::Pending));
        $this->assertSame(SubmissionState::Approved, SubmissionState::of('draft', true, ClientApprovalVerdict::Approved));
        $this->assertSame(SubmissionState::Approved, SubmissionState::of('invoiced', false, ClientApprovalVerdict::ApprovedByPayment));
        $this->assertSame(SubmissionState::Disputed, SubmissionState::of('draft', true, ClientApprovalVerdict::Disputed));
    }

    public function test_the_edition(): void
    {
        $this->assertSame(WorkspaceEdition::Full, WorkspaceEdition::of(null));
        $this->assertSame(WorkspaceEdition::Full, WorkspaceEdition::of('full'));
        $this->assertSame(WorkspaceEdition::Light, WorkspaceEdition::of('light'));
        $this->assertSame(WorkspaceEdition::Light, WorkspaceEdition::of('mystery'));
        $this->assertSame(WorkspaceEdition::Light, WorkspaceEdition::of(7));
        $this->assertTrue(WorkspaceEdition::Full->offersBilling());
        $this->assertFalse(WorkspaceEdition::Light->offersBilling());
        $this->assertTrue(WorkspaceEdition::Light->isLight());
        $this->assertFalse(WorkspaceEdition::Full->isLight());
    }

    public function test_months_step_across_the_year(): void
    {
        $january = ApprovalPeriod::fromKey('2026-01');
        $december = ApprovalPeriod::fromKey('2026-12');
        $this->assertSame('2025-12', $january?->previousKey());
        $this->assertSame('2026-02', $january?->nextKey());
        $this->assertSame('2027-01', $december?->nextKey());
        $this->assertSame('2026-11', $december?->previousKey());
    }

    public function test_hours_are_decimal_to_two_places(): void
    {
        $this->assertSame('0.00', TimesheetCsv::hours(0));
        $this->assertSame('1.50', TimesheetCsv::hours(90));
        $this->assertSame('0.02', TimesheetCsv::hours(1));
        $this->assertSame('12.00', TimesheetCsv::hours(720));
    }

    public function test_the_csv_quotes_and_defuses_formulas(): void
    {
        $csv = TimesheetCsv::render([[
            'worked_on' => '2026-10-14', 'project' => 'P, "one"', 'task' => null, 'description' => "=HYPERLINK(\"x\")\nline",
            'minutes' => 90, 'worked_by' => '+Bob', 'approved_by' => '@Ann', 'approved_via' => 'Reviewer', 'approved_at' => '2026-11-02T10:00:00+00:00',
        ]]);

        $header = 'Date,Project,Task,Description,Hours,Minutes,Worked by,Approved by,Approved how,Approved on';
        $this->assertSame(
            $header."\r\n".'2026-10-14,"P, ""one""",,"\'=HYPERLINK(""x"")'."\n".'line",1.50,90,\'+Bob,\'@Ann,Reviewer,2026-11-02'."\r\n",
            $csv,
        );
        $this->assertSame($header."\r\n", TimesheetCsv::render([]));
        $dash = TimesheetCsv::render([['worked_on' => '2026-10-14', 'project' => '-1', 'task' => '', 'description' => 'd', 'minutes' => 0, 'worked_by' => null, 'approved_by' => null, 'approved_via' => 'Client', 'approved_at' => null]]);
        $this->assertSame("2026-10-14,'-1,,d,0.00,0,,,Client,\r\n", substr($dash, strlen($header) + 2));
    }
}
