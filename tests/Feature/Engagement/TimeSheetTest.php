<?php

namespace Tests\Feature\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimeSheetTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Synthetic Time',
            'slug' => 'synthetic-time',
        ]);
        $this->workspace->memberships()->create(['user_id' => $this->manager->id, 'role' => 'admin']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Synthetic Client',
            'slug' => 'synthetic-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Synthetic Project',
            'status' => 'active',
        ]);
    }

    public function test_the_sheet_groups_entries_by_month_and_totals_them(): void
    {
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 90]);
        $this->entry(['worked_on' => '2026-07-19', 'minutes' => 30]);
        $this->entry(['worked_on' => '2026-06-02', 'minutes' => 45, 'is_billable' => false]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('time')
                ->has('months', 2)
                ->where('months.0.key', '2026-07')
                ->where('months.0.total_minutes', 120)
                ->where('months.0.billable_minutes', 120)
                ->has('months.0.entries', 2)
                ->where('months.1.key', '2026-06')
                ->where('months.1.total_minutes', 45)
                ->where('months.1.billable_minutes', 0));
    }

    /**
     * A line that bills an entry freezes it, draft invoice or not. The
     * predecessor unlinked an entry from a draft and regenerated the invoice;
     * this system has no path that recomposes a draft from an edited entry, so
     * allowing the edit would leave the draft charging the old quantity right
     * up until it was issued.
     */
    public function test_an_entry_on_any_invoice_is_frozen(): void
    {
        $onDraft = $this->entry(['worked_on' => '2026-07-04']);
        $onIssued = $this->entry(['worked_on' => '2026-07-05']);

        $this->attachToInvoice($onDraft, 'draft');
        $this->attachToInvoice($onIssued, 'issued');

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Newest first: the issued one was worked a day later.
                ->where('months.0.entries.0.invoice.status', 'issued')
                ->where('months.0.entries.0.can_edit', false)
                ->where('months.0.entries.0.can_approve', false)
                ->where('months.0.entries.1.invoice.status', 'draft')
                ->where('months.0.entries.1.can_edit', false)
                ->where('months.0.entries.1.can_approve', false));
    }

    /**
     * The screen hiding a control is not a rule. An operator holding a version
     * read before the invoice existed can still send the request, so the guard
     * has to live where the write happens - and it covers the agent API by
     * being there rather than here.
     */
    public function test_the_invoice_freeze_holds_against_a_direct_request(): void
    {
        $onDraft = $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);
        $onIssued = $this->entry(['worked_on' => '2026-07-05', 'minutes' => 60]);
        $version = AgentApiVersion::for($onDraft);

        $this->attachToInvoice($onDraft, 'draft');
        $this->attachToInvoice($onIssued, 'issued');

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$onDraft->public_id}", [
                'expected_version' => $version,
                'minutes' => 75,
            ])
            ->assertSessionHasErrors('engagement');

        $this->actingAs($this->manager)
            ->delete("/workspaces/{$this->workspace->public_id}/time-entries/{$onIssued->public_id}", [
                'expected_version' => AgentApiVersion::for($onIssued),
            ])
            ->assertSessionHasErrors('engagement');

        $this->assertSame(60, (int) $onDraft->fresh()?->minutes);
        $this->assertNotSoftDeleted($onIssued);
    }

    public function test_an_edit_that_read_a_stale_version_is_refused(): void
    {
        $entry = $this->entry(['minutes' => 60]);
        $stale = AgentApiVersion::for($entry);

        $entry->forceFill(['minutes' => 90, 'lock_version' => $entry->lock_version + 1])->save();

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => $stale,
                'minutes' => 30,
            ])
            // A conflict an operator can act on reaches them as a message the
            // dialog renders, not as a bare status the page cannot show.
            ->assertSessionHasErrors('engagement');

        $this->assertSame(90, (int) $entry->fresh()?->minutes);
    }

    public function test_making_an_entry_client_visible_requires_a_client_facing_description(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => AgentApiVersion::for($entry),
                'is_visible_to_client' => true,
            ])
            ->assertSessionHasErrors('engagement');

        $this->assertFalse((bool) $entry->fresh()?->is_visible_to_client);
    }

    public function test_deleting_an_entry_requires_the_version_it_read(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->manager)
            ->delete("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [])
            ->assertSessionHasErrors('expected_version');

        $this->assertNotNull($entry->fresh());

        $this->actingAs($this->manager)
            ->delete("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => AgentApiVersion::for($entry),
            ])
            ->assertRedirect();

        // `fresh()` ignores global scopes, so it would hand the row back with
        // its `deleted_at` set and read as "still there".
        $this->assertSoftDeleted($entry);
    }

    public function test_approving_stamps_the_status_and_the_approver(): void
    {
        $this->agreementWithAnHourlyRate();
        $first = $this->entry(['minutes' => 60]);
        $second = $this->entry(['minutes' => 30]);

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/time-entries/approve", [
                'entries' => [
                    ['id' => $first->public_id, 'expected_version' => AgentApiVersion::for($first)],
                    ['id' => $second->public_id, 'expected_version' => AgentApiVersion::for($second)],
                ],
            ])
            ->assertRedirect();

        $this->assertSame('approved', $first->fresh()?->status);
        $this->assertSame('approved', $second->fresh()?->status);
        $this->assertSame($this->manager->id, $first->fresh()?->approved_by_user_id);
        // The rate is stamped from the agreement in force, and says so.
        $this->assertSame(15000, (int) $first->fresh()?->billing_rate_amount);
        $this->assertSame('agreement', $first->fresh()?->billing_rate_source);
    }

    /**
     * A rate override is two halves of one statement. Accepting the amount
     * without the currency would bill a number in whatever currency happened to
     * be lying around.
     */
    public function test_a_rate_override_needs_both_an_amount_and_a_currency(): void
    {
        $this->agreementWithAnHourlyRate();
        $entry = $this->entry();

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/time-entries/approve", [
                'entries' => [[
                    'id' => $entry->public_id,
                    'expected_version' => AgentApiVersion::for($entry),
                    'billing_rate_amount' => 15000,
                ]],
            ])
            ->assertSessionHasErrors('engagement');

        // Refused outright rather than falling back to the agreement rate: a
        // half-stated override is a mistake, and guessing the other half is how
        // a euro rate bills as dollars.
        $this->assertSame('draft', $entry->fresh()?->status);
    }

    /**
     * The workflow has always taken a task; until now nothing on the web could
     * pass one, so browser-logged time was unattributed while agent-logged time
     * was not.
     */
    public function test_time_logged_from_the_browser_can_name_its_task(): void
    {
        $task = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'Synthetic Task',
            'status' => 'open',
        ]);

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => '2026-07-04',
                'minutes' => 45,
                'description' => 'Work with a task behind it',
                'task_id' => $task->public_id,
            ])
            ->assertRedirect();

        $entry = ClientTimeEntry::query()->latest('id')->firstOrFail();
        $this->assertSame($task->id, $entry->client_task_id);
    }

    /**
     * The same rule the agent API enforces. Two doors into one table is only
     * safe while they agree about what may pass.
     */
    public function test_client_visible_time_logged_from_the_browser_needs_a_client_description(): void
    {
        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => '2026-07-04',
                'minutes' => 45,
                'description' => 'Internal note nobody outside should read',
                'is_visible_to_client' => true,
            ])
            ->assertSessionHasErrors('engagement');

        $this->assertSame(0, ClientTimeEntry::query()->count());
    }

    /**
     * An agreement with no start date cannot anchor a billing cycle, so it has
     * no capacity to report and must not reach the ledger.
     *
     * This is the local half of a defect the MariaDB job caught: the filter
     * originally named `active_date`, which is an accessor over `starts_on`
     * rather than a column. MariaDB refuses the unknown column outright;
     * SQLite degrades the double-quoted identifier to a string literal, so the
     * predicate read as `where 'active_date' is not null`, admitted every row,
     * and the suite stayed green. Asserting what the filter *excludes* is what
     * makes it visible here rather than only in CI.
     */
    public function test_an_agreement_with_no_start_date_reports_no_capacity(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Undated Agreement',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => null,
        ]);
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.key', '2026-07')
                ->has('months.0.capacity', 0));
    }

    /**
     * The capacity strip reads the ledger the invoice will read, so a dated
     * agreement has to come through it.
     */
    public function test_a_dated_retainer_reports_its_capacity_for_the_month(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Dated Agreement',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);
        // Approved: the ledger counts approved work, because draft hours have
        // not drawn on the retainer yet.
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 120, 'status' => 'approved']);
        $this->entry(['worked_on' => '2026-07-05', 'minutes' => 30]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months.0.capacity', 1)
                ->where('months.0.capacity.0.agreement', 'Dated Agreement')
                // A monthly cadence has one cycle per month, so it carries no
                // separate identity; see the boundary-month test for the case
                // this field exists to disambiguate.
                ->where('months.0.capacity.0.cycle_start', '')
                ->where('months.0.capacity.0.worked_hours', 2)
                ->where('months.0.capacity.0.available_hours', 10)
                ->where('months.0.capacity.0.unused_hours', 8)
                // The draft half is reported beside the retainer it will draw
                // on, rather than folded into the used figure.
                ->where('months.0.capacity.0.pending_minutes', 30));
    }

    /**
     * Membership of a workspace is not access to every project in it.
     *
     * The `view` gate on the workspace passes for an ordinary member, so
     * without a project filter the sheet hands them every other project's
     * descriptions, workers, invoice links and capacity.
     */
    public function test_a_member_sees_only_the_projects_they_belong_to(): void
    {
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Project They Cannot See',
            'status' => 'active',
        ]);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        // Theirs in both senses: on their project, and logged by them. A
        // contributor reads their own time, not the whole project's.
        $mine = $this->entry([
            'worked_on' => '2026-07-04',
            'description' => 'Theirs to see',
            'user_id' => $member->id,
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $otherProject->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-05',
            'minutes' => 60,
            'description' => 'Not theirs to see',
            'status' => 'draft',
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($member)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies.0.projects', 1)
                ->where('companies.0.projects.0.name', 'Synthetic Project')
                ->has('months.0.entries', 1)
                ->where('months.0.entries.0.id', $mine->public_id));
    }

    /**
     * The screen must not offer a form whose submission is refused. Logging
     * goes through the workspace `manage` gate, so project access alone is not
     * enough to advertise it.
     */
    public function test_a_member_without_the_manage_gate_is_not_offered_the_log_form(): void
    {
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        $this->actingAs($member)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.projects.0.can_log_time', false));

        // And the endpoint agrees, which is the half that matters.
        $this->actingAs($member)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => '2026-07-04',
                'minutes' => 30,
                'description' => 'Refused',
            ])
            ->assertForbidden();
    }

    /**
     * The month an operator most wants the capacity strip for is the current
     * one, before anything is logged against it.
     */
    public function test_a_month_with_capacity_and_no_entries_still_appears(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Dated Agreement',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.key', '2026-07')
                ->has('months.0.entries', 0)
                ->has('months.0.capacity', 1)
                ->where('months.0.capacity.0.available_hours', 10));
    }

    /**
     * With the ledger carrying excess forward rather than billing it, the
     * overage lives in the closing negative balance and `excessHours` stays
     * zero. Reading `excessHours` alone reports an over-capacity month as
     * comfortably inside its retainer, in green.
     */
    public function test_work_beyond_the_retainer_is_reported_as_over(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Small Retainer',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 60,
            'starts_on' => '2026-07-01',
        ]);
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 240, 'status' => 'approved']);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.capacity.0.available_hours', 1)
                ->where('months.0.capacity.0.worked_hours', 4)
                ->where('months.0.capacity.0.over_hours', 3)
                ->where('months.0.capacity.0.unused_hours', 0));
    }

    /**
     * An hourly-only agreement grants no recurring capacity, so a strip for it
     * would report a permanent zero beside real hours.
     */
    public function test_an_agreement_with_no_retainer_reports_no_capacity(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Hourly Only',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'hourly_rate_amount' => 15000,
            'starts_on' => '2026-07-01',
        ]);
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60, 'status' => 'approved']);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 0));
    }

    public function test_the_sheet_shows_nothing_from_another_workspace(): void
    {
        $otherManager = User::factory()->create();
        $other = Workspace::query()->create(['name' => 'Other', 'slug' => 'other']);
        $other->memberships()->create(['user_id' => $otherManager->id, 'role' => 'admin']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $other->id,
            'name' => 'Other Client',
            'slug' => 'other-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $other->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Other Project',
            'status' => 'active',
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $other->id,
            'client_company_id' => $otherCompany->id,
            'client_project_id' => $otherProject->id,
            'user_id' => $otherManager->id,
            'worked_on' => '2026-07-04',
            'minutes' => 60,
            'description' => 'Another tenant',
            'status' => 'draft',
        ]);
        $mine = $this->entry(['worked_on' => '2026-07-04', 'description' => 'Mine']);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies', 1)
                ->has('months.0.entries', 1)
                ->where('months.0.entries.0.id', $mine->public_id));
    }

    public function test_an_entry_from_another_workspace_cannot_be_changed_through_this_workspace(): void
    {
        $other = Workspace::query()->create(['name' => 'Other', 'slug' => 'other']);
        $other->memberships()->create(['user_id' => $this->manager->id, 'role' => 'admin']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $other->id,
            'name' => 'Other Client',
            'slug' => 'other-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $other->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Other Project',
            'status' => 'active',
        ]);
        $foreign = ClientTimeEntry::query()->create([
            'workspace_id' => $other->id,
            'client_company_id' => $otherCompany->id,
            'client_project_id' => $otherProject->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-04',
            'minutes' => 60,
            'description' => 'Another tenant',
            'status' => 'draft',
        ]);

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$foreign->public_id}", [
                'expected_version' => AgentApiVersion::for($foreign),
                'minutes' => 15,
            ])
            ->assertNotFound();

        $this->assertSame(60, (int) $foreign->fresh()?->minutes);
    }

    /**
     * A retainer is sold to the company, so the ledger behind the capacity
     * strip aggregates every approved hour the company booked - including
     * hours on projects the reader cannot open. Filtering the rows and
     * leaving the strip company-wide published the agreement's title and the
     * shape of the hidden work in a tidier form than the rows would have.
     */
    public function test_capacity_is_withheld_from_a_member_who_cannot_see_the_whole_company(): void
    {
        $member = $this->memberOfTheOneProject();
        $this->otherProject();
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Confidential Retainer Title',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);
        $this->entry([
            'worked_on' => '2026-07-04',
            'minutes' => 60,
            'status' => 'approved',
            'user_id' => $member->id,
        ]);

        $this->travelTo('2026-07-20');

        // The manager, who can see all of it, still gets the strip.
        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 1));

        $this->actingAs($member)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months.0.entries', 1)
                ->has('months.0.capacity', 0));
    }

    /**
     * Approval is where the rate is stamped, so approving attached time
     * changes what a line bills without touching the line. `can_approve` is
     * false on such a row, but the control being hidden is not the rule.
     */
    public function test_an_entry_on_an_invoice_cannot_be_approved(): void
    {
        $this->agreementWithAnHourlyRate();
        $entry = $this->entry(['minutes' => 60]);
        $version = AgentApiVersion::for($entry);
        $this->attachToInvoice($entry, 'issued');

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/time-entries/approve", [
                'entries' => [['id' => $entry->public_id, 'expected_version' => $version]],
            ])
            ->assertSessionHasErrors('engagement');

        $this->assertSame('draft', $entry->fresh()?->status);
        $this->assertNull($entry->fresh()?->billing_rate_amount);
    }

    /**
     * Every write refuses an invoiced entry, and the list is here so that a
     * fourth verb added later has somewhere obvious to be missing from. The
     * update path was hardened first and approval was not - one guard per
     * verb is exactly how that happens.
     */
    public function test_every_write_path_refuses_an_entry_that_is_already_invoiced(): void
    {
        $this->agreementWithAnHourlyRate();
        $base = "/workspaces/{$this->workspace->public_id}";

        /** @var array<string, callable(ClientTimeEntry, string): TestResponse> $writes */
        $writes = [
            'update' => fn (ClientTimeEntry $entry, string $version) => $this->actingAs($this->manager)
                ->patch("{$base}/time-entries/{$entry->public_id}", [
                    'expected_version' => $version,
                    'minutes' => 75,
                ]),
            'delete' => fn (ClientTimeEntry $entry, string $version) => $this->actingAs($this->manager)
                ->delete("{$base}/time-entries/{$entry->public_id}", [
                    'expected_version' => $version,
                ]),
            'approve' => fn (ClientTimeEntry $entry, string $version) => $this->actingAs($this->manager)
                ->post("{$base}/time-entries/approve", [
                    'entries' => [['id' => $entry->public_id, 'expected_version' => $version]],
                ]),
        ];

        foreach ($writes as $verb => $write) {
            $entry = $this->entry(['minutes' => 60]);
            $version = AgentApiVersion::for($entry);
            $this->attachToInvoice($entry, 'issued');

            $write($entry, $version)->assertSessionHasErrors(
                'engagement',
                null,
                'default',
            );

            $this->assertSame(60, (int) $entry->fresh()?->minutes, "{$verb} altered an invoiced entry");
            $this->assertSame('draft', $entry->fresh()?->status, "{$verb} altered an invoiced entry");
            $this->assertNotSoftDeleted($entry);
        }
    }

    /**
     * `AgreementSelector` and `AgreementBillingRateResolver` both exclude
     * drafts, so a draft grants no hours anywhere the money is decided. A
     * proposed retainer offering its hours above a table an operator logs
     * against is the same claim, made where it is acted on.
     */
    public function test_a_draft_agreement_grants_no_capacity(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Proposed Retainer',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'draft',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 0));

        // And the exclusion is the status, not something else about the row:
        // activating the same agreement produces the strip.
        $agreement->forceFill(['status' => 'active'])->save();

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 1));
    }

    /**
     * The ledger runs from the agreement's start so the displayed months
     * inherit their rollover, but only the window is displayed. An agreement
     * running since 2019 otherwise returned six years of empty month cards
     * through a screen that offers twelve.
     */
    public function test_capacity_older_than_the_window_is_not_offered(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Long Running Retainer',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2023-01-01',
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                /** @var array{months: list<array{key: string}>} $props */
                $props = $page->toArray()['props'];

                $this->assertLessThanOrEqual(12, count($props['months']));
                $this->assertNotEmpty($props['months']);

                foreach ($props['months'] as $month) {
                    $this->assertGreaterThanOrEqual('2025-08', $month['key']);
                }
            });
    }

    /**
     * A stale or unreadable `company` fell through as a null selection, and
     * the page independently falls back to the first company - so it printed
     * that company's name above "no time logged in the last twelve months",
     * a claim about a company whose entries were never fetched.
     */
    public function test_an_unknown_company_filter_falls_back_to_a_visible_company(): void
    {
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time?company=not-a-real-company")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.company_id', $this->company->public_id)
                ->has('months.0.entries', 1));
    }

    /**
     * The web routes translate a conflict into something the dialog can
     * render; a JSON caller is written against the status and keeps it. Both
     * halves of that live in one branch, so both are asserted.
     */
    public function test_a_json_caller_still_receives_the_conflict_status(): void
    {
        $entry = $this->entry(['minutes' => 60]);
        $version = AgentApiVersion::for($entry);
        $this->attachToInvoice($entry, 'issued');

        $this->actingAs($this->manager)
            ->patchJson("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => $version,
                'minutes' => 75,
            ])
            ->assertStatus(409);

        $this->assertSame(60, (int) $entry->fresh()?->minutes);
    }

    /**
     * The browser can now name a task, which makes the task id a
     * tenant-owned identifier arriving from outside. Both ways of getting it
     * wrong are refused, and neither leaves an entry behind - a rejected
     * write that still logs the time is the failure worth catching.
     */
    public function test_a_task_from_outside_the_project_cannot_be_attached_from_the_browser(): void
    {
        $foreign = $this->foreignWorkspace();
        $foreignTask = ClientTask::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_project_id' => $foreign['project']->id,
            'title' => 'Foreign Task',
            'status' => 'open',
        ]);

        $siblingProject = $this->otherProject();
        $siblingTask = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $siblingProject->id,
            'title' => 'Sibling Task',
            'status' => 'open',
        ]);

        $url = "/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries";
        $payload = [
            'worked_on' => '2026-07-04',
            'minutes' => 60,
            'description' => 'Synthetic work',
        ];

        // Another workspace's task is not found at all.
        $this->actingAs($this->manager)
            ->post($url, $payload + ['task_id' => $foreignTask->public_id])
            ->assertNotFound();

        // This workspace's task, but another project's: refused by the
        // workflow, which is the check the workspace predicate cannot make.
        $this->actingAs($this->manager)
            ->post($url, $payload + ['task_id' => $siblingTask->public_id])
            ->assertSessionHasErrors('engagement');

        $this->assertSame(0, ClientTimeEntry::query()->count());
    }

    /**
     * A task is serialized on the strength of the project it names, and
     * nothing else - it passes no access check of its own. The schema carries
     * independent foreign keys rather than composite workspace/parent ones, so
     * a row owned elsewhere that points at a visible project satisfies the
     * join.
     */
    public function test_a_task_owned_by_another_workspace_is_not_serialized(): void
    {
        $foreign = $this->foreignWorkspace();

        ClientTask::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_project_id' => $this->project->id,
            'title' => 'Foreign Task Title',
            'status' => 'open',
        ]);
        ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'Local Task Title',
            'status' => 'open',
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $payload = (string) json_encode($page->toArray());

                $this->assertStringNotContainsString('Foreign Task Title', $payload);
                $this->assertStringContainsString('Local Task Title', $payload);
            });
    }

    /**
     * A task reaches a row on the strength of the id the entry holds, and
     * passes no check of its own - the same defect as the log form's task
     * list, one relation over.
     */
    public function test_a_task_from_another_workspace_or_project_is_not_shown_on_a_row(): void
    {
        $foreign = $this->foreignWorkspace();
        $foreignTask = ClientTask::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_project_id' => $this->project->id,
            'title' => 'Foreign Row Task',
            'status' => 'open',
        ]);

        $sibling = $this->otherProject();
        $siblingTask = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $sibling->id,
            'title' => 'Sibling Row Task',
            'status' => 'open',
        ]);

        $this->entry(['worked_on' => '2026-07-04', 'client_task_id' => $foreignTask->id]);
        $this->entry(['worked_on' => '2026-07-05', 'client_task_id' => $siblingTask->id]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months.0.entries', 2)
                // Not absent from the page - the sibling project's task list
                // is offered to a manager who can log against it. Absent from
                // the row, which claims the task describes that entry's work.
                ->where('months.0.entries.0.task', null)
                ->where('months.0.entries.1.task', null));
    }

    /**
     * The invoice badge is a link into another tenant's billing if only the
     * line is scoped: the number and status serialized onto the row come from
     * the invoice the line names, and the schema does not require the two to
     * share an owner.
     */
    public function test_an_invoice_owned_by_another_workspace_is_not_linked(): void
    {
        $foreign = $this->foreignWorkspace();
        $foreignInvoice = ClientInvoice::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'FOREIGN-INVOICE-4242',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $foreignInvoice->id,
            'type' => 'time',
            'description' => 'Synthetic line',
            'quantity' => '1',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'sort_order' => 1,
        ]);

        $entry = $this->entry(['worked_on' => '2026-07-04']);
        $entry->invoiceLines()->attach($line->id, ['workspace_id' => $this->workspace->id]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $payload = (string) json_encode($page->toArray());

                $this->assertStringNotContainsString('FOREIGN-INVOICE-4242', $payload);
            });
    }

    /**
     * The freeze refuses a write on the strength of a row the operator cannot
     * see, so an unscoped check hands another tenant the power to lock this
     * one's entry - a refusal whose cause is invisible and whose remedy is
     * out of reach.
     */
    public function test_another_workspaces_invoice_line_cannot_freeze_this_entry(): void
    {
        $foreign = $this->foreignWorkspace();
        $foreignInvoice = ClientInvoice::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'FOREIGN-FREEZE-1',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
        $foreignLine = ClientInvoiceLine::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_invoice_id' => $foreignInvoice->id,
            'type' => 'time',
            'description' => 'Foreign line',
            'quantity' => '1',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'sort_order' => 1,
        ]);

        $entry = $this->entry(['minutes' => 60]);
        $entry->invoiceLines()->attach($foreignLine->id, ['workspace_id' => $foreign['workspace']->id]);

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => AgentApiVersion::for($entry),
                'minutes' => 75,
            ])
            ->assertRedirect();

        $this->assertSame(75, (int) $entry->fresh()?->minutes);
    }

    /**
     * The window is read on the workspace's clock, not the server's. For the
     * hours between a workspace's month end and UTC's, the two disagree by a
     * whole month - and the browser dates new work locally, so the operator
     * is logging into a month the sheet has already dropped.
     */
    public function test_the_window_follows_the_workspace_timezone(): void
    {
        $this->workspace->forceFill(['timezone' => 'America/Los_Angeles'])->save();

        // 2026-09-01 03:00 UTC is still 2026-08-31 in Los Angeles, so the
        // window runs from September of the previous year, not October.
        $this->travelTo('2026-09-01 03:00:00');

        $this->entry(['worked_on' => '2025-09-15', 'description' => 'Edge of the window']);

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months', 1)
                ->where('months.0.key', '2025-09'));
    }

    /**
     * A cadence anchored mid-month puts two cycles in one calendar month, and
     * the ledger emits a summary for each - clipping the month between them so
     * neither claims the other's hours. Keyed by `yearMonth` alone they became
     * two strips under one agreement name with unrelated balances, and React
     * saw one key twice.
     */
    public function test_a_month_holding_two_cycles_reports_them_separately(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Quarterly Retainer',
            'currency' => 'USD',
            'billing_cadence' => 'quarterly',
            'status' => 'active',
            'period_retainer_minutes' => 3000,
            'starts_on' => '2026-02-15',
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                /** @var array{months: list<array{key: string, capacity: list<array{agreement: string, cycle_start: string}>}>} $props */
                $props = $page->toArray()['props'];

                $boundary = null;

                foreach ($props['months'] as $month) {
                    if ($month['key'] === '2026-05') {
                        $boundary = $month;
                    }
                }

                $this->assertNotNull($boundary, 'The boundary month is missing.');
                $this->assertCount(2, $boundary['capacity']);

                $cycles = array_column($boundary['capacity'], 'cycle_start');

                $this->assertCount(2, array_unique($cycles), 'The two cycles are indistinguishable.');
                $this->assertNotContains('', $cycles);
            });
    }

    /**
     * Membership of a project is not the right to read its time.
     *
     * `AgentTimeEntryQuery::visibleTo()` already decides this - an owner or
     * manager reads every entry on the project, a contributor only their own,
     * a viewer none - and the sheet asks it rather than restating it. Before
     * this, any project role read every colleague's internal descriptions,
     * worker names and invoice links.
     */
    public function test_a_projects_time_is_read_by_role_not_by_membership(): void
    {
        $mine = $this->entry(['worked_on' => '2026-07-04', 'description' => 'Logged by the manager']);

        $viewer = $this->memberWithProjectRole(ProjectRole::Viewer);
        $contributor = $this->memberWithProjectRole(ProjectRole::Contributor);
        $projectManager = $this->memberWithProjectRole(ProjectRole::Manager);

        $theirs = $this->entry([
            'worked_on' => '2026-07-05',
            'description' => 'Logged by the contributor',
            'user_id' => $contributor->id,
        ]);

        $this->travelTo('2026-07-20');
        $url = "/workspaces/{$this->workspace->public_id}/time";

        // A viewer reads the project and none of its time.
        $this->actingAs($viewer)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies.0.projects', 1)
                ->has('months', 0));

        // A contributor reads their own row and not their colleague's.
        $this->actingAs($contributor)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months.0.entries', 1)
                ->where('months.0.entries.0.id', $theirs->public_id));

        // A project manager reads both.
        $this->actingAs($projectManager)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.entries', 2));

        $this->assertNotSame($mine->public_id, $theirs->public_id);
    }

    /**
     * Capacity is a total of approved time, so it goes only to a reader who
     * could see the time it is drawn from. A viewer reading "62 of 80 hours
     * used" learns the same thing the rows would have told them.
     */
    public function test_capacity_is_withheld_from_a_reader_who_cannot_see_the_time(): void
    {
        $viewer = $this->memberWithProjectRole(ProjectRole::Viewer);
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer Behind The Curtain',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 120, 'status' => 'approved']);

        $this->travelTo('2026-07-20');

        $this->actingAs($viewer)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $payload = (string) json_encode($page->toArray());

                $this->assertStringNotContainsString('Retainer Behind The Curtain', $payload);
            });
    }

    /**
     * Deferred work is excluded from the ledger until it is allocated, so
     * approving it draws nothing on the retainer. Counting it as pending
     * overstated the claim on capacity, and went on overstating it after
     * approval - it is already reported on its own line.
     */
    public function test_deferred_drafts_are_not_counted_as_pending_capacity(): void
    {
        $this->monthlyRetainer('Deferred Pending Retainer');
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);
        $this->entry(['worked_on' => '2026-07-05', 'minutes' => 90, 'is_deferred' => true]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.capacity.0.pending_minutes', 60)
                ->where('months.0.deferred_minutes', 90)
                ->where('months.0.total_minutes', 150));
    }

    /**
     * The route binding does not scope the entry, so the lock added to
     * serialize writes against invoice allocation was being taken on another
     * tenant's row and released by the refusal a moment later.
     */
    public function test_a_write_never_locks_another_workspaces_row(): void
    {
        $foreign = $this->foreignWorkspace();
        $foreignEntry = ClientTimeEntry::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_company_id' => $foreign['project']->client_company_id,
            'client_project_id' => $foreign['project']->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-04',
            'minutes' => 60,
            'description' => 'Another tenant',
            'status' => 'draft',
        ]);

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$foreignEntry->public_id}", [
                'expected_version' => AgentApiVersion::for($foreignEntry),
                'minutes' => 75,
            ])
            ->assertNotFound();

        // Snapshot before anything else reads the table: the listener stays
        // attached, and the assertions below would otherwise sample their own
        // queries.
        $captured = $statements;

        $this->assertSame(60, (int) $foreignEntry->fresh()?->minutes);

        // The refusal alone proves nothing: `assertDraftEditable()` returns
        // 404 either way. What the workspace predicate changes is which row
        // the lock is taken on, and on one connection that is visible only in
        // the query. Any read of this table by primary key has to name the
        // workspace it is reading for.
        // Unquoted, because the grammars disagree: SQLite writes `"id"` and
        // MariaDB writes `` `id` ``, and matching one of them makes the test
        // vacuous on the other engine - which is how this assertion first
        // reached CI green locally and red on MariaDB.
        $byPrimaryKey = array_filter(
            array_map(self::unquote(...), $captured),
            fn (string $sql): bool => str_contains($sql, 'from client_time_entries')
                && preg_match('/\bid = \?/', $sql) === 1,
        );

        $this->assertNotEmpty($byPrimaryKey, 'The write never read the row, so this asserted nothing.');

        foreach ($byPrimaryKey as $sql) {
            $this->assertStringContainsString('workspace_id', $sql, "Unscoped read of another tenant's row: {$sql}");
        }
    }

    /**
     * The pivot carries its own `workspace_id`, and the schema does not
     * require it to agree with the line's. A foreign association must not
     * freeze this tenant's entry, and the freeze must agree with the badge -
     * refusing every write while showing no invoice explains nothing.
     */
    public function test_a_foreign_pivot_row_cannot_freeze_this_entry(): void
    {
        $foreign = $this->foreignWorkspace();
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'LOCAL-1',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'time',
            'description' => 'Synthetic line',
            'quantity' => '1',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'sort_order' => 1,
        ]);

        $entry = $this->entry(['minutes' => 60]);
        // The line is this workspace's; only the association is not.
        $entry->invoiceLines()->attach($line->id, ['workspace_id' => $foreign['workspace']->id]);

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => AgentApiVersion::for($entry),
                'minutes' => 75,
            ])
            ->assertRedirect();

        $this->assertSame(75, (int) $entry->fresh()?->minutes);
    }

    /**
     * The ledger gathers a company's work by `client_company_id` alone, and
     * `client_project_id` is an independent key - so an entry naming this
     * company while pointing at another company's project is excluded from
     * the rows and counted in the total above them.
     */
    public function test_capacity_is_withheld_when_an_entry_names_a_project_of_another_company(): void
    {
        $foreign = $this->foreignWorkspace();
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer With Muddled Inputs',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60, 'status' => 'approved']);

        $this->travelTo('2026-07-20');
        $url = "/workspaces/{$this->workspace->public_id}/time";

        // Consistent to begin with: the strip is there.
        $this->actingAs($this->manager)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 1));

        // This entry is this company's as far as the ledger is concerned, and
        // its hours are another company's work.
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $foreign['project']->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-06',
            'minutes' => 300,
            'description' => 'Hours from somewhere else',
            'status' => 'approved',
        ]);

        $this->actingAs($this->manager)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 0));
    }

    /**
     * Deferred work joins the ledger once an invoice carries it, and the
     * invoice has to be this workspace's. An unscoped check let another
     * tenant's association decide whether this tenant's deferred hours count
     * against its own retainer.
     */
    public function test_a_foreign_allocation_does_not_admit_deferred_time_to_the_ledger(): void
    {
        $foreign = $this->foreignWorkspace();
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Deferred Retainer',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);

        $foreignInvoice = ClientInvoice::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'FOREIGN-DEFER-1',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
        $foreignLine = ClientInvoiceLine::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_invoice_id' => $foreignInvoice->id,
            'type' => 'time',
            'description' => 'Foreign line',
            'quantity' => '1',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'sort_order' => 1,
        ]);

        $deferred = $this->entry([
            'worked_on' => '2026-07-04',
            'minutes' => 180,
            'status' => 'approved',
            'is_deferred' => true,
        ]);
        $deferred->invoiceLines()->attach($foreignLine->id, ['workspace_id' => $foreign['workspace']->id]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months.0.capacity', 1)
                // Deferred and not allocated here, so it draws nothing.
                ->where('months.0.capacity.0.worked_hours', 0));
    }

    /**
     * Subcontractor time bills at its own cost and `scopeRetainerBillable()`
     * excludes it, so approving it draws nothing. Reported as pending it
     * overstated the claim on the retainer, and the total stayed wrong after
     * approval removed it.
     */
    public function test_subcontractor_drafts_are_not_counted_as_pending_capacity(): void
    {
        $this->monthlyRetainer('Subcontractor Pending Retainer');
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);
        $this->entry([
            'worked_on' => '2026-07-05',
            'minutes' => 120,
            'subcontractor_cost_amount' => 5000,
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.capacity.0.pending_minutes', 60)
                ->where('months.0.total_minutes', 180));
    }

    /**
     * Hidden work is not merely filtered out of the payload, it is not read.
     * Loading tasks alongside the projects meant a member scoped to one
     * project paid for every task in the workspace.
     */
    public function test_hidden_projects_tasks_are_never_loaded(): void
    {
        $member = $this->memberOfTheOneProject();
        $hidden = $this->otherProject();

        foreach (range(1, 3) as $index) {
            ClientTask::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_project_id' => $hidden->id,
                'title' => "Hidden Task {$index}",
                'status' => 'open',
            ]);
        }

        $this->travelTo('2026-07-20');

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->actingAs($member)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk();

        $taskReads = array_filter(
            array_map(self::unquote(...), $statements),
            fn (string $sql): bool => str_contains($sql, 'from client_tasks'),
        );

        $this->assertNotEmpty($taskReads, 'Tasks were never read, so this asserted nothing.');

        foreach ($taskReads as $sql) {
            // The eager load inlines the parent ids rather than binding them,
            // so the set it read is right there in the statement.
            $this->assertSame(
                1,
                preg_match('/client_project_id in \(([^)]*)\)/', $sql, $matches),
                "Tasks were read without naming their projects: {$sql}",
            );

            $this->assertSame(
                [(string) $this->project->id],
                array_map(trim(...), explode(',', $matches[1])),
                "Tasks were read for more projects than the reader can see: {$sql}",
            );
        }
    }

    /**
     * A renewal ends its predecessor whether or not anyone wrote an
     * `ends_on` date - invoice generation stops the old segment at the new
     * one's start, and the strip has to be the figure the invoice uses.
     */
    public function test_a_renewal_ends_its_predecessors_capacity(): void
    {
        $this->monthlyRetainer('Original Retainer');
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Renewal Retainer',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 900,
            'starts_on' => '2026-08-01',
        ]);

        $this->travelTo('2026-08-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                /** @var array{months: list<array{key: string, capacity: list<array{agreement: string}>}>} $props */
                $props = $page->toArray()['props'];
                $august = null;

                foreach ($props['months'] as $month) {
                    if ($month['key'] === '2026-08') {
                        $august = $month;
                    }
                }

                $this->assertNotNull($august);
                $this->assertSame(
                    ['Renewal Retainer'],
                    array_column($august['capacity'], 'agreement'),
                    'The superseded agreement still claims the month its successor took over.',
                );
            });
    }

    /**
     * A retainer scoped to one project is never drawn on by work logged
     * against another, so a company-wide pending figure beside it announced a
     * demand on capacity that could not arrive.
     */
    public function test_pending_work_is_counted_against_the_retainer_that_can_reach_it(): void
    {
        $other = $this->otherProject();
        $this->monthlyRetainer('Project Scoped Retainer', $this->project->id);

        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);
        $this->entry([
            'worked_on' => '2026-07-05',
            'minutes' => 300,
            'client_project_id' => $other->id,
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months.0.capacity', 1)
                // Only the hour logged against the covered project.
                ->where('months.0.capacity.0.pending_minutes', 60)
                ->where('months.0.total_minutes', 360));
    }

    /**
     * The window has an upper bound as well as a lower one. Capacity is built
     * only to the end of the current month, so a mistyped year sorted a month
     * card above every real one and stayed there.
     */
    public function test_work_dated_beyond_the_window_is_not_shown(): void
    {
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);
        $this->entry(['worked_on' => '2099-08-29', 'minutes' => 60, 'description' => 'Mistyped year']);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months', 1)
                ->where('months.0.key', '2026-07'));
    }

    /**
     * `user_id` is an independent key and proves no membership, so a row
     * could name a person from outside the workspace entirely - the one field
     * on this screen that identifies someone rather than describing work.
     */
    public function test_a_worker_from_outside_the_workspace_is_not_named(): void
    {
        $outsider = User::factory()->create(['name' => 'Outsider Who Never Joined']);
        $this->entry(['worked_on' => '2026-07-04', 'user_id' => $outsider->id]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $payload = (string) json_encode($page->toArray());

                $this->assertStringNotContainsString('Outsider Who Never Joined', $payload);
            });
    }

    /**
     * Task attribution is optional, so an entry saved against the wrong task
     * has to be correctable while it is still a draft - including back to
     * none, which is why an explicit null differs from an absent key.
     */
    public function test_a_draft_can_have_its_task_corrected_or_cleared(): void
    {
        $first = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'First Task',
            'status' => 'open',
        ]);
        $second = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'Second Task',
            'status' => 'open',
        ]);

        $entry = $this->entry(['client_task_id' => $first->id]);
        $url = "/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}";

        $this->actingAs($this->manager)
            ->patch($url, [
                'expected_version' => AgentApiVersion::for($entry),
                'task_id' => $second->public_id,
            ])
            ->assertRedirect();

        $this->assertSame($second->id, $entry->fresh()?->client_task_id);

        $this->actingAs($this->manager)
            ->patch($url, [
                'expected_version' => AgentApiVersion::for($entry->fresh()),
                'task_id' => null,
            ])
            ->assertRedirect();

        $this->assertNull($entry->fresh()?->client_task_id);
    }

    /** A task of another project cannot be attached by correcting a draft. */
    public function test_correcting_a_task_cannot_reach_another_projects_task(): void
    {
        $sibling = $this->otherProject();
        $siblingTask = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $sibling->id,
            'title' => 'Sibling Task',
            'status' => 'open',
        ]);

        $entry = $this->entry();

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => AgentApiVersion::for($entry),
                'task_id' => $siblingTask->public_id,
            ])
            ->assertStatus(422);

        $this->assertNull($entry->fresh()?->client_task_id);
    }

    /**
     * Every permission here is asked of the project, and `ProjectAccess`
     * resolves a role against the project's own workspace - so an entry of
     * this workspace pointing at another's project was authorised against
     * that other workspace. Someone who manages there and merely views here
     * could approve this workspace's time and stamp its rate.
     */
    public function test_approval_cannot_borrow_a_role_from_another_workspaces_project(): void
    {
        $this->agreementWithAnHourlyRate();
        $foreign = $this->foreignWorkspace();

        // An outsider here, in charge over there.
        $outsider = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $outsider->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'user_id' => $outsider->id,
            'role' => ProjectRole::Viewer->value,
        ]);
        $foreign['workspace']->memberships()->create(['user_id' => $outsider->id, 'role' => 'admin']);

        $entry = $this->entry(['client_project_id' => $foreign['project']->id]);

        $this->actingAs($outsider)
            ->post("/workspaces/{$this->workspace->public_id}/time-entries/approve", [
                'entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]],
            ])
            ->assertNotFound();

        $this->assertSame('draft', $entry->fresh()?->status);
        $this->assertNull($entry->fresh()?->billing_rate_amount);
    }

    /**
     * The selector returns the first later candidate in collection order, so
     * `starts_on` has to lead the sort and `id` is only the tie-break. Sorted
     * by id first, a renewal inserted out of order is read as the wrong
     * successor and the agreement it replaced keeps running beside it.
     */
    public function test_succession_follows_start_dates_not_insertion_order(): void
    {
        $this->datedRetainer('January Retainer', '2026-01-01');
        $this->datedRetainer('March Retainer', '2026-03-01');
        $this->datedRetainer('February Retainer', '2026-02-01');

        $this->travelTo('2026-03-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                /** @var array{months: list<array{key: string, capacity: list<array{agreement: string}>}>} $props */
                $props = $page->toArray()['props'];
                $february = null;

                foreach ($props['months'] as $month) {
                    if ($month['key'] === '2026-02') {
                        $february = $month;
                    }
                }

                $this->assertNotNull($february);
                $this->assertSame(
                    ['February Retainer'],
                    array_column($february['capacity'], 'agreement'),
                    'January was superseded in February and still claims it.',
                );
            });
    }

    /**
     * A handover mid-month puts two rows in one calendar month, and an entry
     * lands in whichever segment covers its date. Matching on the month alone
     * gave both strips the whole month's pending total.
     */
    public function test_pending_work_lands_in_the_segment_that_covers_its_date(): void
    {
        $this->datedRetainer('Before The Handover', '2026-01-01');
        $this->datedRetainer('After The Handover', '2026-02-15');

        $this->entry(['worked_on' => '2026-02-05', 'minutes' => 60]);
        $this->entry(['worked_on' => '2026-02-20', 'minutes' => 90]);

        $this->travelTo('2026-02-25');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.key', '2026-02')
                ->has('months.0.capacity', 2)
                ->where('months.0.capacity.0.agreement', 'Before The Handover')
                ->where('months.0.capacity.0.pending_minutes', 60)
                ->where('months.0.capacity.1.agreement', 'After The Handover')
                ->where('months.0.capacity.1.pending_minutes', 90));
    }

    /**
     * The visible project ids span the workspace, so naming them alone
     * admitted an entry filed under this company while pointing at another
     * company's project - rendered and totalled here on the strength of a key
     * that says nothing about which company it belongs to.
     */
    public function test_an_entry_naming_another_companys_project_is_not_shown_here(): void
    {
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Second Client',
            'slug' => 'second-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Second Client Project',
            'status' => 'active',
        ]);

        $mine = $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);
        $this->entry([
            'worked_on' => '2026-07-05',
            'minutes' => 300,
            'client_project_id' => $otherProject->id,
            'description' => 'Work belonging to the other client',
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time?company={$this->company->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months.0.entries', 1)
                ->where('months.0.entries.0.id', $mine->public_id)
                ->where('months.0.total_minutes', 60));
    }

    /**
     * A date the sheet will not show must not be accepted. The write
     * succeeded, the dialog closed as though it had saved, and the row was
     * gone from the only screen offering correction or deletion.
     */
    public function test_a_date_beyond_the_window_is_refused_rather_than_hidden(): void
    {
        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => '2099-08-29',
                'minutes' => 60,
                'description' => 'Mistyped year',
            ])
            ->assertSessionHasErrors('worked_on');

        $this->assertSame(0, ClientTimeEntry::query()->count());

        // The end of the current month is still inside the window.
        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => '2026-07-31',
                'minutes' => 60,
                'description' => 'Later this month',
            ])
            ->assertRedirect();

        $this->assertSame(1, ClientTimeEntry::query()->count());
    }

    /**
     * Matching the entry to its project proves only that those two agree.
     * Both can name a company belonging to another workspace, and then the
     * ownership walk leaves this tenant at the last link rather than the
     * first.
     */
    public function test_a_write_refuses_a_company_owned_by_another_workspace(): void
    {
        $foreign = $this->foreignWorkspace();
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'name' => 'Company Of Another Tenant',
            'slug' => 'company-of-another-tenant',
        ]);
        // This workspace's project, naming that workspace's company.
        $project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $foreignCompany->id,
            'name' => 'Muddled Project',
            'status' => 'active',
        ]);
        $entry = $this->entry([
            'client_company_id' => $foreignCompany->id,
            'client_project_id' => $project->id,
            'minutes' => 60,
        ]);

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => AgentApiVersion::for($entry),
                'minutes' => 75,
            ])
            ->assertNotFound();

        $this->assertSame(60, (int) $entry->fresh()?->minutes);
    }

    /**
     * Two retainers covering different projects run side by side. Asked
     * across the whole company, the later-starting one ends the other - and
     * on a shared start date the id tie-break does, removing one strip
     * outright.
     */
    public function test_a_retainer_is_not_superseded_by_one_covering_another_project(): void
    {
        $other = $this->otherProject();
        $this->monthlyRetainer('Retainer For One Project', $this->project->id);
        $this->monthlyRetainer('Retainer For The Other', $other->id);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                /** @var array{months: list<array{key: string, capacity: list<array{agreement: string}>}>} $props */
                $props = $page->toArray()['props'];

                $this->assertNotEmpty($props['months']);
                $this->assertEqualsCanonicalizing(
                    ['Retainer For One Project', 'Retainer For The Other'],
                    array_column($props['months'][0]['capacity'], 'agreement'),
                    'A concurrent retainer on another project ended this one.',
                );
            });
    }

    /**
     * The window has a near edge too, and hiding an accepted write is no
     * better there: a year mistyped as 2006 saved and vanished exactly as
     * 2099 did.
     */
    public function test_a_date_before_the_window_is_refused_rather_than_hidden(): void
    {
        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => '2006-07-04',
                'minutes' => 60,
                'description' => 'Mistyped year',
            ])
            ->assertSessionHasErrors('worked_on');

        $this->assertSame(0, ClientTimeEntry::query()->count());

        // The far edge of the window is still inside it.
        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => '2025-08-01',
                'minutes' => 60,
                'description' => 'The oldest month the sheet shows',
            ])
            ->assertRedirect();

        $this->assertSame(1, ClientTimeEntry::query()->count());
    }

    /**
     * A period retainer anchored mid-month puts the tail of one cycle and the
     * head of the next in one calendar month. Bounded by the month alone, the
     * earlier row swallowed the later cycle's pending work as well as its own.
     */
    public function test_pending_work_stops_at_its_own_cycle_boundary(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Quarterly Retainer',
            'currency' => 'USD',
            'billing_cadence' => 'quarterly',
            'status' => 'active',
            'period_retainer_minutes' => 3000,
            'starts_on' => '2026-02-15',
        ]);

        // May is a boundary month: the second cycle starts on the 15th.
        $this->entry(['worked_on' => '2026-05-05', 'minutes' => 60]);
        $this->entry(['worked_on' => '2026-05-20', 'minutes' => 90]);

        $this->travelTo('2026-05-25');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                /** @var array{months: list<array{key: string, capacity: list<array{cycle_start: string, pending_minutes: int}>}>} $props */
                $props = $page->toArray()['props'];
                $may = null;

                foreach ($props['months'] as $month) {
                    if ($month['key'] === '2026-05') {
                        $may = $month;
                    }
                }

                $this->assertNotNull($may);
                $this->assertCount(2, $may['capacity']);
                $this->assertSame(
                    [60, 90],
                    array_column($may['capacity'], 'pending_minutes'),
                    'A cycle claimed pending work belonging to the next one.',
                );
            });
    }

    /**
     * The class of bug, not one instance of it.
     *
     * Each finding so far has been one field escaping one filter, and the fix
     * has been one more filter. This asserts the property those fixes were
     * reaching for: nothing belonging to a project the reader cannot open
     * appears anywhere in what is sent, whatever field it arrives in. A field
     * added later to the payload is covered without anyone remembering to
     * cover it.
     */
    public function test_nothing_from_an_invisible_project_reaches_the_payload(): void
    {
        $member = $this->memberOfTheOneProject();
        $hidden = $this->otherProject();

        $worker = User::factory()->create(['name' => 'Hidden Worker Name']);
        $task = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $hidden->id,
            'title' => 'Hidden Task Title',
            'status' => 'open',
        ]);
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Hidden Agreement Title',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);

        $hiddenEntry = ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $hidden->id,
            'client_task_id' => $task->id,
            'user_id' => $worker->id,
            'worked_on' => '2026-07-05',
            'minutes' => 60,
            'description' => 'Hidden Work Description',
            'client_visible_description' => 'Hidden Client Description',
            'is_visible_to_client' => true,
            'status' => 'draft',
        ]);
        $this->attachToInvoice($hiddenEntry, 'issued')
            ->forceFill(['invoice_number' => 'HIDDEN-INVOICE-9001'])
            ->save();

        $this->entry([
            'worked_on' => '2026-07-04',
            'description' => 'Visible Work Description',
            'user_id' => $member->id,
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($member)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $payload = (string) json_encode($page->toArray());

                foreach ([
                    'Project They Cannot See',
                    'Hidden Worker Name',
                    'Hidden Task Title',
                    'Hidden Work Description',
                    'Hidden Client Description',
                    'HIDDEN-INVOICE-9001',
                    'Hidden Agreement Title',
                ] as $secret) {
                    $this->assertStringNotContainsString($secret, $payload);
                }

                // And the sheet is not empty for the wrong reason.
                $this->assertStringContainsString('Visible Work Description', $payload);
            });
    }

    /**
     * Authorization asked per row is a query per row.
     *
     * `ProjectAccess` holds no cache, so a permission read while mapping costs
     * membership queries every time it is asked - invisible at three entries
     * and not at three hundred. Comparing two renders of different sizes fixes
     * the shape rather than a number, so it neither needs updating when a
     * query is legitimately added nor passes when one starts repeating.
     */
    public function test_the_sheet_does_not_query_once_per_entry(): void
    {
        $this->travelTo('2026-07-20');

        for ($i = 0; $i < 3; $i++) {
            $this->entry(['worked_on' => '2026-07-04']);
        }

        $few = $this->queriesRenderingTheSheet();

        for ($i = 0; $i < 27; $i++) {
            $this->entry(['worked_on' => '2026-07-05']);
        }

        $this->assertSame(
            $few,
            $this->queriesRenderingTheSheet(),
            'The sheet issued more queries for more entries, which is an N+1.',
        );
    }

    /**
     * The `active_date` defect, generalised.
     *
     * `active_date` is an accessor over `starts_on`, and naming one in a
     * predicate is invalid SQL. MariaDB says so; SQLite does not, because an
     * unresolvable double-quoted identifier degrades to a string literal - so
     * `where "active_date" is not null` reads as a non-empty string and admits
     * every row. The local suite cannot see the class of bug at all, and this
     * is the cheapest thing that can: every identifier the page's own queries
     * quote has to be a real table, a real column, or an alias that query
     * declared.
     */
    public function test_the_sheet_names_only_columns_that_exist(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Synthetic Retainer',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);
        $this->attachToInvoice($this->entry(['worked_on' => '2026-07-04']), 'issued');
        $this->entry(['worked_on' => '2026-07-06', 'status' => 'approved']);

        $this->travelTo('2026-07-20');

        $known = [];

        foreach (Schema::getTableListing() as $table) {
            // The listing qualifies names with their schema (`main.workspaces`
            // on SQLite); the grammar quotes the bare name.
            $bare = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            $known[strtolower($bare)] = true;

            foreach (Schema::getColumnListing($bare) as $column) {
                $known[strtolower($column)] = true;
            }
        }

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk();

        $this->assertNotEmpty($statements, 'No queries were captured, so this asserted nothing.');

        $checked = 0;

        foreach ($statements as $statement) {
            $allowed = $known;
            // Either grammar's quoting. Reading only SQLite's would make this
            // assert nothing on the engine that catches the bug outright.
            preg_match_all('/\bas\s+[`"]([^`"]+)[`"]/i', $statement, $aliases);

            foreach ($aliases[1] as $alias) {
                $allowed[strtolower($alias)] = true;
            }

            preg_match_all('/[`"]([^`"]+)[`"]/', $statement, $identifiers);

            foreach ($identifiers[1] as $identifier) {
                $checked++;
                $this->assertArrayHasKey(
                    strtolower($identifier),
                    $allowed,
                    "`{$identifier}` is not a column, table or alias, so this predicate is silently a string on SQLite: {$statement}",
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'No identifiers were read, so this asserted nothing.');
    }

    /** Identifier quoting differs by engine; the assertions here must not. */
    private static function unquote(string $sql): string
    {
        return str_replace(['`', '"'], '', $sql);
    }

    private function queriesRenderingTheSheet(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * A second workspace with its own company and project.
     *
     * @return array{workspace: Workspace, project: ClientProject}
     */
    private function foreignWorkspace(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Other Tenant', 'slug' => 'other-tenant']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Other Client',
            'slug' => 'other-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Other Project',
            'status' => 'active',
        ]);

        return ['workspace' => $workspace, 'project' => $project];
    }

    private function memberWithProjectRole(ProjectRole $role): User
    {
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'user_id' => $member->id,
            'role' => $role->value,
        ]);

        return $member;
    }

    /** A workspace member who belongs to one of the company's two projects. */
    private function memberOfTheOneProject(): User
    {
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        return $member;
    }

    private function otherProject(): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Project They Cannot See',
            'status' => 'active',
        ]);
    }

    private function datedRetainer(string $title, string $startsOn): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => $title,
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => $startsOn,
        ]);
    }

    private function monthlyRetainer(string $title, ?int $projectId = null): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $projectId,
            'title' => $title,
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);
    }

    private function agreementWithAnHourlyRate(): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Synthetic Agreement',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'hourly_rate_amount' => 15000,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function entry(array $attributes = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($attributes + [
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-04',
            'minutes' => 60,
            'description' => 'Synthetic work',
            'status' => 'draft',
        ]);
    }

    private function attachToInvoice(ClientTimeEntry $entry, string $status): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SYN-'.$status.'-'.$entry->id,
            'status' => $status,
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'time',
            'description' => 'Synthetic line',
            'quantity' => '1',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'sort_order' => 1,
        ]);
        $entry->invoiceLines()->attach($line->id, ['workspace_id' => $this->workspace->id]);

        return $invoice;
    }
}
