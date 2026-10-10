<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentCapabilities;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InspectsAgentOperations;
use Tests\TestCase;

/**
 * `payments.correct`: the agent door to a payment's descriptive fields.
 *
 * Gated like `payments.record` - both write cutovers, the payments:record
 * scope, a workspace owner/admin and the MCP kill switch - with the version
 * the agent read and a reason required, and every gate rechecked on replay.
 */
final class AgentPaymentCorrectionTest extends TestCase
{
    use InspectsAgentOperations;
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_a_correction_returns_the_listed_row_and_a_retry_is_the_same_correction(string $transport): void
    {
        [$user, $workspace, $payment] = $this->fixture();
        $body = [
            'expected_version' => AgentApiVersion::for($payment),
            'reason' => 'Synthetic statement',
            'method' => 'ach',
            'reference' => null,
            'received_on' => now()->subDay()->toDateString(),
        ];

        $first = $this->correct($transport, $user, $workspace, $payment->public_id, $body, 'synthetic-correct');
        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('ach', $fresh->method);
        $this->assertNull($fresh->reference);
        $this->assertSame($body['received_on'], $fresh->received_on?->toDateString());
        $this->assertSame(10000, $fresh->amount);

        // Shaped exactly like a payments.list row, including the new version.
        $this->actingAsMcp($user, ['payments:read']);
        $listed = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/payments?invoice_id='.$first['invoice_id'])->assertOk()->json('data.0');
        $this->assertSame($listed, $first);
        $this->assertSame(AgentApiVersion::for($fresh), $first['version']);

        // An identical retry replays the receipt: one correction, one event,
        // even though the version it names is now stale.
        $second = $this->correct($transport, $user, $workspace, $payment->public_id, $body, 'synthetic-correct');
        $this->assertSame($first, $second);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->count());
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'payments.correct', 'outcome' => 'replay']);

        // The same key with a different request is refused.
        $this->correct($transport, $user, $workspace, $payment->public_id, [...$body, 'method' => 'check'], 'synthetic-correct', 409);
        // A new key against the version it already moved past is a conflict.
        $this->correct($transport, $user, $workspace, $payment->public_id, [...$body, 'method' => 'check'], 'synthetic-stale', 409);
        $this->assertSame('ach', $payment->fresh()?->method);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusedBodies(): iterable
    {
        yield 'amount' => [['amount' => 1]];
        yield 'currency' => [['currency' => 'EUR']];
        yield 'status' => [['status' => 'refunded']];
        yield 'refunded amount' => [['refunded_amount' => 1]];
        yield 'invoice' => [['invoice_id' => '00000000-0000-4000-8000-000000000000']];
        yield 'notes are not offered to agents' => [['notes' => 'Synthetic private note']];
        yield 'no reason' => [['reason' => null]];
        yield 'blank reason' => [['reason' => '   ']];
        yield 'no version' => [['expected_version' => null]];
        yield 'nothing to correct' => [['method' => null]];
        yield 'method too long' => [['method' => str_repeat('m', 41)]];
        yield 'future date' => [['received_on' => '2099-01-01']];
        yield 'ambiguous date' => [['received_on' => '09/01/2026']];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('refusedBodies')]
    public function test_money_private_and_malformed_fields_are_refused(array $change): void
    {
        [$user, $workspace, $payment] = $this->fixture();
        $body = array_filter(
            [...$this->body($payment), ...$change],
            static fn (mixed $value, string $key): bool => $value !== null || ! in_array($key, ['method'], true),
            ARRAY_FILTER_USE_BOTH,
        );
        if (array_key_exists('reason', $change) && $change['reason'] === null) {
            unset($body['reason']);
        }
        if (array_key_exists('expected_version', $change) && $change['expected_version'] === null) {
            unset($body['expected_version']);
        }

        $this->correct('rest', $user, $workspace, $payment->public_id, $body, 'synthetic-refused', 422);
        $this->assertUnchanged($payment);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'payments.correct', 'outcome' => 'failed']);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function flags(): iterable
    {
        foreach ([false, true] as $outer) {
            foreach ([false, true] as $inner) {
                yield (int) $outer.' '.(int) $inner => [$outer, $inner];
            }
        }
    }

    #[DataProvider('flags')]
    public function test_both_write_flags_close_the_tool_and_the_route(bool $outer, bool $inner): void
    {
        [$user, $workspace, $payment] = $this->fixture();
        config(['agent_api.writes_enabled' => $outer, 'agent_api.payment_writes_enabled' => $inner]);
        $names = $this->deployedToolNames();
        $capabilities = app(AgentCapabilities::class)->forWorkspace($user, $workspace, fn (string $scope): bool => true)['capabilities'];

        $this->assertSame($outer && $inner, in_array('payments.correct', $names, true));
        $this->assertSame($outer && $inner, in_array('payments:record', $capabilities, true));
        $this->correct('rest', $user, $workspace, $payment->public_id, $this->body($payment), 'synthetic-flag', $outer && $inner ? 200 : 404);
        $this->assertSame($outer && $inner ? 'ach' : 'wire', $payment->fresh()?->method);
    }

    public function test_the_mcp_kill_switch_withdraws_the_tool(): void
    {
        [$user, $workspace, $payment] = $this->fixture();
        config(['agent_api.mcp_feature_flags' => ['payments.correct' => false]]);
        $this->actingAsMcp($user, ['mcp:use', 'payments:read', 'payments:record']);
        $headers = $this->initialize();

        $names = array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers)->assertOk()->json('result.tools'), 'name');
        $this->assertNotContains('payments.correct', $names);
        $this->assertContains('payments.record', $names);

        $response = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'payments.correct', 'arguments' => [...$this->body($payment), 'workspace_id' => $workspace->public_id, 'payment_id' => $payment->public_id, 'idempotency_key' => 'synthetic-kill']],
        ], $headers)->json();
        $this->assertTrue(isset($response['error']) || ($response['result']['isError'] ?? false), (string) json_encode($response));
        $this->assertUnchanged($payment);
    }

    public function test_the_record_scope_is_required_and_read_scope_is_not_enough(): void
    {
        [$user, $workspace, $payment] = $this->fixture();
        $this->actingAsMcp($user, ['payments:read']);
        $this->withHeader('Idempotency-Key', 'synthetic-scope')
            ->patchJson($this->url($workspace, $payment->public_id), $this->body($payment))
            ->assertForbidden();

        // And the MCP tool is not listed to a connection without it.
        $this->actingAsMcp($user, ['mcp:use', 'payments:read']);
        $headers = $this->initialize();
        $names = array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers)->assertOk()->json('result.tools'), 'name');
        $this->assertNotContains('payments.correct', $names);
        $this->assertUnchanged($payment);
    }

    #[DataProvider('transports')]
    public function test_members_are_refused_and_a_revoked_role_cannot_replay(string $transport): void
    {
        [$user, $workspace, $payment] = $this->fixture();
        $body = $this->body($payment);
        $this->correct($transport, $user, $workspace, $payment->public_id, $body, 'synthetic-role');

        $workspace->memberships()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->update(['role' => 'member']);
        $this->correct($transport, $user, $workspace, $payment->public_id, $body, 'synthetic-role', 403);
        $this->correct($transport, $user, $workspace, $payment->public_id, [...$body, 'method' => 'check'], 'synthetic-member', 403);

        $workspace->memberships()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->update(['role' => 'admin']);
        $this->correct($transport, $user, $workspace, $payment->public_id, [...$this->body($payment->fresh() ?? $payment), 'method' => 'check'], 'synthetic-admin');
        $this->assertSame('check', $payment->fresh()?->method);
    }

    #[DataProvider('transports')]
    public function test_another_workspaces_payment_is_not_found(string $transport): void
    {
        [$user, $workspace] = $this->fixture();
        [, , $foreign] = $this->fixture();

        $this->correct($transport, $user, $workspace, $foreign->public_id, $this->body($foreign), 'synthetic-foreign', 404);
        $this->assertUnchanged($foreign);
    }

    public function test_the_whole_request_scopes_every_tenant_read_and_write(): void
    {
        [$user, $workspace, $payment] = $this->fixture();
        $this->actingAsMcp($user, ['payments:record']);
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $sql = str_replace(['"', '`'], '', strtolower($event->sql));
            if (preg_match('/^(?:select .*? from|update|insert into|delete from) ([a-z_]+)/', $sql, $match) === 1) {
                $queries[] = [$match[1], $sql];
            }
        });

        $this->withHeader('Idempotency-Key', 'synthetic-query')
            ->patchJson($this->url($workspace, $payment->public_id), $this->body($payment))
            ->assertOk();

        $this->assertContains('client_invoice_payments', array_column($queries, 0));
        $this->assertContains('client_invoices', array_column($queries, 0));
        $this->assertContains('client_company_activity', array_column($queries, 0));
        foreach ($queries as [$table, $sql]) {
            if (in_array($table, ['users', 'workspaces'], true)) {
                continue;
            }
            if (str_starts_with($sql, 'insert into')) {
                $this->assertStringContainsString('workspace_id', (string) strstr($sql, 'values', true), $sql);
            } else {
                $where = strstr($sql, ' where ');
                $this->assertNotFalse($where, $sql);
                $this->assertMatchesRegularExpression('/(?:\b[a-z_]+\.)?workspace_id\s*=/', $where, $sql);
            }
        }
    }

    public function test_listed_rows_carry_a_version_that_moves_with_any_write(): void
    {
        [$user, $workspace, $payment] = $this->fixture();
        $this->actingAsMcp($user, ['payments:read']);
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/payments?invoice_id='.$payment->invoice()->where('workspace_id', $workspace->id)->value('public_id');

        $before = $this->getJson($url)->assertOk()->json('data.0.version');
        app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 100, $workspace);
        $after = $this->getJson($url)->assertOk()->json('data.0.version');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $before);
        $this->assertNotSame($before, $after);
    }

    /**
     * The contract says what the service says: a correction names at least one
     * field. A body carrying only the version and a reason is refused by the
     * published schema, and by the MCP tool's input schema inherited from it,
     * rather than being advertised as valid and then refused with a 422.
     */
    public function test_the_published_schemas_require_a_field_to_correct(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        $request = AgentApiResponseSchemaCatalog::requestForOperation('payments.correct');
        $this->assertContains('payments.correct', $this->deployedToolNames());
        $input = $this->toolInputSchema('payments.correct');
        $validator = new SchemaValidator;
        $version = str_repeat('a', 64);
        $base = ['expected_version' => $version, 'reason' => 'Synthetic'];
        $tooling = ['workspace_id' => (string) str()->uuid(), 'payment_id' => (string) str()->uuid(), 'idempotency_key' => 'synthetic'];

        foreach (['REST body' => [$request, []], 'MCP input' => [$input, $tooling]] as $label => [$schema, $extra]) {
            $this->assertNotSame([], $validator->validateAgainstJsonSchema(json_decode((string) json_encode([...$base, ...$extra])), $schema), $label.': nothing to correct');
            foreach (['method' => 'ach', 'reference' => null, 'received_on' => '2026-08-01'] as $field => $value) {
                $this->assertSame(
                    [],
                    $validator->validateAgainstJsonSchema(json_decode((string) json_encode([...$base, ...$extra, $field => $value])), $schema),
                    $label.': '.$field,
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function body(ClientInvoicePayment $payment): array
    {
        return ['expected_version' => AgentApiVersion::for($payment), 'reason' => 'Synthetic correction', 'method' => 'ach'];
    }

    private function assertUnchanged(ClientInvoicePayment $payment): void
    {
        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('wire', $fresh->method);
        $this->assertSame('SYN-REF-1', $fresh->reference);
        $this->assertSame(10000, $fresh->amount);
        $this->assertSame('succeeded', $fresh->status);
        $this->assertSame(1, $fresh->lock_version);
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->count());
    }

    /** @return array{User, Workspace, ClientInvoicePayment} */
    private function fixture(): array
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        $user = User::factory()->create(['email' => 'synthetic-'.str()->uuid().'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic payments', 'slug' => 'synthetic-'.str()->uuid()]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'SYNTHETIC-'.str()->uuid()], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 10000],
        ]), $workspace);
        $payment = $service->applyPayment($invoice, [
            'amount' => 10000, 'currency' => 'USD', 'method' => 'wire', 'reference' => 'SYN-REF-1',
            'received_on' => now()->toDateString(),
        ], $workspace);

        return [$user, $workspace, $payment];
    }

    private function url(Workspace $workspace, string $paymentId): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/payments/'.$paymentId;
    }

    /** @return array<string, string> */
    private function initialize(): array
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic correction test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = (string) $init->headers->get('Mcp-Session-Id');

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function correct(string $transport, User $user, Workspace $workspace, string $paymentId, array $body, string $key, int $expected = 200): ?array
    {
        $this->actingAsMcp($user, ['mcp:use', 'payments:read', 'payments:record']);
        if ($transport === 'rest') {
            return $this->withHeader('Idempotency-Key', $key)
                ->patchJson($this->url($workspace, $paymentId), $body)
                ->assertStatus($expected)
                ->json('data');
        }

        $headers = $this->initialize();
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'payments.correct', 'arguments' => [...$body, 'workspace_id' => $workspace->public_id, 'payment_id' => $paymentId, 'idempotency_key' => $key]],
        ], $headers)->assertOk()->json();
        if ($expected !== 200) {
            $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), (string) json_encode($result));
            // A role refused at the MCP boundary never reaches the executor,
            // so only a refusal the action itself makes is audited as failed.
            if ($expected !== 403) {
                $audit = AgentMutationAudit::query()->where('workspace_id', $workspace->id)->latest('id')->firstOrFail();
                $this->assertSame('failed', $audit->outcome);
            }

            return null;
        }
        $this->assertArrayHasKey('result', $result, (string) json_encode($result));
        $this->assertFalse($result['result']['isError'] ?? false, (string) json_encode($result));

        return $result['result']['structuredContent']['data'];
    }
}
