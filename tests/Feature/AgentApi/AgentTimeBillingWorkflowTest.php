<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AgentTimeBillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_can_log_approve_and_invoice_time_using_an_effective_agreement_rate(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->agreement($workspace, $company, $project, 12345, '2026-08-01');
        $this->actingAsAgent($owner, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::TIME_APPROVE,
            AgentApiScopes::BILLING_WRITE,
        ]);
        $session = $this->initialize();

        $logged = $this->callTool($session, 'time_entries.log', [
            'workspace_id' => $workspace->public_id,
            'idempotency_key' => 'workflow-log',
            'entries' => [[
                'project_id' => $project->public_id,
                'worked_on' => '2026-08-23',
                'minutes' => 25,
                'description' => 'MCP invoice-ready work',
            ]],
        ]);
        $entry = $logged['structuredContent']['data'][0];
        $this->assertArrayNotHasKey('billing_rate_amount', $entry);

        $approved = $this->callTool($session, 'time_entries.approve', [
            'workspace_id' => $workspace->public_id,
            'idempotency_key' => 'workflow-approve',
            'entries' => [['id' => $entry['id'], 'expected_version' => $entry['version']]],
        ]);
        $this->assertSame([$entry['id']], $approved['structuredContent']['data']['approved_ids']);
        $this->assertDatabaseHas('client_time_entries', [
            'public_id' => $entry['id'],
            'status' => 'approved',
            'billing_rate_amount' => 12345,
            'currency' => 'USD',
        ]);

        $draft = $this->callTool($session, 'invoices.create_draft', [
            'workspace_id' => $workspace->public_id,
            'company_id' => $company->public_id,
            'idempotency_key' => 'workflow-invoice',
            'time_entry_ids' => [$entry['id']],
        ]);
        $invoice = ClientInvoice::query()->where('public_id', $draft['structuredContent']['data']['id'])->with('lines')->sole();
        $this->assertSame(5144, $invoice->total_amount);
        $this->assertSame(5144, $invoice->lines->sole()->total_amount);
        $this->assertSame('0.4167', $invoice->lines->sole()->quantity);
    }

    public function test_approval_uses_the_most_specific_effective_rate_and_snapshots_it(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->agreement($workspace, $company, null, 10000, '2026-01-01');
        $olderProjectRate = $this->agreement($workspace, $company, $project, 15000, '2026-08-01');
        $newerProjectRate = $this->agreement($workspace, $company, $project, 18000, '2026-08-20');
        $entries = collect([
            ['date' => '2026-07-31', 'rate' => 10000],
            ['date' => '2026-08-10', 'rate' => 15000],
            ['date' => '2026-08-23', 'rate' => 18000],
        ])->map(fn (array $case): array => [
            'entry' => ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project->id,
                'user_id' => $owner->id,
                'worked_on' => $case['date'],
                'minutes' => 60,
                'description' => 'Effective rate test',
                'is_billable' => true,
                'status' => 'draft',
                'currency' => 'CAD',
            ]),
            'rate' => $case['rate'],
        ]);
        $this->actingAsAgent($owner, [AgentApiScopes::TIME_APPROVE]);

        $this->withHeader('Idempotency-Key', 'effective-rates')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/time-entries/approve",
            ['entries' => $entries->map(fn (array $case): array => [
                'id' => $case['entry']->public_id,
                'expected_version' => AgentApiVersion::for($case['entry']),
            ])->all()],
        )->assertOk();

        foreach ($entries as $case) {
            $fresh = $case['entry']->fresh();
            $this->assertSame($case['rate'], $fresh->billing_rate_amount);
            $this->assertSame('USD', $fresh->currency);
        }
        $olderProjectRate->update(['hourly_rate_amount' => 25000]);
        $newerProjectRate->update(['hourly_rate_amount' => 30000]);
        foreach ($entries as $case) {
            $this->assertSame($case['rate'], $case['entry']->fresh()->billing_rate_amount);
        }
    }

    public function test_missing_rate_blocks_billable_approval_but_manager_override_and_nonbillable_time_work(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $billable = $this->time($owner, $workspace, $company, $project, true);
        $nonbillable = $this->time($owner, $workspace, $company, $project, false);
        $this->actingAsAgent($owner, [AgentApiScopes::TIME_APPROVE]);
        $path = "/api/v1/workspaces/{$workspace->public_id}/time-entries/approve";

        $this->withHeader('Idempotency-Key', 'missing-rate')->postJson($path, ['entries' => [[
            'id' => $billable->public_id,
            'expected_version' => AgentApiVersion::for($billable),
        ]]])->assertUnprocessable();
        $this->assertSame('draft', $billable->fresh()->status);

        $this->withHeader('Idempotency-Key', 'override-rate')->postJson($path, ['entries' => [[
            'id' => $billable->public_id,
            'expected_version' => AgentApiVersion::for($billable),
            'billing_rate_amount' => 14000,
            'currency' => 'EUR',
        ]]])->assertOk();
        $this->assertDatabaseHas('client_time_entries', ['id' => $billable->id, 'status' => 'approved', 'billing_rate_amount' => 14000, 'currency' => 'EUR']);

        $this->withHeader('Idempotency-Key', 'nonbillable-no-rate')->postJson($path, ['entries' => [[
            'id' => $nonbillable->public_id,
            'expected_version' => AgentApiVersion::for($nonbillable),
        ]]])->assertOk();
        $this->assertDatabaseHas('client_time_entries', ['id' => $nonbillable->id, 'status' => 'approved', 'billing_rate_amount' => null]);
    }

    public function test_contributor_cannot_supply_authoritative_rate_or_cost_fields(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [, $workspace, , $project] = $this->tenant();
        $contributor = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $contributor->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $contributor->id,
            'role' => 'contributor',
        ]);
        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_WRITE]);

        $this->withHeader('Idempotency-Key', 'forged-rate')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/time-entries",
            ['entries' => [[
                'project_id' => $project->public_id,
                'worked_on' => '2026-08-23',
                'minutes' => 60,
                'description' => 'Attempted rate injection',
                'billing_rate_amount' => 1,
                'subcontractor_cost_amount' => 1,
            ]]],
        )->assertUnprocessable();
        $this->assertDatabaseCount('client_time_entries', 0);
    }

    /** @return array{User, Workspace, ClientCompany, ClientProject} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Rate Workspace', 'slug' => 'rate-'.str()->random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Rate Client', 'slug' => 'rate-client-'.str()->random(8)]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Rate Project']);

        return [$owner, $workspace, $company, $project];
    }

    private function agreement(Workspace $workspace, ClientCompany $company, ?ClientProject $project, int $rate, string $startsOn): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project?->id,
            'title' => 'Hourly agreement '.$startsOn,
            'status' => 'active',
            'starts_on' => $startsOn,
            'currency' => 'USD',
            'hourly_rate_amount' => $rate,
        ]);
    }

    private function time(User $user, Workspace $workspace, ClientCompany $company, ClientProject $project, bool $billable): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'worked_on' => '2026-08-23',
            'minutes' => 30,
            'description' => 'Approval rate test',
            'is_billable' => $billable,
            'status' => 'draft',
            'currency' => 'USD',
        ]);
    }

    /** @param list<string> $scopes */
    private function actingAsAgent(User $user, array $scopes): void
    {
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), $scopes);
    }

    private function initialize(): string
    {
        $response = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'SVC billing workflow test', 'version' => '1']],
        ])->assertOk();
        $session = $response->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        return $session;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed> */
    private function callTool(string $session, string $name, array $arguments): array
    {
        $result = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => str()->random(8),
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $session)->assertOk()->json('result');
        $this->assertFalse($result['isError'] ?? true, json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    /** @param array<string, mixed> $message
     * @return TestResponse<Response> */
    private function mcp(array $message, ?string $session = null): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if ($session !== null) {
            $headers['Mcp-Session-Id'] = $session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers);
    }
}
