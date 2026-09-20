<?php

namespace Tests\Feature\Mcp;

use App\Models\AgentMutationAudit;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AgentMcpClientWriteToolsTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array WRITE_SCOPES = [
        AgentApiScopes::MCP_USE,
        AgentApiScopes::IDENTITY_READ,
        AgentApiScopes::CLIENTS_READ,
        AgentApiScopes::CLIENTS_WRITE,
        AgentApiScopes::BILLING_READ,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
    }

    public function test_the_write_tools_exist_only_when_both_switches_and_the_scope_allow_them(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $writeTools = ['clients.create', 'clients.update', 'clients.archive', 'clients.restore', 'agreements.create', 'agreements.update', 'agreements.activate', 'agreements.terminate'];

        $this->assertSame([], array_values(array_diff($writeTools, $this->toolNames($this->initialize()))));

        config(['agent_api.client_writes_enabled' => false]);
        $this->assertSame([], array_intersect($writeTools, $this->toolNames($this->initialize())), 'the nested switch withdraws them');

        config(['agent_api.client_writes_enabled' => true, 'agent_api.writes_enabled' => false]);
        $this->assertSame([], array_intersect($writeTools, $this->toolNames($this->initialize())), 'the outer cutover withdraws them too');

        config(['agent_api.writes_enabled' => true]);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::CLIENTS_READ]);
        $this->assertSame([], array_intersect($writeTools, $this->toolNames($this->initialize())), 'a read-only grant does not see them');
        $this->assertNotContains('clients.create', $this->toolNames($this->initialize()));
        $this->assertSame(0, ClientCompany::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_a_manager_creates_a_client_and_a_retry_with_the_same_key_writes_once(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $session = $this->initialize();
        $args = ['workspace_id' => $workspace->public_id, 'name' => 'Synthetic New Client', 'billing_email' => 'billing@synthetic.example.test', 'idempotency_key' => 'create-1'];

        $first = $this->data($this->callTool($session, 'clients.create', $args));
        $retry = $this->data($this->callTool($session, 'clients.create', $args));

        $this->assertSame('Synthetic New Client', $first['name']);
        $this->assertSame('billing@synthetic.example.test', $first['billing_email']);
        $this->assertTrue($first['is_active']);
        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame(1, ClientCompany::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, AgentMutationAudit::query()->where('operation', 'clients.create')->where('outcome', 'success')->count());
        $this->assertSame(1, AgentMutationAudit::query()->where('operation', 'clients.create')->where('outcome', 'replay')->count());
    }

    public function test_reusing_a_key_for_a_different_request_is_refused_with_a_reason(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $session = $this->initialize();
        $base = ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'reused'];

        $this->data($this->callTool($session, 'clients.create', [...$base, 'name' => 'Synthetic One']));
        $conflict = $this->callTool($session, 'clients.create', [...$base, 'name' => 'Synthetic Two']);

        $this->assertStringContainsString('idempotency key was already used', $this->errorText($conflict));
        $this->assertSame(1, ClientCompany::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_an_invalid_value_is_refused_with_the_field_that_failed(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $session = $this->initialize();

        // Caught by the tool's JSON schema, before any workflow runs.
        $badShape = $this->callTool($session, 'clients.create', ['workspace_id' => $workspace->public_id, 'name' => 'Synthetic Bad Email', 'billing_email' => 'not-an-email', 'idempotency_key' => 'bad-email']);
        $this->assertStringContainsString('billing_email', $this->errorText($badShape));

        // Passes the schema and is caught by the same rules the web form applies.
        $blank = $this->callTool($session, 'clients.create', ['workspace_id' => $workspace->public_id, 'name' => '   ', 'idempotency_key' => 'blank-name']);
        $this->assertStringContainsString('name field is required', $this->errorText($blank));

        $this->assertSame(0, ClientCompany::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_an_edit_changes_only_the_fields_sent_and_null_clears_the_address(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $client = $this->client($workspace, 'Synthetic Original', 'keep@synthetic.example.test');
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $session = $this->initialize();
        $base = ['workspace_id' => $workspace->public_id, 'client_id' => $client->public_id];

        $renamed = $this->data($this->callTool($session, 'clients.update', [...$base, 'name' => 'Synthetic Renamed', 'idempotency_key' => 'rename']));
        $this->assertSame('Synthetic Renamed', $renamed['name']);
        $this->assertSame('keep@synthetic.example.test', $renamed['billing_email'], 'an omitted field is not blanked');
        $this->assertTrue($renamed['is_active'], 'an omitted flag is not reset');

        $cleared = $this->data($this->callTool($session, 'clients.update', [...$base, 'billing_email' => null, 'idempotency_key' => 'clear']));
        $this->assertNull($cleared['billing_email'], 'a field sent as null is an erasure');
        $this->assertSame('Synthetic Renamed', $cleared['name']);
    }

    public function test_enabling_automatic_delivery_needs_someone_to_send_to(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $client = $this->client($workspace, 'Synthetic Nobody');
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $session = $this->initialize();

        $refused = $this->callTool($session, 'clients.update', [
            'workspace_id' => $workspace->public_id, 'client_id' => $client->public_id,
            'automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 3, 'idempotency_key' => 'enable-1',
        ]);

        $this->assertStringContainsString('billing email', strtolower($this->errorText($refused)));
        $this->assertFalse($client->fresh()->automatic_invoice_email_enabled);

        $ok = $this->data($this->callTool($session, 'clients.update', [
            'workspace_id' => $workspace->public_id, 'client_id' => $client->public_id,
            'billing_email' => 'billing@synthetic.example.test', 'automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 3, 'idempotency_key' => 'enable-2',
        ]));
        $this->assertTrue($ok['automatic_invoice_email_enabled']);
        $this->assertSame(3, $ok['automatic_invoice_email_delay_days']);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'client.invoice_delivery_settings_updated')->count());
    }

    public function test_archive_deactivates_without_deleting_and_restore_reverses_it(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $client = $this->client($workspace, 'Synthetic Archive Me');
        $agreement = $this->agreement($workspace, $client);
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $session = $this->initialize();
        $base = ['workspace_id' => $workspace->public_id, 'client_id' => $client->public_id];

        $archived = $this->data($this->callTool($session, 'clients.archive', [...$base, 'idempotency_key' => 'arch-1']));
        $this->assertFalse($archived['is_active']);
        $this->assertNotNull(ClientCompany::query()->find($client->id), 'the row is kept');
        $this->assertSame($client->id, $agreement->fresh()->client_company_id, 'its history still points at it');

        $restored = $this->data($this->callTool($session, 'clients.restore', [...$base, 'idempotency_key' => 'rest-1']));
        $this->assertTrue($restored['is_active']);
    }

    public function test_an_agreement_is_created_as_a_draft_updated_in_part_activated_and_terminated(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        [$workspace, $owner] = $this->workspaceWithOwner();
        $client = $this->client($workspace, 'Synthetic Agreement Client');
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $session = $this->initialize();
        $ws = ['workspace_id' => $workspace->public_id];

        $created = $this->data($this->callTool($session, 'agreements.create', [
            ...$ws, 'client_id' => $client->public_id, 'title' => 'Synthetic support', 'starts_on' => '2026-01-01', 'currency' => 'USD',
            'billing_cadence' => 'monthly', 'hourly_rate_amount' => 37500, 'idempotency_key' => 'agr-create',
        ]));
        $this->assertSame('draft', $created['status']);
        $this->assertSame($client->public_id, $created['client_id']);
        $this->assertSame(37500, $created['hourly_rate_amount']);
        $this->assertNull($created['project_id'], 'covers the whole client');

        $updated = $this->data($this->callTool($session, 'agreements.update', [
            ...$ws, 'agreement_id' => $created['id'], 'title' => 'Synthetic support (renamed)', 'idempotency_key' => 'agr-update',
        ]));
        $this->assertSame('Synthetic support (renamed)', $updated['title']);
        $this->assertSame(37500, $updated['hourly_rate_amount'], 'an omitted term is not blanked');

        $active = $this->data($this->callTool($session, 'agreements.activate', [...$ws, 'agreement_id' => $created['id'], 'idempotency_key' => 'agr-activate']));
        $this->assertSame('active', $active['status']);

        $ended = $this->data($this->callTool($session, 'agreements.terminate', [...$ws, 'agreement_id' => $created['id'], 'idempotency_key' => 'agr-end']));
        $this->assertSame('terminated', $ended['status']);
        $this->assertSame('2026-09-20', $ended['ends_on']);
        $this->assertNotNull(ClientAgreement::query()->where('public_id', $created['id'])->first(), 'terminate keeps the row');

        $reactivate = $this->callTool($session, 'agreements.activate', [...$ws, 'agreement_id' => $created['id'], 'idempotency_key' => 'agr-again']);
        $this->assertStringContainsString('Only draft or paused agreements can be activated', $this->errorText($reactivate));
    }

    public function test_an_agreement_cannot_be_made_to_end_before_it_starts(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        $client = $this->client($workspace, 'Synthetic Dates Client');
        $agreement = $this->agreement($workspace, $client);
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $session = $this->initialize();

        $refused = $this->callTool($session, 'agreements.update', [
            'workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id, 'ends_on' => '2025-12-31', 'idempotency_key' => 'backwards',
        ]);

        $this->assertStringContainsString('cannot end before it starts', $this->errorText($refused));
        $this->assertNull($agreement->fresh()->ends_on);
    }

    public function test_another_workspaces_records_are_not_reachable_for_any_write(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();
        [$other] = $this->workspaceWithOwner();
        $foreignClient = $this->client($other, 'Synthetic Foreign');
        $foreignAgreement = $this->agreement($other, $foreignClient);
        $this->actingAsMcp($owner, self::WRITE_SCOPES);
        $session = $this->initialize();
        $ws = ['workspace_id' => $workspace->public_id];

        foreach ([
            ['clients.update', ['client_id' => $foreignClient->public_id, 'name' => 'Hijacked']],
            ['clients.archive', ['client_id' => $foreignClient->public_id]],
            ['agreements.create', ['client_id' => $foreignClient->public_id, 'title' => 'Injected', 'starts_on' => '2026-01-01', 'currency' => 'USD']],
            ['agreements.update', ['agreement_id' => $foreignAgreement->public_id, 'title' => 'Hijacked']],
            ['agreements.activate', ['agreement_id' => $foreignAgreement->public_id]],
            ['agreements.terminate', ['agreement_id' => $foreignAgreement->public_id]],
        ] as $n => [$tool, $arguments]) {
            $response = $this->callTool($session, $tool, [...$ws, ...$arguments, 'idempotency_key' => "foreign-{$n}"]);
            $this->assertTrue($response['result']['isError'] ?? isset($response['error']), "{$tool} must be refused: ".json_encode($response));
        }

        $this->assertSame('Synthetic Foreign', $foreignClient->fresh()->name);
        $this->assertTrue($foreignClient->fresh()->is_active);
        $this->assertSame('active', $foreignAgreement->fresh()->status);
        $this->assertSame(1, ClientAgreement::query()->where('workspace_id', $other->id)->count(), 'nothing was injected');
        $this->assertSame(0, ClientAgreement::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_a_member_who_is_not_a_manager_cannot_write_even_with_the_scope(): void
    {
        [$workspace] = $this->workspaceWithOwner();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, self::WRITE_SCOPES);
        $session = $this->initialize();

        $this->assertNotContains('clients.create', $this->toolNames($session));
        $response = $this->callTool($session, 'clients.create', ['workspace_id' => $workspace->public_id, 'name' => 'Synthetic Sneaky', 'idempotency_key' => 'member-1']);

        $this->assertTrue($response['result']['isError'] ?? isset($response['error']));
        $this->assertSame(0, ClientCompany::query()->where('workspace_id', $workspace->id)->count());
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed> */
    private function data(array $body): array
    {
        $this->assertFalse($body['result']['isError'] ?? false, 'Expected success, got: '.json_encode($body));

        return $body['result']['structuredContent']['data'];
    }

    /** @param array<string, mixed> $body */
    private function errorText(array $body): string
    {
        $this->assertTrue(($body['result']['isError'] ?? false) === true || isset($body['error']), 'Expected a refusal, got: '.json_encode($body));

        return (string) ($body['result']['content'][0]['text'] ?? $body['error']['message'] ?? '');
    }

    /** @return array{0: Workspace, 1: User} */
    private function workspaceWithOwner(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Writes Workspace', 'slug' => 'synthetic-writes-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $owner];
    }

    private function client(Workspace $workspace, string $name, ?string $billingEmail = null): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => 'synthetic-client-'.uniqid(),
            'billing_email' => $billingEmail,
        ]);
    }

    private function agreement(Workspace $workspace, ClientCompany $client): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $client->id,
            'title' => 'Synthetic agreement',
            'status' => 'active',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'starts_on' => '2026-01-01',
        ]);
    }
}
