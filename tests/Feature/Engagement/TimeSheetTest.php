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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertStatus(409);

        $this->actingAs($this->manager)
            ->delete("/workspaces/{$this->workspace->public_id}/time-entries/{$onIssued->public_id}", [
                'expected_version' => AgentApiVersion::for($onIssued),
            ])
            ->assertStatus(409);

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
            ->assertStatus(409);

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
                ->where('months.0.capacity.0.worked_hours', 2)
                ->where('months.0.capacity.0.available_hours', 10)
                ->where('months.0.capacity.0.unused_hours', 8)
                // The draft half is reported separately rather than folded in.
                ->where('months.0.pending_minutes', 30));
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

        $mine = $this->entry(['worked_on' => '2026-07-04', 'description' => 'Theirs to see']);
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
