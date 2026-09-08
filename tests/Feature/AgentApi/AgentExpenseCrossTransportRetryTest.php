<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationReceipt;
use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AgentExpenseCrossTransportRetryTest extends TestCase
{
    use RefreshDatabase;

    public static function directions(): iterable
    {
        foreach (['log', 'update', 'delete'] as $operation) {
            yield $operation.' REST then MCP' => [$operation, true];
            yield $operation.' MCP then REST' => [$operation, false];
        }
    }

    #[DataProvider('directions')]
    public function test_same_client_and_key_replay_across_transports(string $operation, bool $restFirst): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'expenses:read', 'expenses:write']);
        $session = $this->initialize();
        $facts = ['spent_on' => '2026-09-07', 'amount' => 1250, 'currency' => 'USD', 'description' => 'Synthetic travel expense'];
        $record = $operation === 'log' ? null : (new WorkspaceExpenses($workspace))->record($company, null, new NewExpense(CarbonImmutable::parse('2026-09-06'), 500, 'USD', 'Synthetic original'));
        $body = match ($operation) {
            'log' => ['entries' => [$facts + ['company_id' => $company->public_id]]],
            'update' => $facts + ['expected_version' => AgentApiVersion::for($record)],
            'delete' => ['expected_version' => AgentApiVersion::for($record)],
        };
        $method = match ($operation) {
            'log' => 'POST', 'update' => 'PATCH', 'delete' => 'DELETE'
        };
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/expenses'.($record === null ? '' : '/'.$record->public_id);
        $arguments = ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'cross-door-expense-key', ...$body];
        if ($record !== null) {
            $arguments['expense_id'] = $record->public_id;
        }
        $rest = fn () => $this->json($method, $url, $body, ['Idempotency-Key' => 'cross-door-expense-key'])->assertStatus($operation === 'log' ? 201 : 200)->json('data');
        $mcp = fn () => $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'expenses.'.$operation, 'arguments' => $arguments]], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])->assertOk()->json('result.structuredContent.data');
        $first = $restFirst ? $rest() : $mcp();
        $second = $restFirst ? $mcp() : $rest();
        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame(1, ClientExpense::withTrashed()->where('workspace_id', $workspace->id)->count());
        $receipts = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->get();
        $this->assertCount(1, $receipts);
        $this->assertSame('mcp-test-client', $receipts->sole()->oauth_client_id);
    }

    public function test_rest_receipts_are_isolated_between_authenticated_oauth_clients(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $principal = $this->actingAsMcp($owner, ['mcp:use', 'expenses:write']);
        $body = ['entries' => [['company_id' => $company->public_id, 'spent_on' => '2026-09-07', 'amount' => 1250, 'currency' => 'USD', 'description' => 'Synthetic travel expense']]];
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/expenses';
        $first = $this->withHeader('Idempotency-Key', 'same-key-two-clients')->postJson($url, $body)->assertCreated()->json('data.0.id');
        $this->switchClient($principal, 'synthetic-second-client');
        $second = $this->postJson($url, $body)->assertCreated()->json('data.0.id');
        $this->assertNotSame($first, $second);
        $this->assertSame(2, ClientExpense::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(['mcp-test-client', 'synthetic-second-client'], AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->orderBy('id')->pluck('oauth_client_id')->all());
    }

    public function test_missing_authenticated_client_cannot_create_a_fallback_receipt(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $principal = $this->actingAsMcp($owner, ['expenses:write']);
        $this->switchClient($principal, '');
        $this->withHeader('Idempotency-Key', 'missing-authenticated-client')->postJson('/api/v1/workspaces/'.$workspace->public_id.'/expenses', ['entries' => [['company_id' => $company->public_id, 'spent_on' => '2026-09-07', 'amount' => 500, 'currency' => 'USD', 'description' => 'Synthetic expense']]])->assertUnauthorized();
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('client_expenses', 0);
    }

    public function test_both_context_entrypoints_use_the_authenticated_client(): void
    {
        [, , $owner] = $this->fixture();
        $principal = $this->actingAsMcp($owner, ['expenses:write']);
        $request = Request::create('/synthetic-context');
        $request->headers->set('Idempotency-Key', 'synthetic-context-key');
        $request->setUserResolver(fn () => $principal);
        $factory = app(AgentMutationContextFactory::class);
        $legacy = $factory->from($request);
        $authenticated = $factory->fromAuthenticatedClient($request);
        $this->assertSame('mcp-test-client', $legacy->oauthClientId);
        $this->assertSame('mcp-test-client', $authenticated->oauthClientId);
        $this->assertSame($legacy->user->id, $authenticated->user->id);
        $this->assertSame($legacy->idempotencyKey, $authenticated->idempotencyKey);
    }

    private function switchClient(AgentPrincipal $principal, string $clientId): void
    {
        $accessToken = $principal->token();
        $this->assertInstanceOf(AccessToken::class, $accessToken);
        Token::query()->whereKey($accessToken->oauth_access_token_id)->update(['client_id' => $clientId]);
        $principal->withAccessToken(new AccessToken(['oauth_access_token_id' => $accessToken->oauth_access_token_id, 'oauth_client_id' => $clientId, 'oauth_scopes' => ['mcp:use', 'expenses:write']]));
        app('auth')->guard('api')->setUser($principal);
    }

    private function initialize(): string
    {
        $session = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic expense retry test', 'version' => '1']]])->assertOk()->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        return $session;
    }

    /** @return array{Workspace,ClientCompany,User} */
    private function fixture(): array
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.expense_writes_enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic Retry Workspace', 'slug' => 'synthetic-expense-retry']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Retry Company', 'slug' => 'synthetic-retry-company']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $company, $owner];
    }
}
