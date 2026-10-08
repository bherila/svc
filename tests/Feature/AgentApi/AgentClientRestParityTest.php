<?php

namespace Tests\Feature\AgentApi;

use App\Actions\UpdateClientCompany;
use App\Models\AgentMutationAudit;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AgentClientRestParityTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'clients:read', 'clients:write', 'billing:read'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
    }

    /** @return iterable<string, array{string}> */
    public static function writes(): iterable
    {
        foreach (['clients.create', 'clients.update', 'clients.archive', 'clients.restore', 'agreements.create', 'agreements.update', 'agreements.activate', 'agreements.terminate'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('writes')]
    public function test_rest_and_mcp_share_receipts_and_audit(string $operation): void
    {
        [$workspace, $owner, $client, $agreement] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        [$method, $path, $body, $arguments, $status] = $this->requestFor($operation, $client, $agreement);
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/'.$path;
        $validator = new SchemaValidator;
        $this->assertSame([], $validator->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::requestForOperation($operation)));
        $first = $this->json($method, $url, $body, ['Idempotency-Key' => 'synthetic-shared-receipt'])->assertStatus($status)->json('data');
        $this->assertSame([], $validator->validateAgainstJsonSchema(['data' => $first], AgentApiResponseSchemaCatalog::forOperation($operation)));
        $session = $this->initialize();
        $replay = $this->callTool($session, $operation, ['workspace_id' => $workspace->public_id, ...$arguments, ...$body, 'idempotency_key' => 'synthetic-shared-receipt']);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($first, $replay['result']['structuredContent']['data']);
        $this->assertSame(1, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', $operation)->where('outcome', 'success')->count());
        $this->assertSame(1, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', $operation)->where('outcome', 'replay')->count());
        $this->json($method, $url, $body, ['Idempotency-Key' => 'synthetic-shared-receipt'])->assertStatus($status)->assertJsonPath('data.id', $first['id']);
        $changed = $body;
        $changed['expected_version'] = str_repeat('f', 64);
        $this->json($method, $url, $changed, ['Idempotency-Key' => 'synthetic-shared-receipt'])->assertConflict();
    }

    #[DataProvider('writes')]
    public function test_every_rest_write_requires_scope_role_key_and_both_cutovers(string $operation): void
    {
        [$workspace, $owner, $client, $agreement] = $this->fixture();
        [$method, $path, $body] = $this->requestFor($operation, $client, $agreement);
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/'.$path;
        $this->actingAsMcp($owner, [AgentApiScopes::CLIENTS_READ]);
        $this->json($method, $url, $body, ['Idempotency-Key' => 'no-scope'])->assertForbidden();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, self::SCOPES);
        $this->json($method, $url, $body, ['Idempotency-Key' => 'no-role'])->assertForbidden();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->json($method, $url, $body)->assertUnprocessable();
        config(['agent_api.client_writes_enabled' => false]);
        $this->json($method, $url, $body, ['Idempotency-Key' => 'nested-off'])->assertNotFound();
        config(['agent_api.client_writes_enabled' => true, 'agent_api.writes_enabled' => false]);
        $this->json($method, $url, $body, ['Idempotency-Key' => 'outer-off'])->assertNotFound();
        $this->assertSame(0, AgentMutationAudit::query()->where('outcome', 'success')->count());
    }

    #[DataProvider('writes')]
    public function test_existing_record_writes_refuse_a_stale_version_and_foreign_records(string $operation): void
    {
        if ($operation === 'clients.create') {
            $this->assertTrue(true, 'Creation has no existing row to version.');

            return;
        }
        [$workspace, $owner, $client, $agreement] = $this->fixture();
        [$foreignWorkspace, , $foreignClient, $foreignAgreement] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        [$method, $path, $body] = $this->requestFor($operation, $client, $agreement);
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/'.$path;
        $body['expected_version'] = str_repeat('0', 64);
        $this->json($method, $url, $body, ['Idempotency-Key' => 'stale'])->assertConflict()->assertJsonStructure(['message']);
        unset($body['expected_version']);
        $this->json($method, $url, $body, ['Idempotency-Key' => 'missing-version'])->assertUnprocessable();
        [$method, $path, $body] = $this->requestFor($operation, $foreignClient, $foreignAgreement);
        $this->json($method, '/api/v1/workspaces/'.$workspace->public_id.'/'.$path, $body, ['Idempotency-Key' => 'foreign'])->assertNotFound();
        $this->assertSame('Synthetic Client', $foreignClient->fresh()->name);
        $this->assertSame('draft', $foreignAgreement->fresh()->status);
        $this->assertSame(1, ClientAgreement::query()->where('workspace_id', $foreignWorkspace->id)->count());
    }

    public function test_reads_match_mcp_and_conceal_other_tenants(): void
    {
        [$workspace, $owner, $client, $agreement] = $this->fixture();
        [$foreignWorkspace, , $foreignClient, $foreignAgreement] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $session = $this->initialize();
        foreach ([['clients', 'clients.list', []], ['clients/'.$client->public_id, 'clients.get', ['client_id' => $client->public_id]], ['agreements', 'agreements.list', []], ['agreements/'.$agreement->public_id, 'agreements.get', ['agreement_id' => $agreement->public_id]]] as [$path, $tool, $args]) {
            $rest = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/'.$path)->assertOk()->json();
            $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($rest, AgentApiResponseSchemaCatalog::forOperation($tool)));
            $mcp = $this->callTool($session, $tool, ['workspace_id' => $workspace->public_id, ...$args]);
            $this->assertSame($rest, $mcp['result']['structuredContent']);
        }
        foreach (['clients/'.$foreignClient->public_id, 'agreements/'.$foreignAgreement->public_id] as $path) {
            $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/'.$path)->assertNotFound();
        }
        $this->getJson('/api/v1/workspaces/'.$foreignWorkspace->public_id.'/clients')->assertNotFound();
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/clients?status=unexpected')->assertUnprocessable();
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/agreements?limit=101')->assertUnprocessable();
    }

    public function test_every_rest_read_requires_its_scope_and_manager_role(): void
    {
        [$workspace, $owner, $client, $agreement] = $this->fixture();
        $paths = ['clients', 'clients/'.$client->public_id, 'agreements', 'agreements/'.$agreement->public_id];
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE]);
        foreach ($paths as $path) {
            $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/'.$path)->assertForbidden();
        }
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, self::SCOPES);
        foreach ($paths as $path) {
            $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/'.$path)->assertNotFound();
        }
    }

    public function test_first_party_sessions_can_read_clients_and_agreements(): void
    {
        [$workspace, $owner, $client, $agreement] = $this->fixture();
        config(['agent_api.writes_enabled' => false, 'agent_api.client_writes_enabled' => false]);
        $this->actingAs($owner)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str_repeat('s', 40));
        $base = '/api/v1/workspaces/'.$workspace->public_id;
        $this->getJson($base.'/clients')->assertOk()->assertJsonPath('data.0.id', $client->public_id);
        $this->getJson($base.'/clients/'.$client->public_id)->assertOk()->assertJsonPath('data.id', $client->public_id);
        $this->getJson($base.'/agreements')->assertOk()->assertJsonPath('data.0.id', $agreement->public_id);
        $this->getJson($base.'/agreements/'.$agreement->public_id)->assertOk()->assertJsonPath('data.id', $agreement->public_id);
    }

    public function test_agreement_confirmation_instructions_do_not_require_invoice_tools(): void
    {
        [, $owner] = $this->fixture();
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::CLIENTS_WRITE, AgentApiScopes::BILLING_READ]);
        config(['agent_api.invoice_writes_enabled' => false]);
        $response = $this->mcp($this->initializeMessage())->assertOk();
        $this->assertStringContainsString('activating an agreement', $response->json('result.instructions'));
        $this->assertStringContainsString('terminating an agreement', $response->json('result.instructions'));
    }

    public function test_confirmations_and_web_updates_invalidate_the_revision(): void
    {
        [$workspace, $owner, $client, $agreement] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $base = '/api/v1/workspaces/'.$workspace->public_id;
        $version = AgentApiVersion::for($agreement);
        foreach (['activate', 'terminate'] as $operation) {
            foreach ([false, 'true', 'yes', 'on', 1, '1'] as $index => $confirmation) {
                $this->postJson($base.'/agreements/'.$agreement->public_id.'/'.$operation, ['expected_version' => $version, 'confirm' => $confirmation], ['Idempotency-Key' => 'unconfirmed-'.$operation.'-'.$index])->assertUnprocessable();
            }
        }
        $clientVersion = AgentApiVersion::for($client);
        app(UpdateClientCompany::class)->handle($workspace, $client, ['name' => 'Synthetic Web Edit']);
        $this->patchJson($base.'/clients/'.$client->public_id, ['name' => 'Synthetic Stale', 'expected_version' => $clientVersion], ['Idempotency-Key' => 'web-race'])->assertConflict();
        app(AgreementWorkflow::class)->update($workspace, $agreement, ['title' => 'Synthetic Web Edit']);
        $this->postJson($base.'/agreements/'.$agreement->public_id.'/activate', ['expected_version' => $version, 'confirm' => true], ['Idempotency-Key' => 'agreement-web-race'])->assertConflict();
        $session = $this->initialize();
        $stale = $this->callTool($session, 'agreements.activate', ['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id, 'expected_version' => $version, 'confirm' => true, 'idempotency_key' => 'mcp-stale']);
        $this->assertTrue($stale['result']['isError']);
        $this->assertStringContainsString('has changed', $stale['result']['content'][0]['text']);
    }

    public function test_missing_and_inaccessible_workspaces_have_identical_refusals(): void
    {
        config(['app.debug' => false]);
        [, $owner] = $this->fixture();
        [$foreign, , $client, $agreement] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $missing = Str::uuid()->toString();
        $requests = [['GET', 'clients', []], ['GET', 'clients/'.$client->public_id, []], ['GET', 'agreements', []], ['GET', 'agreements/'.$agreement->public_id, []]];
        foreach (array_keys(iterator_to_array(self::writes())) as $operation) {
            [$method, $path, $body] = $this->requestFor($operation, $client, $agreement);
            $requests[] = [$method, $path, $body];
        }
        foreach ($requests as [$method, $path, $body]) {
            $existing = $this->json($method, '/api/v1/workspaces/'.$foreign->public_id.'/'.$path, $body, ['Idempotency-Key' => 'synthetic-foreign'])->assertNotFound();
            $unknown = $this->json($method, '/api/v1/workspaces/'.$missing.'/'.$path, $body, ['Idempotency-Key' => 'synthetic-missing'])->assertNotFound();
            $this->assertSame($existing->json(), $unknown->json());
            $this->assertSame($existing->headers->get('Cache-Control'), $unknown->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $unknown->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_stale_agreement_revision_precedes_validation_against_stored_dates(): void
    {
        [$workspace, $owner, , $agreement] = $this->fixture();
        $version = AgentApiVersion::for($agreement);
        app(AgreementWorkflow::class)->update($workspace, $agreement, ['starts_on' => '2026-03-01']);
        $this->actingAsMcp($owner, self::SCOPES);
        $this->patchJson('/api/v1/workspaces/'.$workspace->public_id.'/agreements/'.$agreement->public_id, [
            'expected_version' => $version, 'ends_on' => '2026-02-01',
        ], ['Idempotency-Key' => 'synthetic-stale-date'])->assertConflict();
        $this->assertNull($agreement->fresh()->ends_on);
    }

    public function test_versioned_writes_require_the_scope_that_reads_their_revision(): void
    {
        [$workspace, $owner, $client, $agreement] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'clients:write']);
        $session = $this->initialize();
        foreach (array_keys(iterator_to_array(self::writes())) as $operation) {
            if ($operation === 'clients.create') {
                continue;
            }
            [$method, $path, $body, $arguments] = $this->requestFor($operation, $client, $agreement);
            $this->json($method, '/api/v1/workspaces/'.$workspace->public_id.'/'.$path, $body, ['Idempotency-Key' => 'synthetic-read-scope'])->assertForbidden();
            $refused = $this->callTool($session, $operation, ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'synthetic-read-scope', ...$arguments, ...$body]);
            $this->assertTrue(isset($refused['error']) || ($refused['result']['isError'] ?? false));
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_delivery_schema_requires_delay_and_mcp_preserves_recipient_failure(): void
    {
        [$workspace, $owner, $client] = $this->fixture();
        $version = AgentApiVersion::for($client);
        $validator = new SchemaValidator;
        $schema = AgentApiResponseSchemaCatalog::requestForOperation('clients.update');
        foreach ([['expected_version' => $version, 'automatic_invoice_email_enabled' => true], ['expected_version' => $version, 'automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => null]] as $body) {
            $this->assertNotSame([], $validator->validateAgainstJsonSchema($body, $schema));
        }
        $this->assertSame([], $validator->validateAgainstJsonSchema(['expected_version' => $version, 'automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 0], $schema));
        $client->update(['billing_email' => null]);
        $this->actingAsMcp($owner, self::SCOPES);
        $session = $this->initialize();
        $result = $this->callTool($session, 'clients.update', ['workspace_id' => $workspace->public_id, 'client_id' => $client->public_id, 'expected_version' => AgentApiVersion::for($client), 'idempotency_key' => 'synthetic-no-recipient', 'automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 0]);
        $this->assertTrue($result['result']['isError']);
        $this->assertStringContainsString('Add a valid billing email or client portal recipient', $result['result']['content'][0]['text']);
        $this->assertFalse($client->fresh()->automatic_invoice_email_enabled);
    }

    /** @return array{Workspace, User, ClientCompany, ClientAgreement} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic API Workspace', 'slug' => 'synthetic-api-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $client = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Client', 'slug' => 'synthetic-client', 'billing_email' => 'billing@client.example.test']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $client->id, 'title' => 'Synthetic Agreement', 'starts_on' => '2026-01-01', 'status' => 'draft', 'currency' => 'USD']);

        return [$workspace, $owner, $client, $agreement];
    }

    /** @return array{string, string, array<string, mixed>, array<string, mixed>, int} */
    private function requestFor(string $operation, ClientCompany $client, ClientAgreement $agreement): array
    {
        $version = ['expected_version' => AgentApiVersion::for($client)];
        $agreementVersion = ['expected_version' => AgentApiVersion::for($agreement)];

        return match ($operation) {
            'clients.create' => ['POST', 'clients', ['name' => 'Synthetic Created Client'], [], 201],
            'clients.update' => ['PATCH', 'clients/'.$client->public_id, $version + ['name' => 'Synthetic Updated'], ['client_id' => $client->public_id], 200],
            'clients.archive' => ['POST', 'clients/'.$client->public_id.'/archive', $version, ['client_id' => $client->public_id], 200],
            'clients.restore' => ['POST', 'clients/'.$client->public_id.'/restore', $version, ['client_id' => $client->public_id], 200],
            'agreements.create' => ['POST', 'clients/'.$client->public_id.'/agreements', $version + ['title' => 'Synthetic Created Agreement', 'starts_on' => '2026-01-01', 'currency' => 'USD'], ['client_id' => $client->public_id], 201],
            'agreements.update' => ['PATCH', 'agreements/'.$agreement->public_id, $agreementVersion + ['title' => 'Synthetic Updated'], ['agreement_id' => $agreement->public_id], 200],
            'agreements.activate' => ['POST', 'agreements/'.$agreement->public_id.'/activate', $agreementVersion + ['confirm' => true], ['agreement_id' => $agreement->public_id], 200],
            'agreements.terminate' => ['POST', 'agreements/'.$agreement->public_id.'/terminate', $agreementVersion + ['confirm' => true, 'ends_on' => null], ['agreement_id' => $agreement->public_id], 200],
            default => throw new \LogicException('Unknown synthetic operation'),
        };
    }
}
