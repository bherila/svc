<?php

namespace Tests\Feature\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
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

    public function test_an_entry_on_a_sent_invoice_is_frozen_and_one_on_a_draft_is_not(): void
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
                ->where('months.0.entries.1.can_edit', true));
    }

    /**
     * A draft invoice is regenerated from its entries, so the row behind one is
     * still the operator's. Editing it through the screen has to work, or the
     * badge would be telling the truth while the button lied.
     */
    public function test_an_entry_attached_to_a_draft_invoice_can_still_be_edited(): void
    {
        $entry = $this->entry(['worked_on' => '2026-07-04', 'minutes' => 60]);
        $this->attachToInvoice($entry, 'draft');

        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/time-entries/{$entry->public_id}", [
                'expected_version' => AgentApiVersion::for($entry),
                'minutes' => 75,
            ])
            ->assertRedirect();

        $this->assertSame(75, (int) $entry->fresh()?->minutes);
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
