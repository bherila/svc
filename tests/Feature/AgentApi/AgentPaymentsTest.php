<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentCapabilities;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpToolCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AgentPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_recording_full_settlement_and_retry_are_one_payment(string $transport): void
    {
        [$user, $workspace, $invoice] = $this->fixture();
        $payload = $this->payload($invoice);
        $first = $this->record($transport, $user, $workspace, $payload, 'synthetic-full');
        $second = $this->record($transport, $user, $workspace, $payload, 'synthetic-full');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->assertSame('paid', $invoice->fresh()->status);
        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame(10000, $payment->amount);
        $this->assertSame('succeeded', $payment->status);
        $this->assertSame($payload['received_on'], $payment->received_on->toDateString());
        $this->assertNull($payment->provider_payment_identifier);
        $this->record($transport, $user, $workspace, $payload, 'synthetic-new', 422);
        $this->record($transport, $user, $workspace, [...$payload, 'received_on' => now()->subDay()->toDateString()], 'synthetic-full', 409);
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'payments.record', 'outcome' => 'replay']);
    }

    public function test_retry_can_switch_transports_when_optional_reference_was_omitted(): void
    {
        [$user, $workspace, $invoice] = $this->fixture();
        $payload = $this->payload($invoice);
        $rest = $this->record('rest', $user, $workspace, $payload, 'synthetic-cross-door');
        $mcp = $this->record('mcp', $user, $workspace, $payload, 'synthetic-cross-door');
        $this->assertSame($rest, $mcp);
        $this->assertDatabaseCount('client_invoice_payments', 1);
    }

    public static function invalidPayloads(): iterable
    {
        yield 'zero' => [['amount' => 0], 422];
        yield 'fractional' => [['amount' => 1.5], 422];
        yield 'negative' => [['amount' => -1], 422];
        yield 'overpayment' => [['amount' => 10001], 422];
        yield 'currency mismatch' => [['currency' => 'EUR'], 422];
        yield 'ambiguous date' => [['received_on' => '09/01/2026'], 422];
        yield 'missing date' => [['received_on' => null], 422];
        yield 'missing method' => [['method' => null], 422];
        yield 'future' => [['received_on' => '2099-01-01'], 422];
        yield 'too old' => [['received_on' => '2000-01-01'], 422];
        yield 'provider forbidden' => [['provider' => 'stripe'], 422];
        yield 'status forbidden' => [['status' => 'pending'], 422];
        yield 'refund forbidden' => [['refunded_amount' => 1], 422];
        yield 'notes forbidden' => [['notes' => 'Synthetic private note'], 422];
    }

    #[DataProvider('invalidPayloads')]
    public function test_rest_rejects_invalid_or_non_bookkeeping_fields(array $change, int $status): void
    {
        [$user, $workspace, $invoice] = $this->fixture();
        $this->record('rest', $user, $workspace, [...$this->payload($invoice), ...$change], 'synthetic-invalid', $status);
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'outcome' => 'failed']);
    }

    public static function flags(): iterable
    {
        foreach ([false, true] as $outer) {
            foreach ([false, true] as $inner) {
                yield (int) $outer.' '.(int) $inner => [$outer, $inner];
            }
        }
    }

    #[DataProvider('flags')]
    public function test_flags_close_catalog_capabilities_and_rest(bool $outer, bool $inner): void
    {
        [$user, $workspace, $invoice] = $this->fixture();
        config(['agent_api.writes_enabled' => $outer, 'agent_api.payment_writes_enabled' => $inner]);
        $names = array_map(fn ($tool) => $tool->name, app(AgentMcpToolCatalog::class)->definitions(app(AgentMcpReadTools::class), app(AgentMcpWriteTools::class)));
        $capabilities = app(AgentCapabilities::class)->forWorkspace($user, $workspace, fn (string $scope): bool => true)['capabilities'];
        $this->assertContains('payments.list', $names);
        $this->assertContains('payments:read', $capabilities);
        $this->assertSame($outer && $inner, in_array('payments.record', $names, true));
        $this->assertSame($outer && $inner, in_array('payments:record', $capabilities, true));
        $this->record('rest', $user, $workspace, $this->payload($invoice), 'synthetic-flag', $outer && $inner ? 201 : 404);
    }

    #[DataProvider('transports')]
    public function test_scoped_recording_refuses_foreign_invoice_and_role_revoked_replay(string $transport): void
    {
        [$user, $workspace, $invoice] = $this->fixture();
        [, , $foreign] = $this->fixture();
        $this->record($transport, $user, $workspace, $this->payload($foreign), 'synthetic-foreign', 404);
        $this->record($transport, $user, $workspace, $this->payload($invoice), 'synthetic-role');
        $workspace->memberships()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->update(['role' => 'member']);
        $this->record('rest', $user, $workspace, $this->payload($invoice), 'synthetic-role', 403);
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->assertSame('issued', $foreign->fresh()->status);
    }

    public function test_admin_can_record_but_ordinary_member_cannot_read_or_record(): void
    {
        [$user, $workspace, $invoice] = $this->fixture();
        $workspace->memberships()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->update(['role' => 'admin']);
        $this->record('rest', $user, $workspace, $this->payload($invoice), 'synthetic-admin');
        $workspace->memberships()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->update(['role' => 'member']);
        $this->record('rest', $user, $workspace, $this->payload($invoice), 'synthetic-member', 403);
        $this->actingAsMcp($user, ['payments:read']);
        $this->getJson($this->url($workspace).'?invoice_id='.$invoice->public_id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_read_scope_does_not_authorize_record_and_record_scope_does_not_list(): void
    {
        [$user, $workspace, $invoice] = $this->fixture();
        $this->actingAsMcp($user, ['payments:read']);
        $this->withHeader('Idempotency-Key', 'synthetic-scope')->postJson($this->url($workspace), $this->payload($invoice))->assertForbidden();
        $this->actingAsMcp($user, ['payments:record']);
        $this->getJson($this->url($workspace).'?invoice_id='.$invoice->public_id)->assertForbidden();
    }

    public function test_list_is_bounded_tenant_scoped_and_excludes_private_fields(): void
    {
        [$owner, $workspace, $invoice, $company] = $this->fixture();
        [, , $foreign] = $this->fixture();
        $service = app(InvoiceLifecycleService::class);
        foreach (range(1, 3) as $number) {
            $service->applyPayment($invoice, ['amount' => 100, 'currency' => 'USD', 'method' => 'wire',
                'reference' => 'Synthetic reference', 'notes' => 'Synthetic private note', 'provider_payment_identifier' => 'synthetic-provider-secret'], $workspace);
        }
        $this->actingAsMcp($owner, ['payments:read']);
        $url = $this->url($workspace).'?invoice_id='.$invoice->public_id;
        $first = $this->getJson($url.'&limit=2')->assertOk()->assertJsonCount(2, 'data')->json();
        $this->assertNotNull($first['next_cursor']);
        $second = $this->getJson($url.'&limit=2&cursor='.urlencode($first['next_cursor']))->assertOk()->assertJsonCount(1, 'data')->json();
        $this->assertNull($second['next_cursor']);
        $this->assertNotSame($first['data'][0]['id'], $second['data'][0]['id']);
        $this->assertArrayNotHasKey('notes', $first['data'][0]);
        $this->assertArrayNotHasKey('provider_payment_identifier', $first['data'][0]);
        $this->assertArrayNotHasKey('external_finance_transaction_uuid', $first['data'][0]);
        $this->assertSame('Synthetic reference', $first['data'][0]['reference']);
        $this->getJson($this->url($workspace).'?company_id='.$company->public_id)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($this->url($workspace).'?invoice_id='.$foreign->public_id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'&limit=101')->assertUnprocessable();
        $this->getJson($this->url($workspace))->assertUnprocessable();
        $this->getJson($this->url($workspace).'?company_id='.$company->public_id.'&cursor='.urlencode($first['next_cursor']))->assertUnprocessable();

        $portal = User::factory()->create(['email' => 'synthetic-portal@example.test']);
        $company->portalUsers()->attach($portal->id, ['workspace_id' => $workspace->id]);
        $invoice->update(['is_visible_to_client' => true]);
        $this->actingAsMcp($portal, ['payments:read', 'payments:record']);
        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.reference', null);
        $this->withHeader('Idempotency-Key', 'synthetic-portal')->postJson($this->url($workspace), $this->payload($invoice))->assertForbidden();
        $invoice->update(['is_visible_to_client' => false]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_entire_record_and_list_requests_scope_tenant_reads_and_writes(): void
    {
        [$user, $workspace, $invoice] = $this->fixture();
        $this->actingAsMcp($user, ['payments:read', 'payments:record']);
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $sql = str_replace(['"', '`'], '', strtolower($event->sql));
            if (preg_match('/^(?:select .*? from|update|insert into|delete from) ([a-z_]+)/', $sql, $match) === 1) {
                $queries[] = [$match[1], $sql];
            }
        });
        $this->withHeader('Idempotency-Key', 'synthetic-query')->postJson($this->url($workspace), $this->payload($invoice))->assertCreated();
        $this->getJson($this->url($workspace).'?invoice_id='.$invoice->public_id)->assertOk();
        $this->assertContains('client_invoices', array_column($queries, 0));
        $this->assertContains('client_invoice_payments', array_column($queries, 0));
        $this->assertContains('agent_mutation_receipts', array_column($queries, 0));
        foreach ($queries as [$table, $sql]) {
            // These identity roots have no owning workspace column.
            if (in_array($table, ['users', 'workspaces'], true)) {
                continue;
            }
            if (str_starts_with($sql, 'insert into')) {
                $this->assertStringContainsString('workspace_id', strstr($sql, 'values', true), $sql);
            } else {
                // A projected workspace_id is not a tenant predicate.
                $where = strstr($sql, ' where ');
                $this->assertNotFalse($where, $sql);
                $this->assertMatchesRegularExpression('/(?:\\b[a-z_]+\\.)?workspace_id\\s*=/', $where, $sql);
            }
        }
    }

    public function test_mcp_list_returns_the_rest_contract_and_discovery_hides_record_from_portal_users(): void
    {
        [$owner, $workspace, $invoice, $company] = $this->fixture();
        $this->record('rest', $owner, $workspace, $this->payload($invoice), 'synthetic-list');
        $portal = User::factory()->create(['email' => 'synthetic-list-portal@example.test']);
        $company->portalUsers()->attach($portal->id, ['workspace_id' => $workspace->id]);
        $invoice->update(['is_visible_to_client' => true]);
        $this->actingAsMcp($portal, ['mcp:use', 'payments:read', 'payments:record']);
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic list test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = $init->headers->get('Mcp-Session-Id');
        $names = array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers)->assertOk()->json('result.tools'), 'name');
        $this->assertContains('payments.list', $names);
        $this->assertNotContains('payments.record', $names);
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'payments.list', 'arguments' => ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoice->public_id]],
        ], $headers)->assertOk()->json('result.structuredContent');
        $rest = $this->getJson($this->url($workspace).'?invoice_id='.$invoice->public_id)->assertOk()->json();
        $this->assertSame($rest, $result);
        $this->assertCount(1, $result['data']);
    }

    public function test_missing_and_blank_idempotency_keys_are_refused(): void
    {
        [$user, $workspace, $invoice] = $this->fixture();
        $this->actingAsMcp($user, ['payments:record']);
        $this->postJson($this->url($workspace), $this->payload($invoice))->assertUnprocessable();
        $this->withHeader('Idempotency-Key', '   ')->postJson($this->url($workspace), $this->payload($invoice))->assertUnprocessable();
        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    /** @return array{User, Workspace, ClientInvoice, ClientCompany} */
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

        return [$user, $workspace, $invoice, $company];
    }

    private function payload(ClientInvoice $invoice): array
    {
        return ['invoice_id' => $invoice->public_id, 'amount' => 10000, 'currency' => 'USD', 'received_on' => now()->toDateString(), 'method' => 'cash'];
    }

    private function url(Workspace $workspace): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/payments';
    }

    private function record(string $transport, User $user, Workspace $workspace, array $payload, string $key, int $expected = 201): mixed
    {
        $this->actingAsMcp($user, ['mcp:use', 'payments:record']);
        if ($transport === 'rest') {
            $response = $this->withHeader('Idempotency-Key', $key)->postJson($this->url($workspace), $payload)->assertStatus($expected);

            return $response->json('data');
        }
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic payment test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = $init->headers->get('Mcp-Session-Id');
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'payments.record', 'arguments' => [...$payload, 'workspace_id' => $workspace->public_id, 'idempotency_key' => $key]],
        ], $headers)->assertOk()->json();
        if ($expected !== 201) {
            $this->assertArrayHasKey('error', $result, json_encode($result));
            $audit = AgentMutationAudit::query()->where('workspace_id', $workspace->id)->latest('id')->firstOrFail();
            $this->assertSame('failed', $audit->outcome);

            return null;
        }
        $this->assertArrayHasKey('result', $result, json_encode($result));
        $this->assertFalse($result['result']['isError'] ?? false);

        return $result['result']['structuredContent']['data'] ?? $result['result'];
    }
}
