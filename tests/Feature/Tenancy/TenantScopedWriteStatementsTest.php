<?php

namespace Tests\Feature\Tenancy;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientStripeCustomer;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentTaskMutationAction;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\InvoiceLineComposer;
use App\Services\Billing\StripePaymentMethodService;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\Concerns\CapturesTenantOwnedWrites;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * Every tenant-owned write, in the flows that issue one, names its workspace.
 *
 * `AgentInvoiceLifecycleIntegrityTest` asserts this property over the invoice
 * lifecycle, and #230 stopped there - which is exactly the problem. That test
 * proved the four writes its own flow reached, the merged documentation then
 * claimed the property repository-wide, and #231 had to enumerate seventeen
 * counterexamples by searching the source again months later.
 *
 * A list of call sites cannot close that gap, because the gap is the write
 * nobody listed. Each test here drives a real flow, reads every statement it
 * issued, and refuses any update or delete against a table the *schema* says
 * is tenant-owned whose predicate omits the workspace. One test per flow
 * rather than one big one, so a failure names the flow that regressed.
 *
 * None of these was a reachable cross-tenant write: every one was keyed by an
 * id that came from a workspace-scoped read. That is the reasoning this
 * discipline exists to stop relying on - it is a property of today's callers,
 * re-established by hand at each new one, and invisible in the statement.
 */
final class TenantScopedWriteStatementsTest extends TestCase
{
    use CapturesTenantOwnedWrites;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Scoped Writes', 'slug' => 'scoped-writes']);
        $this->workspace->memberships()->create(['user_id' => ($this->user = User::factory()->create())->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Scoped Client', 'slug' => 'scoped-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Scoped Project',
        ]);
    }

    public function test_editing_a_time_entry_names_the_workspace(): void
    {
        $entry = $this->entry();

        $writes = $this->writesIssuedBy(function () use ($entry): void {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->user,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 180],
            );
        });

        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['client_time_entries']);
        // And the edit landed: a predicate naming the wrong workspace would
        // leave the statement matching no row while the call still returned.
        $this->assertSame(180, $entry->fresh()?->minutes);
    }

    public function test_deleting_a_time_entry_names_the_workspace(): void
    {
        $entry = $this->entry();

        $writes = $this->writesIssuedBy(function () use ($entry): void {
            app(TimeEntryMutationService::class)->delete(
                $this->workspace,
                $entry,
                $this->user,
                AgentApiVersion::for($entry),
            );
        });

        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['client_time_entries']);
        $this->assertNull(ClientTimeEntry::query()->find($entry->id));
    }

    public function test_editing_a_task_names_the_workspace(): void
    {
        config(['agent_api.writes_enabled' => true]);
        $task = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'Deliverable',
            'status' => 'open',
        ]);

        $writes = $this->writesIssuedBy(function () use ($task): void {
            app(AgentTaskMutationAction::class)->update(
                $this->user,
                $this->workspace,
                $this->company->public_id,
                (string) Str::uuid(),
                $task->public_id,
                ['expected_version' => AgentApiVersion::for($task), 'title' => 'Renamed deliverable'],
            );
        });

        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['client_tasks']);
        $this->assertSame('Renamed deliverable', $task->fresh()?->title);
    }

    /**
     * Voiding releases the invoice's time, its pivot rows and its milestone
     * claims - three statements that travelled on line ids alone.
     */
    public function test_voiding_an_invoice_releases_its_allocations_by_workspace(): void
    {
        [$invoice, $entry, , $task] = $this->issuedInvoiceWithAllocations();

        $writes = $this->writesIssuedBy(function () use ($invoice): void {
            app(InvoiceLifecycleService::class)->void($invoice->refresh(), $this->workspace, 'Synthetic void');
        });

        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['client_invoices', 'client_time_entries']);
        // The release actually happened. Each of these would still read as it
        // does now if the predicates matched nothing, which is why the void
        // returning without error proves nothing on its own.
        $this->assertSame('approved', $entry->fresh()?->status);
        $this->assertNull($task->fresh()?->client_invoice_line_id);
    }

    /**
     * Scoping a release has to refuse a foreign row, not skip it.
     *
     * This repository accommodates pre-composite-key tenant chains, so a legacy
     * invoice can carry a pivot row or a milestone claim stamped with another
     * workspace. Adding a workspace predicate to the releases without a guard
     * would quietly omit such a row: the invoice would still be voided, and the
     * work it held would stay invoiced or claimed for good, with nothing said.
     * A silent under-release is no better than the unscoped write it replaced.
     *
     * Two cases because the two halves fail differently and are guarded
     * separately - the pivot by its own check, the milestone by its own.
     */
    public function test_voiding_refuses_an_allocation_owned_by_another_workspace(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere-'.Str::random(6)]);
        [$invoice, $entry, $line] = $this->issuedInvoiceWithAllocations();

        $this->writingLegacyCrossTenantRows(function () use ($line, $elsewhere): void {
            DB::table('client_invoice_line_time_entries')
                ->where('client_invoice_line_id', $line->id)
                ->update(['workspace_id' => $elsewhere->id]);
        });

        try {
            app(InvoiceLifecycleService::class)->void($invoice->refresh(), $this->workspace, 'Synthetic void');
            $this->fail('Voiding must refuse an invoice holding another workspace\'s time allocation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('time allocation owned by another workspace', $exception->getMessage());
        }

        // Nothing was half-released on the way to the refusal, and the invoice
        // is not left void with work stranded on it.
        $this->assertSame('invoiced', $entry->fresh()?->status);
        $this->assertNotSame('void', $invoice->fresh()?->status);
    }

    public function test_voiding_refuses_a_milestone_claimed_by_another_workspace(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere-'.Str::random(6)]);
        [$invoice, , , $task] = $this->issuedInvoiceWithAllocations();

        $this->writingLegacyCrossTenantRows(function () use ($task, $elsewhere): void {
            DB::table('client_tasks')->where('id', $task->id)->update(['workspace_id' => $elsewhere->id]);
        });

        try {
            app(InvoiceLifecycleService::class)->void($invoice->refresh(), $this->workspace, 'Synthetic void');
            $this->fail('Voiding must refuse an invoice holding another workspace\'s milestone claim.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('milestone allocation owned by another workspace', $exception->getMessage());
        }

        $this->assertNotNull($task->fresh()?->client_invoice_line_id);
        $this->assertNotSame('void', $invoice->fresh()?->status);
    }

    public function test_claiming_a_milestone_names_the_workspace(): void
    {
        $this->agreement();
        $task = $this->milestone();
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-MILESTONE-1',
            'currency' => 'USD',
            'status' => 'draft',
            'service_period_end' => '2026-03-31',
        ]);
        $sort = 0;

        $writes = $this->writesIssuedBy(function () use ($invoice, &$sort): void {
            app(InvoiceLineComposer::class)->addBillableMilestoneTasks(
                $this->company,
                $invoice,
                Carbon::parse('2026-03-31'),
                $sort,
            );
        });

        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['client_tasks']);
        $this->assertNotNull($task->fresh()?->client_invoice_line_id, 'The claim has to have landed, or the sweep read a no-op.');
    }

    /**
     * An operator tool that can be pointed at any company is the last place to
     * leave a delete naming no tenant, so it gets no exemption.
     */
    public function test_the_portal_access_command_deletes_by_workspace(): void
    {
        $portalUser = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'user_id' => $portalUser->id,
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);
        $this->project->forceFill(['is_visible_to_client' => true])->save();

        $narrowing = $this->writesIssuedBy(function () use ($portalUser): void {
            $this->artisan('svc:portal:project-access', [
                'company' => $this->company->public_id,
                'email' => $portalUser->email,
                '--project' => [$this->project->public_id],
            ])->assertSuccessful();
        });
        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($narrowing, ['client_portal_project_access']);

        $restoring = $this->writesIssuedBy(function () use ($portalUser): void {
            $this->artisan('svc:portal:project-access', [
                'company' => $this->company->public_id,
                'email' => $portalUser->email,
                '--company-wide' => true,
            ])->assertSuccessful();
        });
        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($restoring, ['client_portal_project_access']);
        $this->assertSame(0, DB::table('client_portal_project_access')->count());
    }

    /**
     * Replay blanks invoices and releases their time across a whole workspace.
     * It takes that workspace as an option and reads by it; everything after
     * the selection travelled on ids.
     *
     * `clear()` is invoked directly, as `ReplayInvoicesTest` does for
     * `snapshot()`: the surrounding command rolls its transaction back, which
     * would undo the very statements this is reading.
     */
    public function test_replay_clears_a_workspaces_invoices_by_workspace(): void
    {
        $this->generatedHistory();
        $before = ClientInvoice::query()->where('workspace_id', $this->workspace->id)->count();
        $this->assertGreaterThan(0, $before, 'The history has to exist, or clearing it proves nothing.');

        $clear = new ReflectionMethod(ReplayInvoicesCommand::class, 'clear');
        $writes = $this->writesIssuedBy(function () use ($clear): void {
            $clear->invoke(app(ReplayInvoicesCommand::class), $this->workspace, collect([$this->company]));
        });

        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['client_invoices']);
        $this->assertSame(
            0,
            ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('status', '!=', 'draft')->count(),
            'Every invoice should be back to draft, or the predicates matched nothing.',
        );
    }

    /**
     * The one write whose workspace may legitimately be null.
     *
     * The receiver inserts a state row before anything knows which tenant the
     * payment method belongs to, so the statement that stamps one cannot name
     * the destination - it would match nothing on exactly the transition that
     * matters. It names the workspace the row currently carries instead, which
     * is `NULL` while unresolved. Both halves are exercised here, because a
     * predicate that handles only the resolved case silently stops stamping.
     */
    public function test_a_stripe_state_row_transitions_out_of_the_workspace_it_is_in(): void
    {
        $service = app(StripePaymentMethodService::class);
        $providerId = 'pm_synthetic_1';

        // Unresolved: no customer row, so the tenant cannot be determined and
        // the state row keeps its null workspace.
        $unresolved = $this->writesIssuedBy(function () use ($service, $providerId): void {
            $service->attach(['id' => $providerId, 'customer' => 'cus_unknown', 'type' => 'card'], 'evt_1', 1000);
        });
        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($unresolved, ['stripe_payment_method_states']);
        $this->assertNull($this->stateWorkspaceId($providerId));

        // Resolved: the customer now maps to this tenant, so the same row is
        // stamped. The predicate has to be `workspace_id is null` here - the
        // destination would match nothing.
        ClientStripeCustomer::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'stripe_customer_id' => 'cus_known',
        ]);

        $resolved = $this->writesIssuedBy(function () use ($service, $providerId): void {
            $service->attach(['id' => $providerId, 'customer' => 'cus_known', 'type' => 'card'], 'evt_2', 2000);
        });
        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($resolved, ['stripe_payment_method_states']);
        $this->assertSame(
            $this->workspace->id,
            $this->stateWorkspaceId($providerId),
            'The row has to actually move, or the null predicate is scoping it out of its own transition.',
        );

        // And once stamped, the row transitions out of the workspace it is in
        // rather than out of nothing.
        $detached = $this->writesIssuedBy(function () use ($service, $providerId): void {
            $service->detach($providerId, 'evt_3', 3000);
        });
        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($detached, ['stripe_payment_method_states']);
        $this->assertSame('detached', (string) DB::table('stripe_payment_method_states')
            ->where('provider_id_hash', hash('sha256', $providerId))
            ->value('state'));
    }

    /**
     * An issued invoice holding one time allocation and one milestone claim.
     *
     * @return array{ClientInvoice, ClientTimeEntry, ClientInvoiceLine, ClientTask}
     */
    private function issuedInvoiceWithAllocations(): array
    {
        $agreement = $this->agreement();
        $entry = $this->entry(['status' => 'approved']);
        $task = $this->milestone();
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SVC-VOID-'.Str::random(6),
            'currency' => 'USD',
            'status' => 'draft',
            'service_period_start' => '2026-03-01',
            'service_period_end' => '2026-03-31',
        ]);
        $line = $invoice->lines()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'additional_hours',
            'description' => 'Work',
            'quantity' => '1',
            'unit_amount' => 30000,
            'tax_amount' => 0,
            'total_amount' => 30000,
            'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);
        $entry->forceFill(['status' => 'invoiced'])->save();
        $task->forceFill(['client_invoice_line_id' => $line->id])->save();
        app(InvoiceLifecycleService::class)->issue($invoice->refresh(), $this->workspace);

        return [$invoice, $entry, $line, $task];
    }

    private function stateWorkspaceId(string $providerId): ?int
    {
        $value = DB::table('stripe_payment_method_states')
            ->where('provider_id_hash', hash('sha256', $providerId))
            ->value('workspace_id');

        return $value === null ? null : (int) $value;
    }

    /** @param array<string, mixed> $overrides */
    private function entry(array $overrides = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($overrides + [
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2026-03-14',
            'minutes' => 60,
            'description' => 'Work',
            'is_billable' => true,
            'status' => 'draft',
            'currency' => 'USD',
        ]);
    }

    private function milestone(): ClientTask
    {
        return ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'Deliverable',
            'status' => 'completed',
            'completed_at' => '2026-03-20',
            'milestone_price_amount' => 18750,
        ]);
    }

    private function agreement(): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'hourly_rate_amount' => 30000,
            'starts_on' => '2026-01-01',
        ]);
    }

    private function generatedHistory(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Replayable Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 2,
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2024-02-14',
            'minutes' => 900,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        Carbon::setTestNow(Carbon::parse('2024-06-15'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        } finally {
            Carbon::setTestNow();
        }
    }
}
