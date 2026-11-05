<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\TimeEntryProjectChainGuard;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class DeferredWorkSnapshotLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientAgreement $agreement;

    private ClientProject $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        $this->owner = User::factory()->create(['email' => 'snapshot-owner@example.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic snapshot workspace', 'slug' => 'synthetic-snapshot']);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic snapshot client', 'slug' => 'synthetic-snapshot']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic snapshot project']);
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic snapshot retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-04-01',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000,
        ]);
        $this->entry('2026-05-10', 600, deferred: false);
    }

    public function test_issue_takes_the_current_deferred_snapshot_and_later_documents_keep_it_frozen(): void
    {
        $first = $this->entry('2026-04-02', 60, description: 'Synthetic first issued work');
        $draft = $this->draft();
        $laterApproval = $this->entry('2026-04-01', 120, status: 'draft', description: 'Synthetic later approved work');
        $laterApproval->update(['status' => 'approved']);

        $issued = app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);

        $snapshot = $issued->deferredWorkSnapshot();
        $this->assertNotNull($snapshot);
        $this->assertSame([$laterApproval->id, $first->id], array_column($snapshot->toArray()['entries'], 'id'));
        $this->assertSame(3.0, $snapshot->documentData()['total_hours']);
        $this->assertSame(100000, $issued->total_amount, 'The document refresh changes no charge.');
        $this->assertSame('approved', $laterApproval->fresh()?->status, 'Refreshing the document never bills further time.');
        $this->assertSame(0, $laterApproval->invoiceLines()->count());
        $frozen = $snapshot->toArray();

        $laterApproval->update(['minutes' => 180, 'client_visible_description' => 'Synthetic changed after issue']);
        $this->entry('2026-04-03', 240, description: 'Synthetic approved after issue');
        $fresh = $issued->fresh();
        $this->assertInstanceOf(ClientInvoice::class, $fresh);
        $this->assertSame($frozen, $fresh->deferredWorkSnapshot()?->toArray());
        $this->assertSame($frozen, app(InvoiceLifecycleService::class)->issue($fresh, $this->workspace)->deferredWorkSnapshot()?->toArray(), 'An idempotent issue does not refresh a committed document.');
        $portal = AgentInvoicePresenter::portalDetails($fresh)['deferred_work'];
        $this->assertSame($snapshot->documentData(), $portal);
        $html = app(InvoiceDocumentService::class)->html($fresh)->render();
        $this->assertStringContainsString('Synthetic later approved work', $html);
        $this->assertStringContainsString('3.0000 hours', $html);
        $this->assertStringNotContainsString('Synthetic changed after issue', $html);
        $this->assertStringNotContainsString('Synthetic approved after issue', $html);
    }

    public function test_issue_redacts_a_description_whose_client_visibility_was_revoked_after_generation(): void
    {
        $entry = $this->entry('2026-04-01', 60, description: 'Synthetic client text made private');
        $draft = $this->draft();
        $this->assertSame('Synthetic client text made private', $draft->deferredWorkSnapshot()?->entries[0]['description']);
        $entry->update(['is_visible_to_client' => false]);

        $issued = app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);

        $this->assertSame(null, $issued->deferredWorkSnapshot()?->entries[0]['description']);
        $this->assertSame(1.0, $issued->deferredWorkSnapshot()?->documentData()['total_hours']);
        $this->assertStringNotContainsString('Synthetic client text made private', app(InvoiceDocumentService::class)->html($issued)->render());
        $this->assertSame(null, AgentInvoicePresenter::portalDetails($issued)['deferred_work']['entries'][0]['description']);
    }

    public function test_the_issue_time_refresh_stays_inside_the_invoices_workspace_company_and_project(): void
    {
        $this->agreement->update(['client_project_id' => $this->project->id]);
        $wanted = $this->entry('2026-04-01', 60, description: 'Synthetic intended snapshot work');
        $draft = $this->draft();
        $foreignWorkspace = Workspace::query()->create(['name' => 'Synthetic foreign snapshot workspace', 'slug' => 'foreign-snapshot']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreignWorkspace->id, 'name' => 'Synthetic foreign snapshot client', 'slug' => 'foreign-snapshot']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic other snapshot client', 'slug' => 'other-snapshot']);
        foreach ([[$foreignWorkspace, $foreignCompany], [$this->workspace, $otherCompany], [$this->workspace, $this->company]] as [$workspace, $company]) {
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic excluded snapshot project']);
            $other = $wanted->replicate(['public_id']);
            $other->fill(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                'client_visible_description' => 'Synthetic excluded snapshot work']);
            $other->save();
        }

        $issued = app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);

        $this->assertSame([$wanted->id], array_column($issued->deferredWorkSnapshot()?->entries ?? [], 'id'));
        $this->assertStringNotContainsString('Synthetic excluded snapshot work', app(InvoiceDocumentService::class)->html($issued)->render());
        $this->assertSame('Synthetic intended snapshot work', AgentInvoicePresenter::portalDetails($issued)['deferred_work']['entries'][0]['description']);
    }

    /** @return iterable<string, array{bool}> */
    public static function mismatchedProjects(): iterable
    {
        yield 'different company' => [false];
        yield 'different workspace' => [true];
    }

    #[DataProvider('mismatchedProjects')]
    public function test_issue_refuses_a_project_chain_corrupted_after_generation(bool $otherTenant): void
    {
        $wanted = $this->entry('2026-04-01', 60, description: 'Synthetic intended snapshot work');
        $draft = $this->draft();
        $before = $draft->deferred_work_statement;
        $workspace = $otherTenant
            ? Workspace::query()->create(['name' => 'Synthetic corrupt snapshot workspace', 'slug' => 'corrupt-snapshot'])
            : $this->workspace;
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic corrupt snapshot company', 'slug' => 'corrupt-snapshot']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic corrupt snapshot project']);
        $corrupt = $this->entry('2026-04-02', 60, description: 'Synthetic foreign project text');
        $this->writingLegacyCrossTenantRows(fn () => $corrupt->forceFill(['client_project_id' => $project->id])->save());

        try {
            app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);
            $this->fail('Issue must recheck the same project-chain guard as generation.');
        } catch (DomainException $refusal) {
            $this->assertSame(TimeEntryProjectChainGuard::FAILURE_MESSAGE, $refusal->getMessage());
        }

        $fresh = $draft->fresh();
        $this->assertSame('draft', $fresh?->status);
        $this->assertSame($before, $fresh?->deferred_work_statement);
        $this->assertSame([$wanted->id], array_column($fresh?->deferredWorkSnapshot()?->entries ?? [], 'id'));
        $this->assertStringNotContainsString('Synthetic foreign project text', app(InvoiceDocumentService::class)->html($fresh)->render());
    }

    private function draft(): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateInvoice($this->company, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), $this->agreement);
    }

    private function entry(string $date, int $minutes, bool $deferred = true, string $status = 'approved', ?string $description = null): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->owner->id,
            'worked_on' => $date, 'minutes' => $minutes, 'description' => 'Synthetic private internal work',
            'is_billable' => true, 'is_deferred' => $deferred, 'status' => $status,
            'billing_rate_amount' => 10000, 'currency' => 'USD',
            'is_visible_to_client' => $description !== null, 'client_visible_description' => $description,
        ]);
    }
}
