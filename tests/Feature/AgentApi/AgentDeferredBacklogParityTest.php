<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * The deferred backlog is one figure on every surface (#366): REST and MCP
 * `capacity_ledger.get`, `operations.summary` and the artisan command all
 * print what DeferredBacklogReadService computes, inside the caller's own
 * workspace, for managers only.
 */
final class AgentDeferredBacklogParityTest extends TestCase
{
    use CallsMcp, RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        config(['app.url' => 'http://localhost']);
        $this->travelTo(Carbon::parse('2026-05-20 12:00:00'));
        $this->owner = User::factory()->create();
        [$this->workspace, $this->company, $this->project, $this->agreement] = $this->tenant('synthetic-backlog', $this->owner);
        // A 20 h/month retainer at 150.00/h with work deferred past the cap.
        $this->deferred($this->workspace, $this->company, $this->project, 120, '2026-03-04');
        $this->deferred($this->workspace, $this->company, $this->project, 90, '2026-04-11');
        $this->deferred($this->workspace, $this->company, $this->project, 30, '2026-05-02');
        $applied = $this->deferred($this->workspace, $this->company, $this->project, 60, '2026-03-20');
        $this->applyToRetainer($applied, '2026-04-30');
        $this->actingAsMcp($this->owner, ['mcp:use', 'billing:read', 'identity:read']);
    }

    public function test_rest_and_mcp_return_the_same_backlog_beside_the_ledger(): void
    {
        $rest = $this->getJson($this->ledgerPath($this->workspace, $this->agreement).'?months=3')->assertOk()->json('data');

        $this->assertSame($this->agreement->public_id, $rest['agreement_id']);
        $this->assertSame(['2026-03', '2026-04', '2026-05'], array_column($rest['months'], 'period'));
        $backlog = $rest['deferred_backlog'];
        $this->assertSame('2026-05-20', $backlog['through']);
        $this->assertEquals(4, $backlog['hours']);
        $this->assertSame(3, $backlog['entry_count']);
        $this->assertSame('2026-03-04', $backlog['oldest_worked_on']);
        $this->assertSame(['currency' => 'USD', 'amount' => 60000], $backlog['amount_at_hourly_rate']);
        $this->assertNull($backlog['issue']);
        // Its trend reads the ledger's own months.
        $this->assertSame(array_column($rest['months'], 'period'), array_column($backlog['trend'], 'period'));
        $this->assertEquals([3, 1.5, 0.5], array_column($backlog['trend'], 'hours_added'));
        $this->assertEquals([0, 1, 0], array_column($backlog['trend'], 'hours_applied'));
        $this->assertArrayNotHasKey('entry_ids', $backlog);

        $session = $this->initialize();
        $mcp = $this->callTool($session, 'capacity_ledger.get', [
            'workspace_id' => $this->workspace->public_id,
            'agreement_id' => $this->agreement->public_id,
            'months' => 3,
        ]);
        $this->assertFalse($mcp['result']['isError'] ?? true, json_encode($mcp));
        $this->assertSame($rest, $mcp['result']['structuredContent']['data']);

        $this->assertMatchesComponent('CapacityLedger', $rest);
    }

    public function test_the_months_parameter_bounds_both_the_ledger_and_the_trend(): void
    {
        $rest = $this->getJson($this->ledgerPath($this->workspace, $this->agreement).'?months=1')->assertOk()->json('data');

        $this->assertSame(['2026-05'], array_column($rest['months'], 'period'));
        $this->assertSame(['2026-05'], array_column($rest['deferred_backlog']['trend'], 'period'));
        $this->getJson($this->ledgerPath($this->workspace, $this->agreement).'?months=61')->assertUnprocessable();
    }

    public function test_the_summary_lists_the_backlog_for_managers_only(): void
    {
        $summary = $this->getJson('/api/v1/workspaces/'.$this->workspace->public_id.'/summary')->assertOk()->json('data');

        $this->assertSame(1, $summary['deferred_backlog']['agreement_count']);
        $this->assertFalse($summary['deferred_backlog']['truncated']);
        $row = $summary['deferred_backlog']['agreements'][0];
        $this->assertSame($this->agreement->public_id, $row['agreement_id']);
        $this->assertSame($this->company->public_id, $row['client_id']);
        $this->assertSame('http://localhost/workspaces/'.$this->workspace->public_id.'/clients/'.$this->company->public_id.'/agreements/'.$this->agreement->public_id, $row['web_url']);
        $this->assertEquals(4, $row['backlog']['hours']);
        $this->assertSame([], $row['backlog']['trend']);
        $this->assertMatchesComponent('SummaryDeferredBacklog', $summary['deferred_backlog']);

        $session = $this->initialize();
        $mcp = $this->callTool($session, 'operations.summary', ['workspace_id' => $this->workspace->public_id]);
        $this->assertSame($summary, $mcp['result']['structuredContent']['data']);

        $member = User::factory()->create();
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['mcp:use', 'billing:read', 'identity:read']);
        $this->assertArrayNotHasKey('deferred_backlog', $this->getJson('/api/v1/workspaces/'.$this->workspace->public_id.'/summary')->assertOk()->json('data'));

        $this->actingAsMcp($this->owner, ['mcp:use', 'identity:read']);
        $this->assertArrayNotHasKey('deferred_backlog', $this->getJson('/api/v1/workspaces/'.$this->workspace->public_id.'/summary')->assertOk()->json('data'));
    }

    public function test_another_workspace_sees_neither_the_agreement_nor_its_backlog(): void
    {
        $stranger = User::factory()->create();
        [$otherWorkspace, $otherCompany, $otherProject, $otherAgreement] = $this->tenant('synthetic-backlog-other', $stranger);
        $this->deferred($otherWorkspace, $otherCompany, $otherProject, 600, '2026-04-01');

        // The other tenant's agreement through this workspace's path, and this
        // workspace's agreement for a principal who is not a member of it, are
        // both simply not found.
        $this->getJson($this->ledgerPath($this->workspace, $otherAgreement))->assertNotFound();
        $this->getJson($this->ledgerPath($this->workspace, $this->agreement).'?months=3')
            ->assertOk()->assertJsonPath('data.deferred_backlog.hours', 4);
        $this->actingAsMcp($stranger, ['mcp:use', 'billing:read', 'identity:read']);
        $this->getJson($this->ledgerPath($this->workspace, $this->agreement))->assertNotFound();
        $this->getJson('/api/v1/workspaces/'.Str::uuid()->toString().'/agreements/'.$this->agreement->public_id.'/capacity-ledger')->assertNotFound();

        $own = $this->getJson('/api/v1/workspaces/'.$otherWorkspace->public_id.'/summary')->assertOk()->json('data');
        $this->assertSame([$otherAgreement->public_id], array_column($own['deferred_backlog']['agreements'], 'agreement_id'));
        $this->assertEquals(10, $own['deferred_backlog']['agreements'][0]['backlog']['hours']);
    }

    public function test_the_artisan_command_prints_the_same_backlog(): void
    {
        $rest = $this->getJson($this->ledgerPath($this->workspace, $this->agreement).'?months=3')->assertOk()->json('data.deferred_backlog');

        $this->assertSame(0, Artisan::call('svc:billing:deferred-backlog', [
            'workspace' => $this->workspace->public_id,
            'agreement' => $this->agreement->public_id,
            '--months' => 3,
            '--format' => 'json',
        ]));
        $this->assertEquals(['data' => $rest], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));

        $this->artisan('svc:billing:deferred-backlog', [
            'workspace' => $this->workspace->public_id,
            'agreement' => $this->agreement->public_id,
        ])->expectsOutputToContain('600.00 USD')->assertSuccessful();

        $other = Workspace::query()->create(['name' => 'Synthetic elsewhere', 'slug' => 'synthetic-backlog-elsewhere']);
        $this->artisan('svc:billing:deferred-backlog', ['workspace' => $other->public_id, 'agreement' => $this->agreement->public_id])
            ->expectsOutput('Agreement not found in the requested workspace.')->assertFailed();
        $this->artisan('svc:billing:deferred-backlog', ['workspace' => $this->workspace->public_id, 'agreement' => $this->agreement->public_id, '--months' => 0])
            ->assertExitCode(2);
        $this->artisan('svc:billing:deferred-backlog', ['workspace' => $this->workspace->public_id, 'agreement' => $this->agreement->public_id, '--format' => 'xml'])
            ->assertExitCode(2);
    }

    /** @param array<string, mixed> $value */
    private function assertMatchesComponent(string $component, array $value): void
    {
        $schema = AgentApiResponseSchemaCatalog::schema($component);
        $this->assertSame([], array_values(array_diff(array_keys($value), array_keys($schema['properties']))), $component);
        $this->assertSame([], array_values(array_diff($schema['required'], array_keys($value))), $component);
    }

    private function ledgerPath(Workspace $workspace, ClientAgreement $agreement): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/agreements/'.$agreement->public_id.'/capacity-ledger';
    }

    /** @return array{Workspace, ClientCompany, ClientProject, ClientAgreement} */
    private function tenant(string $slug, User $owner): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$slug, 'slug' => $slug]);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client '.$slug, 'slug' => $slug.'-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Synthetic retainer',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 1200,
            'hourly_rate_amount' => 15000,
        ]);

        return [$workspace, $company, $project, $agreement];
    }

    private function deferred(Workspace $workspace, ClientCompany $company, ClientProject $project, int $minutes, string $workedOn): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $this->owner->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Synthetic deferred work',
            'is_billable' => true,
            'is_deferred' => true,
            'status' => 'approved',
            'billing_rate_amount' => 15000,
            'currency' => 'USD',
        ]);
    }

    private function applyToRetainer(ClientTimeEntry $entry, string $lineDate): void
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $this->agreement->id,
            'invoice_number' => 'SVC-SYN-BACKLOG-1',
            'hours_billed_at_rate' => 0,
            'currency' => 'USD',
            'status' => 'issued',
            'service_period_end' => $lineDate,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $this->agreement->id,
            'type' => 'prior_month_retainer',
            'description' => 'Deferred work items applied to retainer (1:00)',
            'quantity' => '1.0000',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'hours' => 1,
            'line_date' => $lineDate,
            'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);
    }
}
