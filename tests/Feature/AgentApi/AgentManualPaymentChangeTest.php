<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentCapabilities;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InspectsAgentOperations;
use Tests\TestCase;

/**
 * `payments.set_status` and `payments.refund` (#455), over REST and MCP, and
 * their console twins: a hand-recorded payment withdrawn, failed, cleared or
 * refunded, with a reason, the version read, and the invoice recomputed.
 */
final class AgentManualPaymentChangeTest extends TestCase
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
    public function test_a_bounced_payment_is_failed_and_the_invoice_reopens(string $transport): void
    {
        [$user, $workspace, $invoice, $payment] = $this->fixture();
        $this->assertSame('paid', $invoice->fresh()?->status);
        $body = $this->statusBody($payment, 'failed');

        $first = $this->change($transport, 'payments.set_status', $user, $workspace, $payment->public_id, $body, 'synthetic-bounce');
        $fresh = $payment->fresh();
        $this->assertSame('failed', $fresh?->status);
        $this->assertSame(10000, $fresh?->amount);
        $this->assertSame('issued', $invoice->fresh()?->status);
        $this->assertSame(10000, $invoice->fresh()?->balance_amount);
        $this->assertSame(0, $invoice->fresh()?->paid_amount);

        // Shaped exactly like a payments.list row, with its new version.
        $this->actingAsMcp($user, ['payments:read']);
        $listed = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/payments?invoice_id='.$invoice->public_id)->assertOk()->json('data.0');
        $this->assertSame($listed, $first);

        // The reason is in the history, with the status it replaced.
        $activity = ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_failed')->sole();
        $this->assertSame('Synthetic cheque bounced', $activity->payload['reason'] ?? null);
        $this->assertSame('succeeded', $activity->payload['previous_status'] ?? null);

        // A retry replays; the same key with another body is a conflict; a new
        // key against the version it already moved past is a conflict.
        $this->assertSame($first, $this->change($transport, 'payments.set_status', $user, $workspace, $payment->public_id, $body, 'synthetic-bounce'));
        $this->change($transport, 'payments.set_status', $user, $workspace, $payment->public_id, [...$body, 'status' => 'canceled'], 'synthetic-bounce', 409);
        $this->change($transport, 'payments.set_status', $user, $workspace, $payment->public_id, [...$body, 'status' => 'canceled'], 'synthetic-stale', 409);
        $this->assertSame(1, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_failed')->count());
        $this->assertSame('failed', $payment->fresh()?->status);

        // failed is final.
        $this->change($transport, 'payments.set_status', $user, $workspace, $payment->public_id, $this->statusBody($payment->fresh() ?? $payment, 'succeeded'), 'synthetic-revive', 422);
    }

    public function test_a_pending_cheque_clears_and_settles_the_invoice(): void
    {
        [$user, $workspace, $invoice] = $this->fixture(paid: false);
        $pending = app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => 10000, 'currency' => 'USD', 'method' => 'check', 'status' => 'pending'], $workspace);
        $this->assertSame('issued', $invoice->fresh()?->status);

        $this->change('rest', 'payments.set_status', $user, $workspace, $pending->public_id, $this->statusBody($pending, 'succeeded'), 'synthetic-clear');
        $this->assertSame('succeeded', $pending->fresh()?->status);
        $this->assertSame('paid', $invoice->fresh()?->status);
        $this->assertSame(1, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_received')->count());
    }

    public function test_setting_the_status_a_payment_already_has_records_nothing(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $before = AgentApiVersion::for($payment);
        $result = $this->change('rest', 'payments.set_status', $user, $workspace, $payment->public_id, $this->statusBody($payment, 'succeeded'), 'synthetic-same');
        $this->assertSame($before, $result['version'] ?? null);
        $this->assertSame(0, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->whereIn('action', ['invoice.payment_received', 'invoice.payment_failed'])->where('subject_public_id', $payment->public_id)->where('payload->reason', 'Synthetic cheque bounced')->count());
    }

    #[DataProvider('transports')]
    public function test_a_refund_is_recorded_in_parts_and_reopens_the_balance(string $transport): void
    {
        [$user, $workspace, $invoice, $payment] = $this->fixture();

        $partial = $this->change($transport, 'payments.refund', $user, $workspace, $payment->public_id, $this->refundBody($payment, 4000), 'synthetic-refund-1');
        $this->assertSame(4000, $partial['refunded_amount'] ?? null);
        $this->assertSame('succeeded', $partial['status'] ?? null);
        $this->assertSame('partially_paid', $invoice->fresh()?->status);
        $this->assertSame(4000, $invoice->fresh()?->balance_amount);

        // Partly refunded: it cannot fail or be withdrawn, only refunded further.
        $this->change($transport, 'payments.set_status', $user, $workspace, $payment->public_id, $this->statusBody($payment->fresh() ?? $payment, 'canceled'), 'synthetic-withdraw-partial', 422);
        $this->change($transport, 'payments.refund', $user, $workspace, $payment->public_id, $this->refundBody($payment->fresh() ?? $payment, 6001), 'synthetic-refund-too-much', 422);

        $full = $this->change($transport, 'payments.refund', $user, $workspace, $payment->public_id, $this->refundBody($payment->fresh() ?? $payment, 6000), 'synthetic-refund-2');
        $this->assertSame(10000, $full['refunded_amount'] ?? null);
        $this->assertSame('refunded', $full['status'] ?? null);
        $this->assertSame('issued', $invoice->fresh()?->status);
        $this->assertSame(10000, $invoice->fresh()?->balance_amount);
        $activities = ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_refunded')->orderBy('id')->get();
        $this->assertCount(2, $activities);
        $this->assertSame([0, 4000], $activities->map(fn (ClientCompanyActivity $activity): mixed => $activity->payload['previous_refunded_amount'] ?? null)->all());
        $this->assertSame('Synthetic goodwill refund', $activities->last()?->payload['reason'] ?? null);

        // Nothing is left to refund.
        $this->change($transport, 'payments.refund', $user, $workspace, $payment->public_id, $this->refundBody($payment->fresh() ?? $payment, 1), 'synthetic-refund-3', 422);
    }

    public function test_a_processor_payment_follows_its_processor(): void
    {
        [$user, $workspace, $invoice] = $this->fixture(paid: false);
        $stripe = app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => 10000, 'currency' => 'USD', 'method' => 'stripe',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_'.Str::random(8)], $workspace);

        $this->change('rest', 'payments.set_status', $user, $workspace, $stripe->public_id, $this->statusBody($stripe, 'failed'), 'synthetic-stripe-fail', 422);
        $this->change('rest', 'payments.refund', $user, $workspace, $stripe->public_id, $this->refundBody($stripe, 100), 'synthetic-stripe-refund', 422);
        $this->assertSame('succeeded', $stripe->fresh()?->status);
        $this->assertSame(0, $stripe->fresh()?->refunded_amount);

        $this->actingAsMcp($user, ['payments:read']);
        $this->assertFalse($this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/payments?invoice_id='.$invoice->public_id)->assertOk()->json('data.0.manual'));
    }

    public function test_reconciled_money_cannot_stop_counting(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        PaymentReconciliation::query()->create(['workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id,
            'external_system_slug' => 'synthetic-ledger', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 10000,
            'currency' => 'USD', 'reconciled_on' => now()->toDateString(), 'is_active' => true]);

        $this->change('rest', 'payments.set_status', $user, $workspace, $payment->public_id, $this->statusBody($payment, 'canceled'), 'synthetic-reconciled', 422);
        $this->change('rest', 'payments.refund', $user, $workspace, $payment->public_id, $this->refundBody($payment, 1), 'synthetic-reconciled-refund', 422);
        $this->assertSame('succeeded', $payment->fresh()?->status);
        $this->assertSame(0, $payment->fresh()?->refunded_amount);
    }

    /**
     * A change made from a stale view of the invoice is a conflict, not a
     * total over money that is no longer there. MariaDB's REPEATABLE READ is
     * what produces the stale view; here the invoice's revision moves between
     * the locked read and the plain one, which is the difference the guard
     * looks for.
     */
    public function test_an_invoice_that_moved_while_the_change_waited_is_a_conflict(): void
    {
        [$user, $workspace, $invoice] = $this->fixture(paid: false);
        $pending = app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => 10000, 'currency' => 'USD', 'method' => 'check', 'status' => 'pending'], $workspace);
        $moved = false;
        DB::listen(function (QueryExecuted $event) use ($invoice, &$moved): void {
            $sql = str_replace(['"', '`'], '', strtolower($event->sql));
            if (! $moved && str_starts_with($sql, 'select * from client_invoices') && in_array($invoice->id, $event->bindings, false)) {
                $moved = true;
                DB::table('client_invoices')->where('workspace_id', $invoice->workspace_id)->where('id', $invoice->id)->increment('lock_version');
            }
        });

        $this->change('rest', 'payments.set_status', $user, $workspace, $pending->public_id, $this->statusBody($pending, 'succeeded'), 'synthetic-moved', 409);
        $this->assertTrue($moved);
        $this->assertSame('pending', $pending->fresh()?->status);
        $this->assertSame('issued', $invoice->fresh()?->status);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function refusedBodies(): iterable
    {
        yield 'no confirmation' => ['payments.set_status', ['confirm' => false]];
        yield 'no reason' => ['payments.set_status', ['reason' => null]];
        yield 'blank reason' => ['payments.set_status', ['reason' => '   ']];
        yield 'reason too long' => ['payments.set_status', ['reason' => str_repeat('r', 501)]];
        yield 'no version' => ['payments.set_status', ['expected_version' => null]];
        yield 'refunded is not a status to set' => ['payments.set_status', ['status' => 'refunded']];
        yield 'disputed is the processor\'s' => ['payments.set_status', ['status' => 'disputed']];
        yield 'back to pending' => ['payments.set_status', ['status' => 'pending']];
        yield 'another field' => ['payments.set_status', ['amount' => 1]];
        yield 'refund of nothing' => ['payments.refund', ['amount' => 0]];
        yield 'refund as text' => ['payments.refund', ['amount' => '100']];
        yield 'refund with a status' => ['payments.refund', ['status' => 'refunded']];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('refusedBodies')]
    public function test_malformed_bodies_are_refused_before_anything_is_written(string $operation, array $change): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $base = $operation === 'payments.refund' ? $this->refundBody($payment, 100) : $this->statusBody($payment, 'failed');
        $body = [...$base, ...$change];
        foreach (['reason', 'expected_version'] as $optional) {
            if (array_key_exists($optional, $change) && $change[$optional] === null) {
                unset($body[$optional]);
            }
        }

        $this->change('rest', $operation, $user, $workspace, $payment->public_id, $body, 'synthetic-malformed', 422);
        $this->assertSame('succeeded', $payment->fresh()?->status);
        $this->assertSame(0, $payment->fresh()?->refunded_amount);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
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
    public function test_both_write_flags_close_the_tools_and_the_routes(bool $outer, bool $inner): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        config(['agent_api.writes_enabled' => $outer, 'agent_api.payment_writes_enabled' => $inner]);
        $names = $this->deployedToolNames();
        $capabilities = app(AgentCapabilities::class)->forWorkspace($user, $workspace, fn (string $scope): bool => true)['capabilities'];

        $this->assertSame($outer && $inner, in_array('payments.set_status', $names, true));
        $this->assertSame($outer && $inner, in_array('payments.refund', $names, true));
        $this->assertSame($outer && $inner, in_array('payments:record', $capabilities, true));
        $this->change('rest', 'payments.set_status', $user, $workspace, $payment->public_id, $this->statusBody($payment, 'failed'), 'synthetic-flag', $outer && $inner ? 200 : 404);
        $this->assertSame($outer && $inner ? 'failed' : 'succeeded', $payment->fresh()?->status);
    }

    public function test_both_payment_scopes_are_required(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        foreach ([['payments:read'], ['payments:record']] as $scopes) {
            $this->actingAsMcp($user, $scopes);
            $this->withHeader('Idempotency-Key', 'synthetic-scope')
                ->postJson($this->url($workspace, $payment->public_id, 'payments.set_status'), $this->statusBody($payment, 'failed'))
                ->assertForbidden();
        }
        $this->assertSame('succeeded', $payment->fresh()?->status);
    }

    #[DataProvider('transports')]
    public function test_members_are_refused_and_a_revoked_role_cannot_replay(string $transport): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $body = $this->refundBody($payment, 100);
        $this->change($transport, 'payments.refund', $user, $workspace, $payment->public_id, $body, 'synthetic-role');

        $workspace->memberships()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->update(['role' => 'member']);
        $this->change($transport, 'payments.refund', $user, $workspace, $payment->public_id, $body, 'synthetic-role', 403);
        $this->change($transport, 'payments.refund', $user, $workspace, $payment->public_id, $this->refundBody($payment->fresh() ?? $payment, 100), 'synthetic-member', 403);
        $this->assertSame(100, $payment->fresh()?->refunded_amount);
    }

    #[DataProvider('transports')]
    public function test_another_workspaces_payment_is_not_found(string $transport): void
    {
        [$user, $workspace] = $this->fixture();
        [, , , $foreign] = $this->fixture();

        $this->change($transport, 'payments.set_status', $user, $workspace, $foreign->public_id, $this->statusBody($foreign, 'failed'), 'synthetic-foreign', 404);
        $this->change($transport, 'payments.refund', $user, $workspace, $foreign->public_id, $this->refundBody($foreign, 100), 'synthetic-foreign-refund', 404);
        $this->assertSame('succeeded', $foreign->fresh()?->status);
        $this->assertSame(0, $foreign->fresh()?->refunded_amount);
    }

    public function test_the_whole_request_scopes_every_tenant_read_and_write(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $this->actingAsMcp($user, ['payments:record', 'payments:read']);
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $sql = str_replace(['"', '`'], '', strtolower($event->sql));
            if (preg_match('/^(?:select .*? from|update|insert into|delete from) ([a-z_]+)/', $sql, $match) === 1) {
                $queries[] = [$match[1], $sql];
            }
        });

        $this->withHeader('Idempotency-Key', 'synthetic-query')
            ->postJson($this->url($workspace, $payment->public_id, 'payments.refund'), $this->refundBody($payment, 100))
            ->assertOk();

        $this->assertContains('client_invoice_payments', array_column($queries, 0));
        $this->assertContains('client_invoices', array_column($queries, 0));
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

    public function test_the_console_dry_run_reports_the_change_and_writes_nothing(): void
    {
        [, $workspace, $invoice, $payment] = $this->fixture();

        $this->artisan('svc:billing:payment-status', [
            'payment' => $payment->public_id, '--workspace' => $workspace->public_id, '--status' => 'canceled',
            '--reason' => 'Synthetic duplicate entry', '--dry-run' => true, '--format' => 'json',
        ])->expectsOutputToContain('"dry_run":true,"changed":true,"status":{"old":"succeeded","new":"canceled"},"refunded_amount":{"old":0,"new":0},"invoice_status":"issued","invoice_balance":10000')->assertSuccessful();
        $this->assertSame('succeeded', $payment->fresh()?->status);
        $this->assertSame('paid', $invoice->fresh()?->status);
        $this->assertSame(0, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_canceled')->count());

        $this->artisan('svc:billing:payment-status', [
            'payment' => $payment->public_id, '--workspace' => $workspace->public_id, '--status' => 'canceled',
            '--reason' => 'Synthetic duplicate entry', '--expected-version' => AgentApiVersion::for($payment),
        ])->assertSuccessful();
        $this->assertSame('canceled', $payment->fresh()?->status);
        $this->assertSame('issued', $invoice->fresh()?->status);
        $this->assertSame('Synthetic duplicate entry', ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_canceled')->sole()->payload['reason'] ?? null);
    }

    /** The finance transaction is part of what a keyed payment is: a retry naming another is refused. */
    public function test_a_keyed_retry_naming_another_finance_transaction_is_refused(): void
    {
        [, $workspace, $invoice] = $this->fixture(paid: false);
        $record = fn (string $transaction): int => $this->artisan('svc:billing:payment', [
            'invoice' => $invoice->public_id, 'amount' => '1000', 'currency' => 'USD', 'method' => 'wire',
            '--workspace' => $workspace->public_id, '--idempotency-key' => 'synthetic-finance-key', '--external-finance-transaction' => $transaction,
        ])->run();

        $this->assertSame(0, $record('33333333-3333-4333-8333-333333333333'));
        $this->assertSame(0, $record('33333333-3333-4333-8333-333333333333'));
        $this->assertSame(1, $record('44444444-4444-4444-8444-444444444444'));
        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('33333333-3333-4333-8333-333333333333', $payment->external_finance_transaction_uuid);
    }

    public function test_the_console_refund_is_bounded_and_scoped(): void
    {
        [, $workspace, , $payment] = $this->fixture();
        [, $other] = $this->fixture();

        $this->artisan('svc:billing:refund-payment', ['payment' => $payment->public_id, '--workspace' => $other->public_id, '--amount' => '100', '--reason' => 'Synthetic'])->assertFailed();
        $this->artisan('svc:billing:refund-payment', ['payment' => $payment->public_id, '--workspace' => $workspace->public_id, '--amount' => '1.5', '--reason' => 'Synthetic'])->assertExitCode(2);
        $this->artisan('svc:billing:refund-payment', ['payment' => $payment->public_id, '--workspace' => $workspace->public_id, '--amount' => '100'])->assertExitCode(2);
        $this->artisan('svc:billing:refund-payment', ['payment' => $payment->public_id, '--workspace' => $workspace->public_id, '--amount' => '10001', '--reason' => 'Synthetic'])->assertFailed();
        $this->artisan('svc:billing:refund-payment', ['payment' => $payment->public_id, '--workspace' => $workspace->public_id, '--amount' => '100', '--reason' => 'Synthetic', '--expected-version' => str_repeat('a', 64)])->assertFailed();
        $this->assertSame(0, $payment->fresh()?->refunded_amount);

        $this->artisan('svc:billing:refund-payment', ['payment' => $payment->public_id, '--workspace' => $workspace->public_id, '--amount' => '2500', '--reason' => 'Synthetic goodwill', '--format' => 'json'])
            ->expectsOutputToContain('"refunded_amount":{"old":0,"new":2500}')->assertSuccessful();
        $this->assertSame(2500, $payment->fresh()?->refunded_amount);
    }

    /** @return array<string, mixed> */
    private function statusBody(ClientInvoicePayment $payment, string $status): array
    {
        return ['confirm' => true, 'expected_version' => AgentApiVersion::for($payment), 'reason' => 'Synthetic cheque bounced', 'status' => $status];
    }

    /** @return array<string, mixed> */
    private function refundBody(ClientInvoicePayment $payment, int $amount): array
    {
        return ['confirm' => true, 'expected_version' => AgentApiVersion::for($payment), 'reason' => 'Synthetic goodwill refund', 'amount' => $amount];
    }

    /** @return array{User, Workspace, ClientInvoice, ClientInvoicePayment} */
    private function fixture(bool $paid = true): array
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        $user = User::factory()->create(['email' => 'synthetic-'.Str::lower(Str::random(8)).'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic manual payments', 'slug' => 'synthetic-'.Str::lower(Str::random(10))]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'SYNTHETIC-'.Str::upper(Str::random(8))], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 10000],
        ]), $workspace);
        $payment = $paid
            ? $service->applyPayment($invoice, ['amount' => 10000, 'currency' => 'USD', 'method' => 'check', 'reference' => 'SYN-CHQ-1', 'received_on' => now()->toDateString()], $workspace)
            : new ClientInvoicePayment;

        return [$user, $workspace, $invoice, $payment];
    }

    private function url(Workspace $workspace, string $paymentId, string $operation): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/payments/'.$paymentId.($operation === 'payments.refund' ? '/refunds' : '/status');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function change(string $transport, string $operation, User $user, Workspace $workspace, string $paymentId, array $body, string $key, int $expected = 200): array
    {
        $this->actingAsMcp($user, ['mcp:use', 'payments:read', 'payments:record']);
        if ($transport === 'rest') {
            return (array) $this->withHeader('Idempotency-Key', $key)
                ->postJson($this->url($workspace, $paymentId, $operation), $body)
                ->assertStatus($expected)
                ->json('data');
        }

        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic manual payment test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = (string) $init->headers->get('Mcp-Session-Id');
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => $operation, 'arguments' => [...$body, 'workspace_id' => $workspace->public_id, 'payment_id' => $paymentId, 'idempotency_key' => $key]],
        ], $headers)->assertOk()->json();
        if ($expected !== 200) {
            $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), (string) json_encode($result));
            if ($expected !== 403) {
                $this->assertSame('failed', AgentMutationAudit::query()->where('workspace_id', $workspace->id)->latest('id')->firstOrFail()->outcome);
            }

            return [];
        }
        $this->assertFalse($result['result']['isError'] ?? false, (string) json_encode($result));

        return (array) $result['result']['structuredContent']['data'];
    }
}
