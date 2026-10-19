<?php

namespace Tests\Feature\Api;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InspectsAgentOperations;
use Tests\TestCase;

/**
 * The finance reconciliation operations on the registry (#457): one action
 * behind REST (OAuth and the Sanctum finance token), MCP and the console.
 * {@see FinanceReconciliationApiTest} pins the finance token's original
 * contract, which is unchanged.
 */
final class FinanceReconciliationOperationsTest extends TestCase
{
    use InspectsAgentOperations;
    use RefreshDatabase;

    public function test_the_operations_are_declared_once_with_their_scopes_routes_and_schemas(): void
    {
        foreach ([
            FinanceReconciliationOperations::LIST => ['GET', 'agent-api.v1.invoice-payments.index', ['finance:read'], [200], 'FinanceInvoicePaymentListResponse'],
            FinanceReconciliationOperations::UPSERT => ['PUT', 'agent-api.v1.payment-reconciliations.upsert', ['finance:reconcile'], [200, 201], 'FinanceReconciliationResponse'],
            FinanceReconciliationOperations::DEACTIVATE => ['DELETE', 'agent-api.v1.payment-reconciliations.destroy', ['finance:reconcile'], [200], 'FinanceReconciliationResponse'],
        ] as $id => [$method, $route, $scopes, $statuses, $response]) {
            $operation = $this->agentOperation($id);
            $this->assertSame($method, $operation->rest?->method, $id);
            $this->assertSame($route, $operation->rest->routeName, $id);
            $this->assertSame($statuses, $operation->rest->successStatuses, $id);
            $this->assertSame($scopes, $operation->requirement->scopes, $id);
            $this->assertSame($scopes, AgentApiResponseSchemaCatalog::scopesForOperation($id), $id);
            $this->assertSame($response, AgentApiResponseSchemaCatalog::operationComponent($id), $id);
            $this->assertSame($id, $operation->mcpName(), $id);
            $this->assertTrue($operation->idempotent, $id);
            // Never behind a write cutover: the finance integration predates them.
            $this->assertSame([], array_values(array_filter($operation->requirement->flags, static fn (string $flag): bool => ! str_starts_with($flag, 'mcp'))), $id);
        }
        $this->assertSame('FinanceReconciliationRequest', AgentApiResponseSchemaCatalog::catalog()->requestComponent(FinanceReconciliationOperations::UPSERT));
    }

    public function test_an_oauth_manager_lists_reconciles_and_deactivates_over_rest(): void
    {
        [$owner, $workspace, $payment] = $this->payment('OAuth REST', 6000);
        $transaction = (string) Str::uuid();
        $path = $this->allocationPath($workspace, $payment, 'synthetic-ledger', $transaction);

        $this->actingAsMcp($owner, ['finance:reconcile']);
        $this->putJson($path, ['allocated_amount' => 2500, 'currency' => 'USD', 'reconciled_on' => '2026-08-15'])
            ->assertCreated()
            ->assertJsonPath('data.external_system', 'synthetic-ledger')
            ->assertJsonPath('data.external_transaction_id', $transaction)
            ->assertJsonPath('data.is_active', true);
        $this->putJson($path, ['allocated_amount' => 3000, 'currency' => 'USD'])
            ->assertOk()
            ->assertJsonPath('data.allocated_amount', 3000);
        $this->assertDatabaseCount('payment_reconciliations', 1);

        $this->actingAsMcp($owner, ['finance:read']);
        $listed = $this->getJson($this->listPath($workspace))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.reconciled_amount', 3000)
            ->assertJsonPath('data.0.unreconciled_amount', 3000)
            ->json();
        $this->assertConformsTo('FinanceInvoicePaymentListResponse', $listed);

        $this->actingAsMcp($owner, ['finance:reconcile']);
        $deactivated = $this->deleteJson($path)->assertOk()->assertJsonPath('data.is_active', false)->json();
        $this->assertConformsTo('FinanceReconciliationResponse', $deactivated);
        $this->deleteJson($path)->assertOk()->assertJsonPath('data.is_active', false);

        $audits = AgentMutationAudit::query()->where('workspace_id', $workspace->id)->orderBy('id')->get();
        $this->assertSame(
            [FinanceReconciliationOperations::UPSERT, FinanceReconciliationOperations::UPSERT, FinanceReconciliationOperations::DEACTIVATE, FinanceReconciliationOperations::DEACTIVATE],
            $audits->pluck('operation')->all(),
        );
        $this->assertSame(['success'], $audits->pluck('outcome')->unique()->values()->all());
        $this->assertSame(['mcp-test-client'], $audits->pluck('oauth_client_id')->unique()->values()->all());
        $this->assertSame([(string) PaymentReconciliation::query()->sole()->public_id], $audits->first()?->affected_public_ids);
    }

    public function test_oauth_needs_the_scope_and_a_workspace_manager(): void
    {
        [$owner, $workspace, $payment] = $this->payment('OAuth gates');
        $path = $this->allocationPath($workspace, $payment, 'synthetic-ledger', (string) Str::uuid());

        $this->actingAsMcp($owner, ['payments:read', 'payments:record', 'billing:read']);
        $this->getJson($this->listPath($workspace))
            ->assertForbidden()
            ->assertJsonPath('reason', 'missing_scope')
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="finance:read"');
        $this->putJson($path, ['allocated_amount' => 100, 'currency' => 'USD'])
            ->assertForbidden()
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="finance:reconcile"');
        // Reading does not let a caller write.
        $this->actingAsMcp($owner, ['finance:read']);
        $this->putJson($path, ['allocated_amount' => 100, 'currency' => 'USD'])->assertForbidden();

        // A member may not use the OAuth door at all, though the finance token lets one list.
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['finance:read', 'finance:reconcile']);
        $this->getJson($this->listPath($workspace))->assertForbidden();
        $this->putJson($path, ['allocated_amount' => 100, 'currency' => 'USD'])->assertForbidden();
        $this->deleteJson($path)->assertForbidden();

        $this->assertDatabaseCount('payment_reconciliations', 0);
        $this->assertSame(['failed'], AgentMutationAudit::query()->pluck('outcome')->unique()->values()->all());
        $this->assertSame(['forbidden'], AgentMutationAudit::query()->pluck('error_category')->unique()->values()->all());
    }

    /** Someone outside the workspace is told nothing about it and leaves nothing in it, not even a failed write's audit. */
    public function test_a_workspace_the_caller_is_not_in_is_concealed_before_anything_is_written(): void
    {
        [, $workspace, $payment] = $this->payment('Concealed');
        $path = $this->allocationPath($workspace, $payment, 'synthetic-ledger', (string) Str::uuid());
        $outsider = User::factory()->create();

        $this->actingAsMcp($outsider, ['finance:read', 'finance:reconcile']);
        $this->getJson($this->listPath($workspace))->assertNotFound();
        $this->putJson($path, ['allocated_amount' => 100, 'currency' => 'USD'])->assertNotFound();
        $this->deleteJson($path)->assertNotFound();

        Auth::forgetGuards();
        $token = $outsider->createToken('outsider', ['finance.read', 'finance.reconcile'], now()->addHour())->plainTextToken;
        $this->withToken($token)->putJson($path, ['allocated_amount' => 100, 'currency' => 'USD'])->assertNotFound();
        Auth::forgetGuards();
        $this->withToken($token)->deleteJson($path)->assertNotFound();

        $this->assertDatabaseCount('agent_mutation_audits', 0);
        $this->assertDatabaseCount('payment_reconciliations', 0);
    }

    /** A finance token opens these operations and nothing else, whatever route it is shown to. */
    public function test_a_finance_token_opens_only_the_finance_operations(): void
    {
        [$owner, $workspace, $payment] = $this->payment('Finance token boundary');
        $token = $owner->createToken('finance integration', ['finance.read', 'finance.reconcile'], now()->addHour())->plainTextToken;

        $this->withToken($token)->getJson($this->listPath($workspace))->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson("/api/v1/workspaces/{$workspace->public_id}/payments?invoice_id={$payment->invoice()->value('public_id')}")->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/context')->assertUnauthorized();

        // Every ability Sanctum allows ('*') still opens only finance:read and finance:reconcile.
        Auth::forgetGuards();
        $everything = $owner->createToken('everything', ['*'], now()->addHour())->plainTextToken;
        $this->withToken($everything)->getJson($this->listPath($workspace))->assertOk();
        Auth::forgetGuards();
        $this->withToken($everything)->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices")->assertUnauthorized();
    }

    public function test_finance_token_writes_are_audited_against_the_token(): void
    {
        [$owner, $workspace, $payment] = $this->payment('Finance token audit');
        $issued = $owner->createToken('finance integration', ['finance.reconcile'], now()->addHour());

        $this->withToken($issued->plainTextToken)
            ->putJson($this->allocationPath($workspace, $payment, 'synthetic-ledger', (string) Str::uuid()), ['allocated_amount' => 100, 'currency' => 'USD'])
            ->assertCreated();

        $audit = AgentMutationAudit::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('finance-token:'.$issued->accessToken->getKey(), $audit->oauth_client_id);
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame('success', $audit->outcome);
    }

    /** Agent write cutovers and MCP switches never stop the finance integration's REST routes. */
    public function test_switches_withhold_the_tools_but_never_the_routes(): void
    {
        [$owner, $workspace, $payment] = $this->payment('Switches');
        config([
            'agent_api.writes_enabled' => false,
            'agent_api.payment_writes_enabled' => false,
            'agent_api.mcp_feature_flags' => ['mcp.read' => false, 'mcp.write' => false],
        ]);
        $this->assertNotContains(FinanceReconciliationOperations::LIST, $this->deployedToolNames());
        $this->assertNotContains(FinanceReconciliationOperations::UPSERT, $this->deployedToolNames());

        $this->actingAsMcp($owner, ['finance:read', 'finance:reconcile']);
        $this->putJson($this->allocationPath($workspace, $payment, 'synthetic-ledger', (string) Str::uuid()), ['allocated_amount' => 100, 'currency' => 'USD'])->assertCreated();
        $this->getJson($this->listPath($workspace))->assertOk()->assertJsonPath('data.0.reconciled_amount', 100);
    }

    public function test_mcp_tools_run_the_same_operations_for_managers_only(): void
    {
        [$owner, $workspace, $payment] = $this->payment('MCP', 6000);
        $transaction = (string) Str::uuid();

        $this->actingAsMcp($owner, ['mcp:use', 'finance:read', 'finance:reconcile']);
        $headers = $this->initialize();
        $names = array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers)->assertOk()->json('result.tools'), 'name');
        foreach ([FinanceReconciliationOperations::LIST, FinanceReconciliationOperations::UPSERT, FinanceReconciliationOperations::DEACTIVATE] as $tool) {
            $this->assertContains($tool, $names);
        }

        $target = ['workspace_id' => $workspace->public_id, 'payment_id' => $payment->public_id, 'external_system' => 'synthetic-ledger', 'transaction_id' => $transaction];
        $created = $this->tool($headers, FinanceReconciliationOperations::UPSERT, [...$target, 'allocated_amount' => 2500, 'currency' => 'USD']);
        $this->assertSame($transaction, $created['data']['external_transaction_id']);
        $this->assertTrue($created['data']['is_active']);

        config(['app.url' => 'https://svc.example.test']);
        $invoiceId = $payment->invoice()->value('public_id');
        $listed = $this->tool($headers, FinanceReconciliationOperations::LIST, ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoiceId, 'per_page' => 10]);
        $this->assertSame(2500, $listed['data'][0]['reconciled_amount']);
        $this->assertSame(3500, $listed['data'][0]['unreconciled_amount']);
        // Its page links are the REST route's public address, not the internal transport's.
        $this->assertSame("https://svc.example.test/api/v1/workspaces/{$workspace->public_id}/invoice-payments", $listed['meta']['path']);
        $this->assertSame("https://svc.example.test/api/v1/workspaces/{$workspace->public_id}/invoice-payments?invoice={$invoiceId}&per_page=10&page=1", $listed['links']['first']);

        $deactivated = $this->tool($headers, FinanceReconciliationOperations::DEACTIVATE, $target);
        $this->assertFalse($deactivated['data']['is_active']);
        $this->assertFalse(PaymentReconciliation::query()->sole()->is_active);

        // A refusal the operation makes reaches the agent as a tool error.
        $refused = $this->tool($headers, FinanceReconciliationOperations::UPSERT, [...$target, 'allocated_amount' => 9000, 'currency' => 'USD'], false);
        $this->assertStringContainsString('cannot exceed', (string) json_encode($refused));

        // Manager-only: a member is not offered them.
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['mcp:use', 'finance:read', 'finance:reconcile']);
        $memberHeaders = $this->initialize();
        $memberNames = array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $memberHeaders)->assertOk()->json('result.tools'), 'name');
        $this->assertNotContains(FinanceReconciliationOperations::LIST, $memberNames);
    }

    public function test_the_mcp_upsert_input_is_the_rest_body_plus_its_path(): void
    {
        $input = $this->toolInputSchema(FinanceReconciliationOperations::UPSERT);
        $body = AgentApiResponseSchemaCatalog::requestForOperation(FinanceReconciliationOperations::UPSERT);
        foreach ($body['properties'] as $name => $property) {
            $this->assertSame($property, $input['properties'][$name] ?? null, $name);
        }
        foreach (['workspace_id', 'payment_id', 'external_system', 'transaction_id', 'allocated_amount', 'currency'] as $required) {
            $this->assertContains($required, $input['required']);
        }
        $this->assertArrayNotHasKey('idempotency_key', $input['properties']);
    }

    public function test_console_commands_run_the_same_operations_as_a_named_manager(): void
    {
        [$owner, $workspace, $payment] = $this->payment('Console', 6000);
        $transaction = (string) Str::uuid();
        $common = ['--workspace' => $workspace->public_id, '--user' => $owner->public_id, '--format' => 'json'];

        $this->artisan('svc:finance:reconcile', [...$common, 'payment' => $payment->public_id, 'system' => 'synthetic-ledger', 'transaction' => $transaction, '--amount' => '2500', '--currency' => 'USD', '--reconciled-on' => '2026-08-15'])
            ->expectsOutputToContain('"created":true')
            ->assertSuccessful();
        $this->artisan('svc:finance:reconcile', [...$common, 'payment' => $payment->public_id, 'system' => 'synthetic-ledger', 'transaction' => $transaction, '--amount' => '2000', '--currency' => 'USD'])
            ->expectsOutputToContain('"created":false')
            ->assertSuccessful();
        $this->assertSame(2000, PaymentReconciliation::query()->sole()->allocated_amount);

        $this->artisan('svc:finance:payments', $common)
            ->expectsOutputToContain('"unreconciled_amount":4000')
            ->assertSuccessful();

        $this->artisan('svc:finance:unreconcile', [...$common, 'payment' => $payment->public_id, 'system' => 'synthetic-ledger', 'transaction' => $transaction])
            ->expectsOutputToContain('"is_active":false')
            ->assertSuccessful();
        $this->assertFalse(PaymentReconciliation::query()->sole()->is_active);
        $this->assertSame(['console'], AgentMutationAudit::query()->pluck('oauth_client_id')->unique()->values()->all());

        // The same rules refuse the same things.
        $this->artisan('svc:finance:reconcile', [...$common, 'payment' => $payment->public_id, 'system' => 'synthetic-ledger', 'transaction' => $transaction, '--amount' => '9000', '--currency' => 'USD'])
            ->expectsOutputToContain('cannot exceed')
            ->assertFailed();
        $this->artisan('svc:finance:reconcile', [...$common, 'payment' => $payment->public_id, 'system' => 'Not A Slug', 'transaction' => $transaction, '--amount' => '1', '--currency' => 'USD'])
            ->assertExitCode(2);
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->artisan('svc:finance:payments', [...$common, '--user' => $member->public_id])
            ->expectsOutputToContain('not an owner or admin')
            ->assertFailed();
    }

    /** @return iterable<string, array{string}> */
    public static function doors(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
        yield 'console' => ['console'];
    }

    #[DataProvider('doors')]
    public function test_another_workspaces_payment_is_not_found_through_any_door(string $door): void
    {
        [$owner, $workspace] = $this->payment('Home');
        [, $other, $foreign] = $this->payment('Foreign', 5000, $owner);
        $transaction = (string) Str::uuid();

        match ($door) {
            'rest' => $this->restAsOwner($owner)->putJson($this->allocationPath($workspace, $foreign, 'synthetic-ledger', $transaction), ['allocated_amount' => 100, 'currency' => 'USD'])->assertNotFound(),
            'mcp' => $this->assertNotFoundOverMcp($owner, $workspace, $foreign, $transaction),
            'console' => $this->artisan('svc:finance:reconcile', ['--workspace' => $workspace->public_id, '--user' => $owner->public_id, 'payment' => $foreign->public_id, 'system' => 'synthetic-ledger', 'transaction' => $transaction, '--amount' => '100', '--currency' => 'USD'])
                ->expectsOutputToContain('Not found')->assertFailed(),
        };

        $this->assertDatabaseCount('payment_reconciliations', 0);
        $this->assertNotSame($workspace->id, $other->id);
    }

    /** Every tenant read and write the routes make is scoped to the route's workspace. */
    public function test_every_tenant_query_is_scoped_to_the_workspace(): void
    {
        [$owner, $workspace, $payment] = $this->payment('Scoped', 6000);
        $this->payment('Neighbour', 4000, $owner);
        $path = $this->allocationPath($workspace, $payment, 'synthetic-ledger', (string) Str::uuid());
        $invoiceId = $payment->invoice()->value('public_id');
        $this->actingAsMcp($owner, ['finance:read', 'finance:reconcile']);
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $sql = str_replace(['"', '`'], '', strtolower($event->sql));
            if (preg_match('/^(?:select .*? from|update|insert into|delete from) ([a-z_]+)/', $sql, $match) === 1) {
                $queries[] = [$match[1], $sql];
            }
        });

        $this->putJson($path, ['allocated_amount' => 100, 'currency' => 'USD'])->assertCreated();
        $this->getJson($this->listPath($workspace).'?invoice='.$invoiceId)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson($path)->assertOk();

        foreach (['client_invoice_payments', 'payment_reconciliations', 'client_invoices', 'client_companies'] as $table) {
            $this->assertContains($table, array_column($queries, 0));
        }
        foreach ($queries as [$table, $sql]) {
            if (! in_array($table, ['client_invoice_payments', 'payment_reconciliations', 'client_invoices', 'client_companies'], true)) {
                continue;
            }
            if (str_starts_with($sql, 'insert into')) {
                $this->assertStringContainsString('workspace_id', (string) strstr($sql, 'values', true), $sql);

                continue;
            }
            $where = strstr($sql, ' where ');
            $this->assertNotFalse($where, $sql);
            $this->assertMatchesRegularExpression('/(?:\b[a-z_]+\.)?workspace_id\s*=/', $where, $sql);
        }
    }

    private function restAsOwner(User $owner): self
    {
        $this->actingAsMcp($owner, ['finance:reconcile']);

        return $this;
    }

    private function assertNotFoundOverMcp(User $owner, Workspace $workspace, ClientInvoicePayment $foreign, string $transaction): void
    {
        $this->actingAsMcp($owner, ['mcp:use', 'finance:reconcile']);
        $headers = $this->initialize();
        $refused = $this->tool($headers, FinanceReconciliationOperations::UPSERT, [
            'workspace_id' => $workspace->public_id, 'payment_id' => $foreign->public_id, 'external_system' => 'synthetic-ledger',
            'transaction_id' => $transaction, 'allocated_amount' => 100, 'currency' => 'USD',
        ], false);
        $this->assertNotSame([], $refused);
    }

    /** @param array<string, mixed> $document */
    private function assertConformsTo(string $component, array $document): void
    {
        $schema = AgentApiResponseSchemaCatalog::catalog()->schema($component);
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema(json_decode((string) json_encode($document)), $schema), $component);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(array $headers, string $name, array $arguments, bool $succeeds = true): array
    {
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]], $headers)
            ->assertOk()
            ->json();
        if (! $succeeds) {
            $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), (string) json_encode($result));

            return $result;
        }
        $this->assertFalse($result['result']['isError'] ?? false, (string) json_encode($result));

        return $result['result']['structuredContent'];
    }

    /** @return array<string, string> */
    private function initialize(): array
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic finance test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = (string) $init->headers->get('Mcp-Session-Id');

        return $headers;
    }

    private function listPath(Workspace $workspace): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/invoice-payments";
    }

    private function allocationPath(Workspace $workspace, ClientInvoicePayment $payment, string $system, string $transaction): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/invoice-payments/{$payment->public_id}/reconciliations/{$system}/{$transaction}";
    }

    /** @return array{0: User, 1: Workspace, 2: ClientInvoicePayment} */
    private function payment(string $name, int $amount = 5000, ?User $owner = null): array
    {
        $owner ??= User::factory()->create(['email' => 'synthetic-'.Str::lower(Str::random(8)).'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6))]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic '.$name.' client', 'slug' => Str::slug($name).'-client-'.Str::lower(Str::random(6))]);
        $invoices = app(InvoiceLifecycleService::class);
        $invoice = $invoices->createDraft($workspace, $company, ['invoice_number' => 'SYN-'.Str::upper(Str::random(10)), 'currency' => 'USD'], [[
            'type' => 'service', 'description' => 'Synthetic service', 'quantity' => '1', 'unit_amount' => $amount, 'tax_amount' => 0,
        ]]);
        $invoices->issue($invoice, $workspace);
        $payment = $invoices->applyPayment($invoice, [
            'amount' => $amount, 'currency' => 'USD', 'method' => 'wire', 'status' => 'succeeded', 'idempotency_key' => 'synthetic-'.Str::random(16),
        ], $workspace);

        return [$owner, $workspace, $payment];
    }
}
