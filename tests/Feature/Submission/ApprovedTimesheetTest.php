<?php

namespace Tests\Feature\Submission;

use App\Models\ClientTimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReviewerWorld;
use Tests\TestCase;

/** The approved timesheet shows only what a person approved, for the month, and nothing priced. */
final class ApprovedTimesheetTest extends TestCase
{
    use BuildsReviewerWorld;
    use RefreshDatabase;

    /** @var array<string, ClientTimeEntry> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');
        $this->buildReviewerWorld();

        $stamp = fn (string $source): array => ['client_approved_at' => '2026-11-02 10:00:00', 'client_approved_by_user_id' => $this->ben->id, 'client_approval_source' => $source];
        $draft = ['status' => 'draft', 'billing_rate_amount' => 987654];
        $this->rows = [
            'reviewer' => $this->hours($this->otherProject, $this->otherOwner, '2026-10-14', 60, $draft + $stamp('reviewer') + ['client_visible_description' => 'VISIBLE-REVIEWER']),
            'client' => $this->hours($this->otherProject, $this->otherOwner, '2026-10-31', 30, $draft + $stamp('portal') + ['client_visible_description' => 'VISIBLE-CLIENT']),
            'pending' => $this->hours($this->otherProject, $this->otherOwner, '2026-10-15', 15, $draft + ['submitted_at' => '2026-11-01 10:00:00', 'client_visible_description' => 'SECRET-PENDING']),
            'draft' => $this->hours($this->otherProject, $this->otherOwner, '2026-10-16', 15, $draft + ['client_visible_description' => 'SECRET-DRAFT']),
            'disputed' => $this->hours($this->otherProject, $this->otherOwner, '2026-10-17', 15, $draft + $stamp('reviewer') + ['client_disputed_at' => '2026-11-03 10:00:00', 'client_visible_description' => 'SECRET-DISPUTED']),
            'september' => $this->hours($this->otherProject, $this->otherOwner, '2026-09-30', 15, $draft + $stamp('reviewer') + ['client_visible_description' => 'SECRET-SEPTEMBER']),
            'november' => $this->hours($this->otherProject, $this->otherOwner, '2026-11-01', 15, $draft + $stamp('reviewer') + ['client_visible_description' => 'SECRET-NOVEMBER']),
            'payment' => $this->hours($this->otherProject, $this->otherOwner, '2026-10-18', 15, $draft + $stamp('payment') + ['client_visible_description' => 'SECRET-PAYMENT']),
            'colleague' => $this->hours($this->otherProject, $this->otherWorker, '2026-10-20', 45, $draft + $stamp('reviewer') + ['client_visible_description' => 'VISIBLE-COLLEAGUE']),
            'herila' => $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-14', 15, $draft + $stamp('reviewer') + ['client_visible_description' => 'SECRET-OTHER-WORKSPACE']),
        ];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_month_holds_only_what_a_person_approved(): void
    {
        $this->actingAs($this->otherOwner)->get("/workspaces/{$this->otherEng->public_id}/timesheet?period=2026-10")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspaces/timesheet')
                ->has('timesheet.entries', 3)
                ->where('timesheet.entries.0.description', 'VISIBLE-REVIEWER')
                ->where('timesheet.entries.0.approved_by', 'Ben Synthetic')
                ->where('timesheet.entries.0.approved_via', 'Reviewer')
                ->where('timesheet.entries.0.worked_by', $this->otherOwner->name)
                ->where('timesheet.entries.1.description', 'VISIBLE-COLLEAGUE')
                ->where('timesheet.entries.2.description', 'VISIBLE-CLIENT')
                ->where('timesheet.entries.2.approved_via', 'Client')
                ->where('timesheet.total_minutes', 135)
                ->where('timesheet.next_period', '2026-11')
                ->where('links.csv_href', "/workspaces/{$this->otherEng->public_id}/timesheet/export?period=2026-10&format=csv"));
    }

    public function test_it_carries_no_rate_internal_note_or_other_workspaces_hours(): void
    {
        $body = (string) $this->actingAs($this->otherOwner)->get("/workspaces/{$this->otherEng->public_id}/timesheet?period=2026-10")->getContent();

        foreach (['987654', 'SECRET-'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_a_plain_member_sees_only_their_own_hours(): void
    {
        $this->actingAs($this->otherWorker)->get("/workspaces/{$this->otherEng->public_id}/timesheet?period=2026-10")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('timesheet.entries', 0));
    }

    public function test_an_empty_month_and_a_bad_month(): void
    {
        $this->actingAs($this->otherOwner)->get("/workspaces/{$this->otherEng->public_id}/timesheet?period=2026-08")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('timesheet.entries', 0)->where('timesheet.total_minutes', 0));
        $this->actingAs($this->otherOwner)->get("/workspaces/{$this->otherEng->public_id}/timesheet?period=junk")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('timesheet.period', '2026-11'));
    }

    public function test_the_csv_lists_the_same_rows(): void
    {
        $response = $this->actingAs($this->otherOwner)->get("/workspaces/{$this->otherEng->public_id}/timesheet/export?period=2026-10&format=csv")->assertOk();
        $csv = (string) $response->getContent();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('approved-timesheet-2026-10.csv', (string) $response->headers->get('Content-Disposition'));
        $lines = array_values(array_filter(explode("\r\n", $csv)));
        $this->assertCount(4, $lines);
        $this->assertSame('Date,Project,Task,Description,Hours,Minutes,Worked by,Approved by,Approved how,Approved on', $lines[0]);
        $this->assertStringContainsString('2026-10-14,Synthetic Other Project,,VISIBLE-REVIEWER,1.00,60,', $lines[1]);
        $this->assertStringContainsString(',Reviewer,2026-11-02', $lines[1]);
        $this->assertStringNotContainsString('SECRET-', $csv);
    }

    public function test_the_pdf_is_a_pdf_and_an_unknown_format_is_not_found(): void
    {
        $response = $this->actingAs($this->otherOwner)->get("/workspaces/{$this->otherEng->public_id}/timesheet/export?period=2026-10&format=pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->actingAs($this->otherOwner)->get("/workspaces/{$this->otherEng->public_id}/timesheet/export?period=2026-10&format=xls")->assertNotFound();
    }

    public function test_the_rest_read_matches_the_page(): void
    {
        $this->actingAs($this->otherOwner)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), 'x'.str_repeat('a', 39))->getJson("/api/v1/workspaces/{$this->otherEng->public_id}/timesheets/approved?period=2026-10")->assertOk()
            ->assertJsonCount(3, 'data.entries')->assertJsonPath('data.total_minutes', 135);
    }
}
