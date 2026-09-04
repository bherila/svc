<?php

namespace Tests\Feature\Engagement;

use App\Http\Requests\Engagement\ApproveTimeEntriesRequest;
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
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

class TimeSheetTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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

    public function test_time_on_a_draft_invoice_is_editable_but_issued_time_is_frozen(): void
    {
        $onDraft = $this->entry(['worked_on' => '2026-07-04']);
        $onIssued = $this->entry(['worked_on' => '2026-07-05']);

        $this->attachToInvoice($onDraft, 'draft');
        $this->attachToInvoice($onIssued, 'issued');

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Newest first: the issued one was worked a day later.
                ->where('months.0.entries.0.invoice.status', 'issued')
                ->where('months.0.entries.0.can_edit', false)
                ->where('months.0.entries.0.can_approve', false)
                ->where('months.0.entries.1.invoice.status', 'draft')
                ->where('months.0.entries.1.can_edit', true)
                ->where('months.0.entries.1.can_approve', false));
    }

    public function test_a_draft_without_a_supported_regeneration_path_is_not_advertised_as_editable(): void
    {
        $entry = $this->entry(['worked_on' => '2026-07-04']);
        $invoice = $this->attachToInvoice($entry, 'draft');
        $invoice->forceFill(['invoice_kind' => 'terminal'])->save();

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.entries.0.invoice.status', 'draft')
                ->where('months.0.entries.0.can_edit', false));
    }

    public function test_a_draft_with_an_unknown_invoice_kind_is_not_advertised_as_legacy_cadence(): void
    {
        $entry = $this->entry(['worked_on' => '2026-07-04']);
        $agreement = $this->agreementWithAnHourlyRate();
        $invoice = $this->attachToInvoice($entry, 'draft');
        $invoice->forceFill([
            'client_agreement_id' => $agreement->id,
            'invoice_kind' => 'future_generated_kind',
            'service_period_start' => '2026-07-01',
            'service_period_end' => '2026-07-31',
        ])->save();

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.entries.0.invoice.status', 'draft')
                ->where('months.0.entries.0.can_edit', false));
    }

    public function test_an_interim_draft_is_not_advertised_when_interim_billing_is_disabled(): void
    {
        $entry = $this->entry(['worked_on' => '2026-07-04']);
        $agreement = $this->agreementWithAnHourlyRate();
        $agreement->forceFill(['billing_cadence' => 'quarterly', 'bill_overage_interim' => false])->save();
        $invoice = $this->attachToInvoice($entry, 'draft');
        $invoice->forceFill([
            'client_agreement_id' => $agreement->id,
            'invoice_kind' => 'interim_overage',
            'service_period_start' => '2026-07-01',
            'service_period_end' => '2026-07-31',
            'cycle_start' => '2026-07-01',
            'cycle_end' => '2026-09-30',
        ])->save();

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.entries.0.invoice.status', 'draft')
                ->where('months.0.entries.0.can_edit', false));
    }

    public function test_an_interim_draft_without_a_complete_cycle_is_not_advertised_as_editable(): void
    {
        $entry = $this->entry(['worked_on' => '2026-07-04']);
        $agreement = $this->agreementWithAnHourlyRate();
        $agreement->forceFill(['billing_cadence' => 'quarterly', 'bill_overage_interim' => true])->save();
        $invoice = $this->attachToInvoice($entry, 'draft');
        $invoice->forceFill([
            'client_agreement_id' => $agreement->id,
            'invoice_kind' => 'interim_overage',
            'service_period_start' => '2026-07-01',
            'service_period_end' => '2026-07-31',
            'cycle_start' => null,
            'cycle_end' => null,
        ])->save();

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.entries.0.invoice.status', 'draft')
                ->where('months.0.entries.0.can_edit', false));
    }

    public function test_a_closing_cycle_interim_draft_is_not_advertised_as_editable(): void
    {
        $entry = $this->entry(['worked_on' => '2026-07-04']);
        $agreement = $this->agreementWithAnHourlyRate();
        $agreement->forceFill(['billing_cadence' => 'quarterly', 'bill_overage_interim' => true])->save();
        $invoice = $this->attachToInvoice($entry, 'draft');
        $invoice->forceFill([
            'client_agreement_id' => $agreement->id,
            'invoice_kind' => 'interim_overage',
            'service_period_start' => '2026-09-01',
            'service_period_end' => '2026-09-30',
            'cycle_start' => '2026-07-01',
            'cycle_end' => '2026-09-30',
        ])->save();

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.entries.0.invoice.status', 'draft')
                ->where('months.0.entries.0.can_edit', false));
    }

    public function test_a_draft_with_a_foreign_agreement_is_not_advertised_as_regenerable(): void
    {
        $entry = $this->entry(['worked_on' => '2026-07-04']);
        $otherWorkspace = Workspace::query()->create(['name' => 'Other sheet agreement', 'slug' => 'other-sheet-agreement']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Other Sheet Client',
            'slug' => 'other-sheet-client',
        ]);
        $foreignAgreement = ClientAgreement::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'title' => 'Foreign agreement',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'starts_on' => '2026-07-01',
        ]);
        $invoice = $this->attachToInvoice($entry, 'draft');
        $invoice->forceFill([
            'client_agreement_id' => $foreignAgreement->id,
            'invoice_kind' => 'cadence_period',
            'service_period_start' => '2026-07-01',
            'service_period_end' => '2026-07-31',
        ])->save();

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.entries.0.invoice.status', 'draft')
                ->where('months.0.entries.0.can_edit', false));
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
            ->assertRedirect();

        $this->actingAs($this->manager)
            ->delete("/workspaces/{$this->workspace->public_id}/time-entries/{$onIssued->public_id}", [
                'expected_version' => AgentApiVersion::for($onIssued),
            ])
            ->assertSessionHasErrors('engagement');

        $this->assertSame(75, (int) $onDraft->fresh()?->minutes);
        // A legacy draft-status row is not invoiceable until approval. The
        // regeneration therefore releases its stale synthetic line instead of
        // continuing to quote the old sixty-minute amount.
        $this->assertFalse($onDraft->fresh()?->invoiceLines()->exists());
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
     * The availability is broken down, and the carry-forward rule stated.
     *
     * `available_hours` alone is unarguable and unexplainable: a month living
     * on hours carried in reads exactly like one with a large retainer, and the
     * hours that aged out on the way are invisible. The ledger has computed all
     * three since the port; the screen was the part that was missing.
     */
    public function test_the_capacity_strip_says_where_the_hours_came_from(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Rolling Agreement',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'rollover_months' => 2,
            'starts_on' => '2026-06-01',
        ]);
        // June is left unworked, so July opens on hours carried in as well as
        // its own grant - which is the case the breakdown exists to show.
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 120, 'status' => 'approved']);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.capacity.0.retainer_hours', 10)
                ->where('months.0.capacity.0.rollover_in_hours', 10)
                ->where('months.0.capacity.0.available_hours', 20)
                ->where('months.0.capacity.0.expired_hours', 0)
                // The agreement's own rule, so the arithmetic above can be read
                // rather than only observed. Null would be a different claim -
                // that the agreement states no rollover at all.
                ->where('months.0.capacity.0.rollover_months', 2));
    }

    /**
     * A cycle that opens repaying an earlier overrun says so.
     *
     * The screen reported the gross grant and the availability and nothing
     * between them, so a month that sold ten hours and could offer five read
     * as an arithmetic error. The offset is the missing term, and the balance
     * is the figure an operator was left to derive from three others.
     */
    public function test_the_capacity_strip_accounts_for_hours_spent_repaying_an_overrun(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Rolling Agreement',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'rollover_months' => 1,
            'starts_on' => '2026-06-01',
        ]);
        // Fifteen hours against a ten-hour June: the five over are carried, not
        // billed, so July opens owing them.
        $this->entry(['worked_on' => '2026-06-04', 'minutes' => 900, 'status' => 'approved']);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.key', '2026-07')
                ->where('months.0.capacity.0.retainer_hours', 10)
                ->where('months.0.capacity.0.deficit_offset_hours', 5)
                ->where('months.0.capacity.0.available_hours', 5)
                ->where('months.0.capacity.0.balance_hours', 5)
                // And the month that ran up the debt closes on its negative.
                ->where('months.1.key', '2026-06')
                ->where('months.1.capacity.0.over_hours', 5)
                ->where('months.1.capacity.0.balance_hours', -5));
    }

    /**
     * Hours worked past the retainer and carried forward are unpaid; the same
     * hours once invoiced are paid. A strip reporting only what the retainer
     * included and how far the work went past it draws the two identically.
     */
    public function test_the_capacity_strip_separates_hours_paid_for_from_hours_included(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Charged Agreement',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'rollover_months' => 1,
            'starts_on' => '2026-07-01',
        ]);
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 780, 'status' => 'approved']);
        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SYN-OVERAGE-1',
            'status' => 'issued',
            'service_period_end' => '2026-07-31',
            'hours_billed_at_rate' => '3.0000',
            'currency' => 'USD',
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.capacity.0.retainer_hours', 10)
                ->where('months.0.capacity.0.over_hours', 3)
                ->where('months.0.capacity.0.billed_overage_hours', 3)
                ->where('months.0.capacity.0.paid_hours', 13)
                // The charge settles the debt it was raised for, so the cycle
                // closes square rather than three hours down.
                ->where('months.0.capacity.0.balance_hours', 0));
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
            'subcontractor_billing_mode' => 'direct',
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies.0.projects', 1)
                ->where('companies.0.projects.0.name', 'Synthetic Project')
                ->has('months.0.entries', 1)
                ->where('months.0.entries.0.id', $mine->public_id)
                ->missing('months.0.entries.0.subcontractor_billing_mode'));
    }

    /**
     * The screen offers the log form to exactly whoever the endpoint admits.
     *
     * That invariant is older than #101 and survives it; only the answer
     * changed. It used to hold by having the screen restate the workspace
     * `manage` gate on top of project access, because `store()` asked for both
     * - so a contributor was refused, and the form was correctly hidden. Now
     * `store()` asks `canLogTime` alone and the contributor is admitted, so the
     * form must be shown.
     *
     * Both roles in one test on purpose. Asserted separately, a screen that
     * always says true and an endpoint that always says true agree with each
     * other while agreeing about nothing, and the viewer half is what makes the
     * contributor half mean something.
     */
    public function test_the_screen_offers_the_log_form_to_exactly_whoever_may_use_it(): void
    {
        $contributor = $this->memberWithProjectRole(ProjectRole::Contributor);
        $viewer = $this->memberWithProjectRole(ProjectRole::Viewer);

        $this->actingAs($contributor)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.projects.0.can_log_time', true));

        $this->actingAs($contributor)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => $this->today(),
                'minutes' => 30,
                'description' => 'Offered and accepted',
            ])
            ->assertRedirect();

        $this->actingAs($viewer)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.projects.0.can_log_time', false));

        $this->actingAs($viewer)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => $this->today(),
                'minutes' => 30,
                'description' => 'Neither offered nor accepted',
            ])
            ->assertForbidden();

        $this->assertSame(1, ClientTimeEntry::query()->count());
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 1));

        $this->actingAs($member)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 0));

        // And the exclusion is the status, not something else about the row:
        // activating the same agreement produces the strip.
        $agreement->forceFill(['status' => 'active'])->save();

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
     * The workspace-wide sheet's URL still works, and resolves a client rather
     * than picking one.
     *
     * It used to choose the alphabetically first company and render its name
     * above whatever it had fetched. Now it goes through the same entry point
     * every other way into a workspace does, so the reader lands on the client
     * they last opened - on the module they asked for.
     */
    public function test_the_old_workspace_wide_url_lands_on_this_clients_time(): void
    {
        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/time")
            ->assertRedirect("/workspaces/{$this->workspace->public_id}?module=time")
            ->assertSessionMissing('errors');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}?module=time")
            ->assertRedirect("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time");
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
     * nothing else - it passes no access check of its own, so a row owned
     * elsewhere that points at a visible project satisfies the join.
     *
     * The schema now refuses to store such a row: `ct_ws_project_fk` ties
     * (workspace_id, client_project_id) to the project's own workspace. This
     * test pins the application-layer guard as defense in depth, for rows a
     * database migrated from before that key can still hold - so the fixture
     * is seeded with enforcement suspended and asserted with it back on.
     */
    public function test_a_task_owned_by_another_workspace_is_not_serialized(): void
    {
        $foreign = $this->foreignWorkspace();

        $this->writingLegacyCrossTenantRows(fn () => ClientTask::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_project_id' => $this->project->id,
            'title' => 'Foreign Task Title',
            'status' => 'open',
        ]));
        ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'Local Task Title',
            'status' => 'open',
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
        // Refused by ct_ws_project_fk since #113; seeded with enforcement
        // suspended so the row-level guard stays the subject of the test.
        $foreignTask = $this->writingLegacyCrossTenantRows(fn () => ClientTask::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_project_id' => $this->project->id,
            'title' => 'Foreign Row Task',
            'status' => 'open',
        ]));

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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
     * the invoice the line names.
     *
     * The schema now refuses both halves of that arrangement - ci_ws_company_fk
     * on the invoice and cil_ws_invoice_fk on the line. This test pins the
     * application-layer guard as defense in depth, for rows a database migrated
     * from before those keys can still hold.
     */
    public function test_an_invoice_owned_by_another_workspace_is_not_linked(): void
    {
        $foreign = $this->foreignWorkspace();
        $line = $this->writingLegacyCrossTenantRows(function () use ($foreign): ClientInvoiceLine {
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

            return ClientInvoiceLine::query()->create([
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
        });

        $entry = $this->entry(['worked_on' => '2026-07-04']);
        $entry->invoiceLines()->attach($line->id, ['workspace_id' => $this->workspace->id]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
        $foreignLine = $this->writingLegacyCrossTenantRows(function () use ($foreign): ClientInvoiceLine {
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

            return ClientInvoiceLine::query()->create([
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
        });

        $entry = $this->entry(['minutes' => 60]);
        // The pivot names this workspace's entry under the foreign one, which
        // cilte_ws_time_entry_fk refuses; suspended for the same reason.
        $this->writingLegacyCrossTenantRows(
            fn () => $entry->invoiceLines()->attach($foreignLine->id, ['workspace_id' => $foreign['workspace']->id]),
        );

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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
        $url = "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time";

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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
     * The pivot carries its own `workspace_id`. A foreign association must not
     * freeze this tenant's entry, and the freeze must agree with the badge -
     * refusing every write while showing no invoice explains nothing.
     *
     * Since #113 the pivot's workspace has to agree with both the line's and
     * the entry's - cilte_ws_line_fk and cilte_ws_time_entry_fk, the latter on
     * a column that carried no foreign key at all before. This test pins the
     * application-layer guard as defense in depth.
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
        $this->writingLegacyCrossTenantRows(
            fn () => $entry->invoiceLines()->attach($line->id, ['workspace_id' => $foreign['workspace']->id]),
        );

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => AgentApiVersion::for($entry),
                'minutes' => 75,
            ])
            ->assertRedirect();

        $this->assertSame(75, (int) $entry->fresh()?->minutes);
    }

    /**
     * The ledger gathers a company's work by `client_company_id` alone, so an
     * entry naming this company while pointing at another company's project is
     * excluded from the rows and counted in the total above them.
     *
     * Since #113 cte_ws_project_fk refuses this fixture, whose project belongs
     * to another workspace. It would not refuse the same muddle inside one
     * workspace - an entry naming this company and a sibling company's project
     * - because the composite keys tie a child to its workspace, not to its
     * company. The guard below is the only thing that catches that, so this
     * test still earns its place; the fixture is seeded with enforcement
     * suspended.
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
        $url = "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time";

        // Consistent to begin with: the strip is there.
        $this->actingAs($this->manager)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 1));

        // This entry is this company's as far as the ledger is concerned, and
        // its hours are another company's work.
        $this->writingLegacyCrossTenantRows(fn () => ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $foreign['project']->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-06',
            'minutes' => 300,
            'description' => 'Hours from somewhere else',
            'status' => 'approved',
        ]));

        $this->actingAs($this->manager)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 0));
    }

    public function test_capacity_is_withheld_when_company_time_claims_another_workspace(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer With Cross-Workspace Inputs',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'retainer_minutes' => 600,
            'starts_on' => '2026-07-01',
        ]);
        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60, 'status' => 'approved']);

        $this->travelTo('2026-07-20');
        $url = "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time";

        $this->actingAs($this->manager)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('months.0.capacity', 1));

        $foreign = $this->foreignWorkspace();
        // Refused by cte_ws_company_fk since #113; the capacity guard is what
        // is under test, so the fixture is seeded with enforcement suspended.
        $this->writingLegacyCrossTenantRows(fn () => ClientTimeEntry::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $foreign['project']->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-06',
            'minutes' => 300,
            'description' => 'Company work stored under another tenant',
            'status' => 'approved',
        ]));

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

        // Refused by ci_ws_company_fk since #113; the ledger's own scoping is
        // what is under test, so the fixture is seeded with enforcement
        // suspended and asserted with it back on.
        $foreignLine = $this->writingLegacyCrossTenantRows(function () use ($foreign): ClientInvoiceLine {
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

            return ClientInvoiceLine::query()->create([
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
        });

        $deferred = $this->entry([
            'worked_on' => '2026-07-04',
            'minutes' => 180,
            'status' => 'approved',
            'is_deferred' => true,
        ]);
        $this->writingLegacyCrossTenantRows(
            fn () => $deferred->invoiceLines()->attach($foreignLine->id, ['workspace_id' => $foreign['workspace']->id]),
        );

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            'subcontractor_billing_mode' => 'flat_hourly',
            'subcontractor_cost_currency' => 'USD',
        ]);

        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('months.0.capacity.0.pending_minutes', 60)
                ->where('months.0.entries.0.subcontractor_billing_mode', 'flat_hourly')
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
     *
     * cte_ws_project_fk now refuses to store that entry. This test pins the
     * authorisation guard as defense in depth, for rows a database migrated
     * from before that key can still hold.
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

        $entry = $this->writingLegacyCrossTenantRows(
            fn () => $this->entry(['client_project_id' => $foreign['project']->id]),
        );

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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
     *
     * cp_ws_company_fk and cte_ws_company_fk now refuse both of those rows.
     * This test pins the ownership walk as defense in depth, for rows a
     * database migrated from before those keys can still hold.
     */
    public function test_a_write_refuses_a_company_owned_by_another_workspace(): void
    {
        $foreign = $this->foreignWorkspace();
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'name' => 'Company Of Another Tenant',
            'slug' => 'company-of-another-tenant',
        ]);
        $entry = $this->writingLegacyCrossTenantRows(function () use ($foreignCompany): ClientTimeEntry {
            // This workspace's project, naming that workspace's company.
            $project = ClientProject::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $foreignCompany->id,
                'name' => 'Muddled Project',
                'status' => 'active',
            ]);

            return $this->entry([
                'client_company_id' => $foreignCompany->id,
                'client_project_id' => $project->id,
                'minutes' => 60,
            ]);
        });

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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
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
     * A rate stated on the entry is not a default to be replaced.
     * `TimeEntryWorkflow` records one when the operator types it at the point
     * of logging and marks it `explicit`; resolving the agreement rate over
     * the top makes the invoice charge a number nobody entered, on an
     * approval that asked for no change of rate.
     */
    public function test_approval_keeps_a_rate_the_operator_already_stated(): void
    {
        $this->agreementWithAnHourlyRate();
        $entry = $this->entry([
            'billing_rate_amount' => 22500,
            'billing_rate_source' => 'explicit',
        ]);

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/time-entries/approve", [
                'entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]],
            ])
            ->assertRedirect();

        $this->assertSame('approved', $entry->fresh()?->status);
        // Not 15000, which is what the agreement would have resolved to.
        $this->assertSame(22500, (int) $entry->fresh()?->billing_rate_amount);
        $this->assertSame('explicit', $entry->fresh()?->billing_rate_source);
    }

    /**
     * The ledger clips its last row at the termination date, so pending work
     * after it belongs to no segment - reporting it against the expired
     * retainer promises capacity that has ended.
     */
    public function test_pending_work_after_termination_is_not_claimed_by_the_retainer(): void
    {
        $agreement = $this->monthlyRetainer('Terminating Retainer');
        $agreement->forceFill(['ends_on' => '2026-07-15'])->save();

        $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);
        $this->entry(['worked_on' => '2026-07-20', 'minutes' => 300]);

        $this->travelTo('2026-07-25');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('months.0.capacity', 1)
                // Only the hour worked while the agreement was in force.
                ->where('months.0.capacity.0.pending_minutes', 60));
    }

    /**
     * Both screens default their date field from the workspace's calendar,
     * because the write validators bound it by the workspace's window. A
     * browser or server ahead of that calendar defaulted to a date its own
     * save would refuse.
     */
    public function test_both_screens_default_dates_from_the_workspace_calendar(): void
    {
        $this->workspace->forceFill(['timezone' => 'America/Los_Angeles'])->save();

        // Still 2026-08-31 in Los Angeles; UTC has reached September.
        $this->travelTo('2026-09-01 03:00:00');

        // The calendar travels, not a date. A date computed here would be
        // right at render and wrong by morning, and these pages are meant to
        // be left open; the browser reads the clock against this.
        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.timezone', 'America/Los_Angeles'));

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/operations")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.timezone', 'America/Los_Angeles'));

        // And that default is a date the write accepts.
        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => '2026-08-31',
                'minutes' => 60,
                'description' => 'Logged on the last day of the local month',
            ])
            ->assertRedirect();

        $this->assertSame(1, ClientTimeEntry::query()->count());
    }

    /**
     * The page must not offer a selection the write refuses: an over-limit
     * approval is rejected whole, and the operator is given no way to make it
     * smaller. The limit is named by the request that enforces it.
     */
    public function test_the_page_is_told_the_approval_limit_the_request_enforces(): void
    {
        $this->travelTo('2026-07-20');

        $this->actingAs($this->manager)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approval_limit', ApproveTimeEntriesRequest::MAX_ENTRIES));

        // And the request really does refuse one more than it advertises.
        $entries = [];

        for ($i = 0; $i <= ApproveTimeEntriesRequest::MAX_ENTRIES; $i++) {
            $entries[] = ['id' => 'synthetic-'.$i, 'expected_version' => 'v'];
        }

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/time-entries/approve", ['entries' => $entries])
            ->assertSessionHasErrors('entries');
    }

    /**
     * A rate with no currency is approved and then never billed.
     *
     * `InvoiceFromTimeService` takes only entries whose currency matches the
     * invoice's, so an entry carrying an amount and no currency is approved,
     * billable, rate-bearing - and silently absent from every invoice. The
     * web create path recorded a rate as `explicit` on the amount alone and
     * left the currency null, and keeping that rate at approval preserved the
     * hole rather than the agreement resolution filling it.
     */
    public function test_time_logged_with_a_rate_is_never_left_without_a_currency(): void
    {
        $this->agreementWithAnHourlyRate();

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => '2026-07-04',
                'minutes' => 60,
                'description' => 'Logged with a rate and no currency',
                'billing_rate_amount' => 22500,
            ])
            ->assertRedirect();

        $entry = ClientTimeEntry::query()->firstOrFail();

        $this->assertSame('explicit', $entry->billing_rate_source);
        $this->assertSame($this->workspace->fresh()?->default_currency, $entry->currency);

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/time-entries/approve", [
                'entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]],
            ])
            ->assertRedirect();

        $this->assertSame(22500, (int) $entry->fresh()?->billing_rate_amount);
        $this->assertNotNull($entry->fresh()?->currency);
    }

    /** And a row already stored without one still approves with one. */
    public function test_approval_supplies_a_currency_an_older_entry_lacks(): void
    {
        $this->agreementWithAnHourlyRate();
        $entry = $this->entry([
            'billing_rate_amount' => 22500,
            'billing_rate_source' => 'explicit',
            'currency' => null,
        ]);

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/time-entries/approve", [
                'entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]],
            ])
            ->assertRedirect();

        $this->assertSame(22500, (int) $entry->fresh()?->billing_rate_amount);
        $this->assertSame($this->workspace->fresh()?->default_currency, $entry->fresh()?->currency);
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

        $response = $this->actingAs($member)
            ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
            ->assertOk();

        $this->assertInertiaPayloadOmits($response, [
            'Project They Cannot See',
            'Hidden Worker Name',
            'Hidden Task Title',
            'Hidden Work Description',
            'Hidden Client Description',
            'HIDDEN-INVOICE-9001',
            'Hidden Agreement Title',
        ], 'Visible Work Description');
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

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($this->manager)
                ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
                ->assertOk(),
            function (): void {
                for ($i = 0; $i < 27; $i++) {
                    $this->entry(['worked_on' => '2026-07-05']);
                }
            },
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

        $this->assertQueriesNameOnlyRealIdentifiers(
            fn () => $this->actingAs($this->manager)
                ->get("/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/time")
                ->assertOk(),
        );
    }

    /** Identifier quoting differs by engine; the assertions here must not. */
    private static function unquote(string $sql): string
    {
        return str_replace(['`', '"'], '', $sql);
    }

    /**
     * A project contributor can log time from a browser, as they always could
     * through a token.
     *
     * The two doors disagreed: the web path asked `manage` on the workspace,
     * the agent API asked `canLogTime` on the project (#101). This is the
     * behaviour that changes - everything below is what must not change with it.
     */
    public function test_a_project_contributor_can_log_time_from_the_web(): void
    {
        $contributor = $this->memberWithProjectRole(ProjectRole::Contributor);

        $this->actingAs($contributor)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => $this->today(),
                'minutes' => 30,
                'description' => 'Synthetic contributor time.',
            ])
            ->assertRedirect();

        $entry = ClientTimeEntry::query()->latest('id')->sole();
        $this->assertSame($contributor->id, $entry->user_id);
    }

    /**
     * A viewer still cannot, on either door.
     *
     * `canLogTime()` is every project role except viewer, so this is the edge
     * the new gate turns on. Without it "both doors ask canLogTime" would be
     * satisfied by a rule that admitted everyone who can see the project.
     */
    public function test_a_project_viewer_cannot_log_time_from_the_web(): void
    {
        $viewer = $this->memberWithProjectRole(ProjectRole::Viewer);

        $this->actingAs($viewer)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => $this->today(),
                'minutes' => 30,
                'description' => 'Synthetic viewer time.',
            ])
            ->assertForbidden();

        $this->assertSame(0, ClientTimeEntry::query()->count());
    }

    /**
     * A workspace member with no project role cannot log time.
     *
     * Membership admits them to the workspace, not to a project in it. Asserted
     * because the old gate keyed on the workspace, so a rule that kept any part
     * of that reading would let this through.
     */
    public function test_a_workspace_member_with_no_project_role_cannot_log_time(): void
    {
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($member)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => $this->today(),
                'minutes' => 30,
                'description' => 'Synthetic outsider time.',
            ])
            ->assertForbidden();

        $this->assertSame(0, ClientTimeEntry::query()->count());
    }

    /**
     * Time is logged against the person logging it, whatever the payload says.
     *
     * The whole safety of admitting contributors rests on this: neither door
     * accepts a subject, so widening who may write does not widen whose time
     * they may write. It was true before this change and pinned by nothing -
     * the property most worth a test is the one everything else assumes.
     *
     * Both spellings are sent, because the workflow takes the actor as the
     * worker and a future edition that started reading the payload would
     * plausibly reach for either name.
     */
    public function test_time_is_attributed_to_the_actor_not_to_a_named_subject(): void
    {
        $contributor = $this->memberWithProjectRole(ProjectRole::Contributor);
        $someoneElse = $this->memberWithProjectRole(ProjectRole::Contributor);

        $this->actingAs($contributor)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => $this->today(),
                'minutes' => 30,
                'description' => 'Synthetic attributed time.',
                'user_id' => $someoneElse->id,
                'user' => $someoneElse->public_id,
            ])
            ->assertRedirect();

        $entry = ClientTimeEntry::query()->latest('id')->sole();
        $this->assertSame($contributor->id, $entry->user_id);
        $this->assertNotSame($someoneElse->id, $entry->user_id);
    }

    /**
     * A contributor may record work; naming its rate is a manager's decision.
     *
     * A rate supplied at creation is stored as `billing_rate_source =>
     * 'explicit'`, which outranks the rate the agreement would have resolved -
     * so this field prices the work rather than describing it. Refused as a
     * field error rather than a 403, because the request is a legitimate one
     * from someone entitled to make it with one field they may not set.
     */
    public function test_a_contributor_cannot_price_their_own_time(): void
    {
        $contributor = $this->memberWithProjectRole(ProjectRole::Contributor);

        $this->actingAs($contributor)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => $this->today(),
                'minutes' => 30,
                'description' => 'Synthetic priced time.',
                'billing_rate_amount' => 99999,
            ])
            ->assertSessionHasErrors('billing_rate_amount');

        $this->assertSame(0, ClientTimeEntry::query()->count());
    }

    /**
     * A project manager still can, so the guard narrows one role rather than
     * removing the field.
     */
    public function test_a_project_manager_can_state_the_rate(): void
    {
        $manager = $this->memberWithProjectRole(ProjectRole::Manager);

        $this->actingAs($manager)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$this->project->public_id}/time-entries", [
                'worked_on' => $this->today(),
                'minutes' => 30,
                'description' => 'Synthetic managed time.',
                'billing_rate_amount' => 12500,
            ])
            ->assertRedirect();

        $entry = ClientTimeEntry::query()->latest('id')->sole();
        $this->assertSame(12500, $entry->billing_rate_amount);
        $this->assertSame('explicit', $entry->billing_rate_source);
    }

    /**
     * A contributor logging time on a project in another workspace is not
     * found, not merely refused.
     *
     * The ownership assertion runs before the access question deliberately:
     * `canLogTime` reads the project's *own* workspace, so asking it about a
     * foreign project would answer honestly about the wrong workspace - and a
     * contributor there would be admitted through this workspace's URL.
     */
    public function test_a_project_from_another_workspace_is_not_loggable_here(): void
    {
        $contributor = $this->memberWithProjectRole(ProjectRole::Contributor);
        $foreign = $this->foreignWorkspace();

        // Workspace membership first: the project membership carries a
        // composite key into it, so the rows cannot be written the other way
        // round.
        $foreign['workspace']->memberships()->create(['user_id' => $contributor->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $foreign['workspace']->id,
            'client_project_id' => $foreign['project']->id,
            'user_id' => $contributor->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        $this->actingAs($contributor)
            ->post("/workspaces/{$this->workspace->public_id}/projects/{$foreign['project']->public_id}/time-entries", [
                'worked_on' => $this->today(),
                'minutes' => 30,
                'description' => 'Synthetic cross-tenant time.',
            ])
            ->assertNotFound();

        $this->assertSame(0, ClientTimeEntry::query()->count());
    }

    /**
     * A date the sheet's window always contains.
     *
     * The window is twelve months either side of now rather than a fixed span,
     * so a literal date would age out of it and start failing on the validation
     * rule instead of on what the test is about.
     */
    private function today(): string
    {
        return now()->toDateString();
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
            'starts_on' => '2026-01-01',
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
            'invoice_kind' => 'ad_hoc',
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
