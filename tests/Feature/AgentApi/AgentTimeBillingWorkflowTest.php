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
use App\Support\Billing\SubcontractorBillingMode;
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

    public function test_flat_hourly_and_direct_entries_approve_without_an_ordinary_agreement_rate(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $flat = $this->time($owner, $workspace, $company, $project, true);
        $flat->update([
            'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
            'subcontractor_cost_amount' => 8000,
            'subcontractor_cost_currency' => 'USD',
        ]);
        $direct = $this->time($owner, $workspace, $company, $project, true);
        $direct->update([
            'subcontractor_billing_mode' => SubcontractorBillingMode::Direct,
        ]);
        $this->actingAsAgent($owner, [AgentApiScopes::TIME_APPROVE]);

        $this->withHeader('Idempotency-Key', 'approve-mode-snapshots')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/time-entries/approve",
            ['entries' => [
                ['id' => $flat->public_id, 'expected_version' => AgentApiVersion::for($flat)],
                ['id' => $direct->public_id, 'expected_version' => AgentApiVersion::for($direct)],
            ]],
        )->assertOk();

        foreach ([$flat, $direct] as $entry) {
            $this->assertDatabaseHas('client_time_entries', [
                'id' => $entry->id,
                'status' => 'approved',
                'billing_rate_amount' => null,
                'billing_rate_source' => null,
            ]);
        }
    }

    /**
     * A flat-hourly entry with no snapshotted amount is refused.
     *
     * Isolating for `client_time_entries.subcontractor_cost_amount`, which
     * #143 names as one of the two columns no existing citation covered.
     * Production refuses on
     * `$amount === null || trim((string) $currency) === ''`, and because that
     * is an OR, a fixture nulling the pair proves neither half: delete either
     * guard and the test stays green on the other's null. So the currency is
     * present here and only the amount is missing.
     *
     * The refusal matters because flat-hourly time bills at the
     * subcontractor's own cost. With no amount there is no number to bill, and
     * approving anyway would put an entry into the invoicing path that no
     * later stage can price.
     */
    public function test_flat_hourly_time_with_a_currency_but_no_amount_is_refused(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->time($owner, $workspace, $company, $project, true);
        $entry->update([
            'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
            'subcontractor_cost_amount' => null,
            'subcontractor_cost_currency' => 'USD',
        ]);
        $this->actingAsAgent($owner, [AgentApiScopes::TIME_APPROVE]);

        $this->withHeader('Idempotency-Key', 'flat-hourly-no-amount')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/time-entries/approve",
            ['entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]]],
        )->assertUnprocessable();

        $this->assertSame('draft', $entry->fresh()?->status);
    }

    /**
     * A flat-hourly entry with an amount but no currency is refused.
     *
     * The mirror of the test above, and the half that isolates
     * `client_time_entries.subcontractor_cost_currency`. An amount without a
     * currency is not a price: the invoice would carry a bare integer whose
     * denomination is whatever the reader assumes, and the workspace default
     * is not a safe substitute for a subcontractor's own contracted currency.
     *
     * Empty string as well as null, because the production guard trims, and a
     * column that arrived from the importer as `''` reads as "stated" to a
     * plain null check while carrying no more information than a null.
     */
    public function test_flat_hourly_time_with_an_amount_but_no_currency_is_refused(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->actingAsAgent($owner, [AgentApiScopes::TIME_APPROVE]);
        $path = "/api/v1/workspaces/{$workspace->public_id}/time-entries/approve";

        foreach ([null, '', '   '] as $index => $currency) {
            $entry = $this->time($owner, $workspace, $company, $project, true);
            $entry->update([
                'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
                'subcontractor_cost_amount' => 8000,
                'subcontractor_cost_currency' => $currency,
            ]);

            $this->withHeader('Idempotency-Key', 'flat-hourly-no-currency-'.$index)->postJson(
                $path,
                ['entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]]],
            )->assertUnprocessable();

            $this->assertSame('draft', $entry->fresh()?->status);
        }
    }

    /**
     * A stored rate that is not marked explicit is replaced, not kept.
     *
     * Isolating for `client_time_entries.billing_rate_source`. Approval keeps a
     * rate the operator typed - `billing_rate_source === 'explicit'` and an
     * amount present - and otherwise resolves the agreement rate over the top.
     * So a null source on a row that *does* carry an amount silently discards
     * that amount and bills the agreement rate instead.
     *
     * Only the source varies between the two entries here. Both carry the same
     * stored amount, so the assertion cannot pass by way of the
     * `billing_rate_amount !== null` half of the same condition - which is the
     * failure mode #143 exists to prevent, and which a fixture varying both
     * would have walked straight into.
     *
     * The importer is why this is not hypothetical: it carries rate amounts
     * across without a provenance marker, so the migrated rows are exactly the
     * ones whose stated rate this branch throws away.
     */
    public function test_a_stored_rate_with_no_provenance_is_replaced_by_the_agreement_rate(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->agreement($workspace, $company, null, 20000, '2026-01-01');

        $unmarked = $this->time($owner, $workspace, $company, $project, true);
        $unmarked->update(['billing_rate_amount' => 33000, 'billing_rate_source' => null]);
        $marked = $this->time($owner, $workspace, $company, $project, true);
        $marked->update(['billing_rate_amount' => 33000, 'billing_rate_source' => 'explicit']);

        $this->actingAsAgent($owner, [AgentApiScopes::TIME_APPROVE]);
        $path = "/api/v1/workspaces/{$workspace->public_id}/time-entries/approve";

        foreach ([$unmarked, $marked] as $index => $entry) {
            $this->withHeader('Idempotency-Key', 'provenance-'.$index)->postJson(
                $path,
                ['entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]]],
            )->assertOk();
        }

        // The agreement rate won where the provenance was absent, and the
        // operator's number survived where it was stated.
        $this->assertDatabaseHas('client_time_entries', [
            'id' => $unmarked->id, 'status' => 'approved', 'billing_rate_amount' => 20000, 'billing_rate_source' => 'agreement',
        ]);
        $this->assertDatabaseHas('client_time_entries', [
            'id' => $marked->id, 'status' => 'approved', 'billing_rate_amount' => 33000, 'billing_rate_source' => 'explicit',
        ]);
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
                'subcontractor_billing_mode' => 'flat_hourly',
                'subcontractor_cost_currency' => 'USD',
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
