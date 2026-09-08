<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationReceipt;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LegacyReceiptCrossTransportTest extends TestCase
{
    use RefreshDatabase;

    public static function directions(): iterable
    {
        foreach (['tasks.create', 'time_entries.log', 'invoices.create_draft'] as $operation) {
            yield $operation.' REST then MCP' => [$operation, true];
            yield $operation.' MCP then REST' => [$operation, false];
        }
    }

    #[DataProvider('directions')]
    public function test_original_mutations_share_authenticated_receipts_between_real_transports(string $operation, bool $restFirst): void
    {
        [$owner, $workspace, $company, $project] = $this->fixture();
        $principal = $this->actingAsMcp($owner, ['mcp:use', 'tasks:write', 'time:write', 'billing:write']);
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $headers['Mcp-Session-Id'] = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic legacy receipt test', 'version' => '1']]], $headers)->assertOk()->headers->get('Mcp-Session-Id');
        [$path, $body, $extra, $table] = match ($operation) {
            'tasks.create' => ['projects/'.$project->public_id.'/tasks', ['title' => 'Synthetic receipt task'], ['project_id' => $project->public_id], 'client_tasks'],
            'time_entries.log' => ['time-entries', ['entries' => [['project_id' => $project->public_id, 'worked_on' => '2026-09-07', 'minutes' => 30, 'description' => 'Synthetic receipt work']]], [], 'client_time_entries'],
            'invoices.create_draft' => ['invoices', ['company_id' => $company->public_id, 'time_entry_ids' => [], 'manual_lines' => [['type' => 'manual', 'description' => 'Synthetic receipt line', 'quantity' => 1, 'unit_amount' => 500]]], [], 'client_invoices'],
        };
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/'.$path;
        $rest = fn () => $this->postJson($url, $body, ['Idempotency-Key' => 'synthetic-cross-key'])->assertCreated()->json('data');
        $mcp = fn () => $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $operation, 'arguments' => ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'synthetic-cross-key', ...$extra, ...$body]]], $headers)->assertOk()->json('result.structuredContent.data');
        $first = $restFirst ? $rest() : $mcp();
        $second = $restFirst ? $mcp() : $rest();
        $this->assertNotNull($first);
        // The internal HTTP adapter uses its own host; receipt identity and
        // presented business fields must match regardless of that URL base.
        unset($first['web_url'], $second['web_url']);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount($table, 1);
        $this->assertSame(1, AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('oauth_client_id', 'mcp-test-client')->where('status', 'completed')->count());
        $this->assertSame(1, AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('oauth_client_id', 'testing-client')->where('status', 'namespace_guard')->count());

        $token = $principal->token();
        Token::query()->whereKey($token->oauth_access_token_id)->update(['client_id' => 'synthetic-second-client']);
        $principal->withAccessToken(new AccessToken(['oauth_access_token_id' => $token->oauth_access_token_id, 'oauth_client_id' => 'synthetic-second-client', 'oauth_scopes' => ['mcp:use', 'tasks:write', 'time:write', 'billing:write']]));
        $other = $rest();
        $this->assertNotSame($first, $other);
        $this->assertDatabaseCount($table, 2);
        $this->assertDatabaseHas('agent_mutation_receipts', ['workspace_id' => $workspace->id, 'oauth_client_id' => 'synthetic-second-client', 'status' => 'completed']);
    }

    public function test_missing_or_reserved_authenticated_client_is_refused_before_legacy_rest_writes(): void
    {
        [$owner, $workspace, , $project] = $this->fixture();
        $principal = $this->actingAsMcp($owner, ['tasks:write']);
        foreach (['', 'testing-client'] as $clientId) {
            $token = $principal->token();
            Token::query()->whereKey($token->oauth_access_token_id)->update(['client_id' => $clientId]);
            $principal->withAccessToken(new AccessToken(['oauth_access_token_id' => $token->oauth_access_token_id, 'oauth_client_id' => $clientId, 'oauth_scopes' => ['tasks:write']]));
            $this->postJson('/api/v1/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id.'/tasks', ['title' => 'Synthetic rejected task'], ['Idempotency-Key' => 'synthetic-missing-client'])->assertUnauthorized();
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('client_tasks', 0);
    }

    /** @return array{User, Workspace, ClientCompany, ClientProject} */
    private function fixture(): array
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic receipt workspace', 'slug' => 'synthetic-receipts']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic receipt company', 'slug' => 'synthetic-receipt-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic receipt project']);

        return [$owner, $workspace, $company, $project];
    }
}
