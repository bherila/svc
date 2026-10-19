<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * `payments.record` with `finance_reconciliation` (S6): the payment and its
 * finance allocation are written in one transaction under one receipt, so a
 * retry replays both and never records either twice.
 */
final class AgentPaymentRecordReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'payments:record', 'billing:read', 'finance:reconcile'];

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_the_payment_and_its_allocation_are_written_together(string $transport): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $transaction = (string) Str::uuid();
        $recorded = $this->record($transport, $owner, $workspace, $this->payload($invoice, 7000, [
            'external_system_slug' => 'synthetic-ledger',
            'external_transaction_uuid' => strtoupper($transaction),
            'allocated_amount' => 7000,
        ]), 'synthetic-deposit');

        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame($payment->public_id, $recorded[0]['id']);
        $this->assertSame('succeeded', $payment->status);
        $this->assertNull($payment->external_finance_transaction_uuid, 'The legacy column is not set from the allocation');
        $this->assertNull($recorded[0]['external_finance_transaction_uuid']);

        $allocation = PaymentReconciliation::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame($payment->id, $allocation->client_invoice_payment_id);
        $this->assertSame('synthetic-ledger', $allocation->external_system_slug);
        $this->assertSame(strtolower($transaction), $allocation->external_transaction_uuid);
        $this->assertSame(7000, $allocation->allocated_amount);
        $this->assertSame('USD', $allocation->currency);
        $this->assertTrue($allocation->is_active);
        $this->assertNull($allocation->reconciled_on);
        $this->assertSame($owner->id, $allocation->created_by_user_id);

        foreach (['payments.record', 'finance.reconciliations.upsert'] as $operation) {
            $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => $operation, 'outcome' => 'success']);
        }

        // The finance read sees the allocation the record wrote.
        $this->actingAsMcp($owner, ['finance:read']);
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/invoice-payments')->assertOk()
            ->assertJsonPath('data.0.id', $payment->public_id)
            ->assertJsonPath('data.0.reconciled_amount', 7000)
            ->assertJsonPath('data.0.unreconciled_amount', 0);
    }

    #[DataProvider('transports')]
    public function test_a_retry_replays_the_payment_and_its_allocation_once(string $transport): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $payload = $this->payload($invoice, 4000, $this->allocation(2500));
        $first = $this->record($transport, $owner, $workspace, $payload, 'synthetic-retry');
        $other = $transport === 'rest' ? 'mcp' : 'rest';
        $this->assertSame($first, $this->record($transport, $owner, $workspace, $payload, 'synthetic-retry'));
        $this->assertSame($first, $this->record($other, $owner, $workspace, $payload, 'synthetic-retry'), 'A retry may switch transports');

        $this->assertSame(1, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, PaymentReconciliation::query()->where('workspace_id', $workspace->id)->count());
        foreach (['payments.record', 'finance.reconciliations.upsert'] as $operation) {
            $this->assertSame(2, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', $operation)->where('outcome', 'replay')->count(), $operation);
        }

        // The same key with another allocation is another request.
        $this->record($transport, $owner, $workspace, $this->payload($invoice, 4000, $this->allocation(2000)), 'synthetic-retry', 409);
        $unreconciled = $payload;
        unset($unreconciled['finance_reconciliation']);
        $this->record($transport, $owner, $workspace, $unreconciled, 'synthetic-retry', 409);
        $this->assertSame(1, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(2500, PaymentReconciliation::query()->where('workspace_id', $workspace->id)->sole()->allocated_amount);
    }

    /**
     * Omitted and null mean no allocation, and hash alike, so a receipt
     * written before the field existed still replays.
     */
    public function test_a_null_allocation_is_the_same_request_as_none(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $payload = $this->payload($invoice, 4000);
        $first = $this->record('rest', $owner, $workspace, $payload, 'synthetic-null');
        $this->assertSame($first, $this->record('rest', $owner, $workspace, [...$payload, 'finance_reconciliation' => null], 'synthetic-null'));
        $this->assertSame($first, $this->record('mcp', $owner, $workspace, [...$payload, 'finance_reconciliation' => null], 'synthetic-null'));
        $this->assertSame(1, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseCount('payment_reconciliations', 0);
        $this->assertDatabaseMissing('agent_mutation_audits', ['operation' => 'finance.reconciliations.upsert']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidAllocations(): iterable
    {
        $valid = ['external_system_slug' => 'synthetic-ledger', 'external_transaction_uuid' => '7e0b3c9a-5d1f-4f7e-9a51-1c2b3d4e5f60', 'allocated_amount' => 1000];
        // Closed: the allocation takes exactly its three fields.
        foreach (['currency' => 'USD', 'is_active' => true, 'reconciled_on' => '2026-10-01', 'payment_id' => '7e0b3c9a-5d1f-4f7e-9a51-1c2b3d4e5f61'] as $field => $value) {
            yield "unknown field {$field}" => [[...$valid, $field => $value]];
        }
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield "missing {$field}" => [$missing];
        }
        yield 'empty object' => [[]];
        yield 'not an object' => ['synthetic-ledger'];
        yield 'list' => [array_values($valid)];
        yield 'uppercase slug' => [[...$valid, 'external_system_slug' => 'Synthetic-Ledger']];
        yield 'slug with a space' => [[...$valid, 'external_system_slug' => 'synthetic ledger']];
        yield 'slug too long' => [[...$valid, 'external_system_slug' => str_repeat('a', 81)]];
        yield 'not a uuid' => [[...$valid, 'external_transaction_uuid' => 'synthetic-transaction']];
        yield 'zero amount' => [[...$valid, 'allocated_amount' => 0]];
        yield 'fractional amount' => [[...$valid, 'allocated_amount' => 10.5]];
        yield 'over the payment' => [[...$valid, 'allocated_amount' => 4001]];
    }

    #[DataProvider('invalidAllocations')]
    public function test_an_invalid_allocation_is_refused_before_anything_is_written(mixed $allocation): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $this->record('rest', $owner, $workspace, [...$this->payload($invoice, 4000), 'finance_reconciliation' => $allocation], 'synthetic-invalid', 422);
        $this->assertNothingWritten($workspace);
    }

    public function test_the_mcp_input_schema_closes_the_allocation_too(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $result = $this->callRecordTool($owner, $workspace, [...$this->payload($invoice, 4000, [...$this->allocation(1000), 'is_active' => true]), 'idempotency_key' => 'synthetic-mcp-closed']);
        $this->assertStringContainsString('finance_reconciliation', json_encode($result['error'] ?? [], JSON_THROW_ON_ERROR));
        $this->assertNothingWritten($workspace);
    }

    #[DataProvider('transports')]
    public function test_a_pending_payment_cannot_be_reconciled(string $transport): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $this->record($transport, $owner, $workspace, [...$this->payload($invoice, 4000, $this->allocation(4000)), 'status' => 'pending'], 'synthetic-pending', 422);
        $this->assertNothingWritten($workspace);
    }

    #[DataProvider('transports')]
    public function test_reconciling_requires_the_finance_reconcile_scope_also_on_a_replay(string $transport): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $payload = $this->payload($invoice, 4000, $this->allocation(4000));
        $withoutReconcile = ['mcp:use', 'payments:record', 'billing:read'];
        $this->record($transport, $owner, $workspace, $payload, 'synthetic-scope', 403, $withoutReconcile);
        $this->assertNothingWritten($workspace);

        // Without an allocation the same token records as it always has.
        $plain = $payload;
        unset($plain['finance_reconciliation']);
        $this->record($transport, $owner, $workspace, $plain, 'synthetic-plain', 201, $withoutReconcile);

        $current = $this->payload($invoice->refresh(), 4000, $this->allocation(4000));
        $this->record($transport, $owner, $workspace, $current, 'synthetic-scope-replay');
        $this->record($transport, $owner, $workspace, $current, 'synthetic-scope-replay', 403, $withoutReconcile);
        $this->assertSame(2, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, PaymentReconciliation::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_a_manager_is_still_required(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->record('rest', $owner, $workspace, $this->payload($invoice, 4000, $this->allocation(4000)), 'synthetic-member', 403);
        $this->assertNothingWritten($workspace);
    }

    /**
     * The allocation is written after the payment, in the same transaction: a
     * failure writing it leaves no payment, receipt or allocation behind.
     */
    public function test_a_failure_writing_the_allocation_writes_nothing(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        DB::listen(static function (QueryExecuted $event): void {
            if (str_starts_with(str_replace(['"', '`'], '', strtolower($event->sql)), 'insert into payment_reconciliations')) {
                throw new RuntimeException('Synthetic allocation failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->record('rest', $owner, $workspace, $this->payload($invoice, 4000, $this->allocation(4000)), 'synthetic-atomic', 500);
            $this->fail('The allocation failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic allocation failure.', $exception->getMessage());
        }
        $this->assertNothingWritten($workspace);
        $this->assertSame('issued', $invoice->refresh()->status);
    }

    /** The guard refuses a reconciling record as it refuses any other. */
    public function test_the_collection_guard_applies(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'cheque', 'status' => 'pending'], $workspace);
        $message = $this->record('rest', $owner, $workspace, $this->payload($invoice->refresh(), 4000, $this->allocation(4000)), 'synthetic-guarded', 422);
        $this->assertStringContainsString('still in flight', (string) $message);
        $this->assertSame(1, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseCount('payment_reconciliations', 0);
    }

    private function assertNothingWritten(Workspace $workspace): void
    {
        $this->assertSame(0, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseCount('payment_reconciliations', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    /** @return array{User, Workspace, ClientInvoice, ClientCompany} */
    private function fixture(): array
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        $owner = User::factory()->create(['email' => 'synthetic-'.str()->uuid().'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic reconciled payments', 'slug' => 'synthetic-'.str()->uuid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'SYNTHETIC-'.str()->uuid()], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 10000],
        ]), $workspace);

        return [$owner, $workspace, $invoice, $company];
    }

    /** @return array{external_system_slug: string, external_transaction_uuid: string, allocated_amount: int} */
    private function allocation(int $amount): array
    {
        return ['external_system_slug' => 'synthetic-ledger', 'external_transaction_uuid' => '7e0b3c9a-5d1f-4f7e-9a51-1c2b3d4e5f60', 'allocated_amount' => $amount];
    }

    /**
     * @param  array<string, mixed>|null  $allocation
     * @return array<string, mixed>
     */
    private function payload(ClientInvoice $invoice, int $amount, ?array $allocation = null): array
    {
        return ['confirm' => true, 'expected_version' => AgentApiVersion::for($invoice), 'invoice_id' => $invoice->public_id, 'amount' => $amount,
            'currency' => 'USD', 'received_on' => now()->toDateString(), 'method' => 'ach'] + ($allocation === null ? [] : ['finance_reconciliation' => $allocation]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $scopes
     */
    private function record(string $transport, User $user, Workspace $workspace, array $payload, string $key, int $expected = 201, array $scopes = self::SCOPES): mixed
    {
        $this->actingAsMcp($user, $scopes);
        if ($transport === 'rest') {
            $response = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/workspaces/'.$workspace->public_id.'/payments', $payload)->assertStatus($expected);

            return $expected === 201 ? $response->json('data') : $response->json('message');
        }
        $result = $this->callRecordTool($user, $workspace, [...$payload, 'idempotency_key' => $key], $scopes);
        if ($expected !== 201) {
            // The tool reaches the REST route, and a refusal there comes back
            // as a tool error carrying the route's message.
            $this->assertTrue($result['result']['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

            return (string) ($result['result']['content'][0]['text'] ?? '');
        }
        $this->assertFalse($result['result']['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        return $result['result']['structuredContent']['data'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function callRecordTool(User $user, Workspace $workspace, array $arguments, array $scopes = self::SCOPES): array
    {
        $this->actingAsMcp($user, $scopes);
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic reconciliation test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = $init->headers->get('Mcp-Session-Id');

        return $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'payments.record', 'arguments' => [...$arguments, 'workspace_id' => $workspace->public_id]],
        ], $headers)->assertOk()->json();
    }
}
