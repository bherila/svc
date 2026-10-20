<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientInvoicePaymentRefund;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentPaymentPresenter;
use App\Support\Billing\ManualPaymentChange;
use App\Support\Billing\PaymentRefundKind;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `payments.reverse_refund` (#472) over REST and MCP, and its console twin: a
 * refund of a hand-recorded payment that was recorded in error is answered by
 * a reversing entry linked to it, and the refund itself is never changed.
 */
final class AgentRefundReversalTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_a_refund_recorded_in_error_is_reversed_by_a_linked_entry(string $transport): void
    {
        [$user, $workspace, $invoice, $payment] = $this->fixture();
        $refunded = $this->operate($transport, 'payments.refund', $user, $workspace, $payment->public_id, null, $this->refundBody($payment, 10000), 'synthetic-refund');
        $this->assertSame('refunded', $refunded['status'] ?? null);
        $this->assertSame('issued', $invoice->fresh()?->status);
        $refunds = $refunded['refunds'] ?? [];
        $this->assertCount(1, $refunds);
        $refundId = (string) ($refunds[0]['id'] ?? '');
        $this->assertSame(['kind' => 'refund', 'amount' => 10000, 'reason' => 'Synthetic refund', 'reverses_refund_id' => null, 'reversed_by_id' => null],
            array_intersect_key($refunds[0], array_flip(['kind', 'amount', 'reason', 'reverses_refund_id', 'reversed_by_id'])));
        $before = ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->sole()->getAttributes();

        $body = $this->reversalBody($payment->fresh() ?? $payment);
        $reversed = $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $body, 'synthetic-reversal');
        $this->assertSame('succeeded', $reversed['status'] ?? null);
        $this->assertSame(0, $reversed['refunded_amount'] ?? null);
        $this->assertSame('paid', $invoice->fresh()?->status);
        $this->assertSame(0, $invoice->fresh()?->balance_amount);
        $this->assertSame(10000, $invoice->fresh()?->paid_amount);

        // Both entries, linked both ways; the refund itself is untouched.
        $entries = $reversed['refunds'] ?? [];
        $this->assertCount(2, $entries);
        $reversalId = (string) ($entries[1]['id'] ?? '');
        $this->assertSame([$refundId, $reversalId], array_column($entries, 'id'));
        $this->assertSame($reversalId, $entries[0]['reversed_by_id'] ?? null);
        $this->assertSame(['kind' => 'reversal', 'amount' => 10000, 'reason' => 'Synthetic refund entered by mistake', 'reverses_refund_id' => $refundId, 'reversed_by_id' => null],
            array_intersect_key($entries[1], array_flip(['kind', 'amount', 'reason', 'reverses_refund_id', 'reversed_by_id'])));
        $this->assertSame($before, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->where('public_id', $refundId)->sole()->getAttributes());
        $this->assertSame($user->id, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->where('public_id', $reversalId)->sole()->recorded_by_user_id);

        // Shaped exactly like a payments.list row.
        $this->actingAsMcp($user, ['payments:read']);
        $this->assertSame($reversed, $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/payments?invoice_id='.$invoice->public_id)->assertOk()->json('data.0'));

        // The history says what happened, and names both entries.
        $activity = ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_refund_reversed')->sole();
        $this->assertSame(['previous_refunded_amount' => 10000, 'reason' => 'Synthetic refund entered by mistake', 'previous_status' => 'refunded', 'reversal_id' => $reversalId, 'reverses_refund_id' => $refundId],
            array_intersect_key($activity->payload, array_flip(['previous_refunded_amount', 'reason', 'previous_status', 'reversal_id', 'reverses_refund_id'])));
        $this->assertSame($refundId, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_refunded')->sole()->payload['refund_id'] ?? null);
        $this->assertSame(2, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.marked_paid')->count());

        // A retry replays; the same key with another body, or a new key on the
        // version it moved past, is a conflict; a fresh read is refused twice.
        $this->assertSame($reversed, $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $body, 'synthetic-reversal'));
        // An explicit null amount is the omitted one, so it replays too.
        $this->assertSame($reversed, $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, [...$body, 'amount' => null], 'synthetic-reversal'));
        $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, [...$body, 'amount' => 1], 'synthetic-reversal', 409);
        $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $body, 'synthetic-reversal-stale', 409);
        $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-reversal-twice', 422);
        // Nor can the reversal itself be reversed.
        $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $payment->public_id, $reversalId, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-reversal-of-reversal', 422);
        $this->assertSame(2, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.payment_refund_reversed')->count());
        $this->assertSame(0, $payment->fresh()?->refunded_amount);
    }

    public function test_part_of_a_refund_is_reversed_once_and_never_beyond_it(): void
    {
        [$user, $workspace, $invoice, $payment] = $this->fixture();
        $first = $this->operate('rest', 'payments.refund', $user, $workspace, $payment->public_id, null, $this->refundBody($payment, 4000), 'synthetic-refund-1');
        $second = $this->operate('rest', 'payments.refund', $user, $workspace, $payment->public_id, null, $this->refundBody($payment->fresh() ?? $payment, 1000), 'synthetic-refund-2');
        $mistyped = (string) ($first['refunds'][0]['id'] ?? '');
        $this->assertSame([4000, 1000], array_column($second['refunds'] ?? [], 'amount'));
        $this->assertSame(5000, $payment->fresh()?->refunded_amount);

        // 4000 was keyed for 2500: reverse the 1500 that never left.
        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $mistyped, [...$this->reversalBody($payment->fresh() ?? $payment), 'amount' => 4001], 'synthetic-too-much', 422);
        $result = $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $mistyped, [...$this->reversalBody($payment->fresh() ?? $payment), 'amount' => 1500], 'synthetic-partial');
        $this->assertSame(3500, $result['refunded_amount'] ?? null);
        $this->assertSame('succeeded', $result['status'] ?? null);
        $this->assertSame('partially_paid', $invoice->fresh()?->status);
        $this->assertSame(3500, $invoice->fresh()?->balance_amount);
        $this->assertSame(1500, $result['refunds'][2]['amount'] ?? null);

        // Reversed once: the remaining 2500 of it is a refund that happened.
        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $mistyped, [...$this->reversalBody($payment->fresh() ?? $payment), 'amount' => 100], 'synthetic-again', 422);
        // The other refund is still reversible, and the invoice is paid once more.
        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, (string) ($second['refunds'][1]['id'] ?? ''), $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-other');
        $this->assertSame(2500, $payment->fresh()?->refunded_amount);
        $this->assertSame(2500, $invoice->fresh()?->balance_amount);
        $this->assertSame((int) $payment->fresh()?->refunded_amount, $this->ledgerTotal($payment));
    }

    /**
     * A history read from a snapshot older than the payment lock is a conflict,
     * not a decision on entries that may be missing. MariaDB's REPEATABLE READ
     * produces the stale snapshot; here the payment's revision moves between the
     * locked read and the plain one, which is the difference the check looks for.
     */
    public function test_a_payment_that_moved_while_the_reversal_waited_is_a_conflict(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $refundId = $this->refund($user, $workspace, $payment, 100);
        $body = $this->reversalBody($payment->fresh() ?? $payment);
        $moved = false;
        DB::listen(function (QueryExecuted $event) use ($payment, &$moved): void {
            $sql = str_replace(['"', '`'], '', strtolower($event->sql));
            if (! $moved && str_starts_with($sql, 'select * from client_invoice_payments') && ! str_contains($sql, 'public_id')) {
                $moved = true;
                DB::table('client_invoice_payments')->where('workspace_id', $payment->workspace_id)->where('id', $payment->id)->increment('lock_version');
            }
        });

        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $body, 'synthetic-moved', 409);
        $this->assertTrue($moved);
        $this->assertSame(100, $payment->fresh()?->refunded_amount);
        $this->assertSame(1, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_a_processor_refund_follows_its_processor(): void
    {
        [$user, $workspace, $invoice] = $this->fixture(paid: false);
        $service = app(InvoiceLifecycleService::class);
        $stripe = $service->applyPayment($invoice, ['amount' => 10000, 'currency' => 'USD', 'method' => 'stripe',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_'.Str::random(8)], $workspace);
        $stripe = $service->setRefundedAmount($stripe, 10000, $workspace);
        $this->assertSame(0, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->count());

        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $stripe->public_id, (string) Str::uuid(), $this->reversalBody($stripe), 'synthetic-stripe', 422);
        $this->assertSame(10000, $stripe->fresh()?->refunded_amount);
        $this->assertSame('refunded', $stripe->fresh()?->status);
    }

    public function test_money_that_counts_again_cannot_overpay_or_land_on_a_void_invoice(): void
    {
        [$user, $workspace, $invoice, $payment] = $this->fixture();
        $refundId = $this->refund($user, $workspace, $payment, 10000);
        $service = app(InvoiceLifecycleService::class);
        $replacement = $service->applyPayment($invoice->fresh() ?? $invoice, ['amount' => 10000, 'currency' => 'USD', 'method' => 'wire', 'received_on' => now()->toDateString()], $workspace);

        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-overpay', 422);
        $this->assertSame(10000, $payment->fresh()?->refunded_amount);
        $this->assertSame(1, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->count());

        // Withdrawn and the invoice voided: the money belongs to no live invoice.
        $service->changeManualPayment($replacement, ManualPaymentChange::status('canceled', 'Synthetic duplicate'), null, $workspace);
        $service->void($invoice->fresh() ?? $invoice, $workspace, 'Synthetic void');
        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-void', 422);
        $this->assertSame(10000, $payment->fresh()?->refunded_amount);
        $this->assertSame('void', $invoice->fresh()?->status);
    }

    /** A reversal only adds capacity, so a reconciled payment is not held back. */
    public function test_a_reconciled_payment_keeps_its_allocations(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $refundId = $this->refund($user, $workspace, $payment, 3000);
        $allocation = PaymentReconciliation::query()->create(['workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id,
            'external_system_slug' => 'synthetic-ledger', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 7000,
            'currency' => 'USD', 'reconciled_on' => now()->toDateString(), 'is_active' => true]);

        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-reconciled');
        $this->assertSame(0, $payment->fresh()?->refunded_amount);
        $this->assertSame(7000, $allocation->fresh()?->allocated_amount);
        $this->assertTrue($allocation->fresh()?->is_active);
        $this->assertNull($allocation->fresh()?->review_reason);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusedBodies(): iterable
    {
        yield 'no confirmation' => [['confirm' => false]];
        yield 'no reason' => [['reason' => null]];
        yield 'blank reason' => [['reason' => '  ']];
        yield 'reason too long' => [['reason' => str_repeat('r', 501)]];
        yield 'no version' => [['expected_version' => null]];
        yield 'nothing reversed' => [['amount' => 0]];
        yield 'an amount as text' => [['amount' => '100']];
        yield 'another field' => [['status' => 'succeeded']];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('refusedBodies')]
    public function test_malformed_bodies_are_refused_before_anything_is_written(array $change): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $refundId = $this->refund($user, $workspace, $payment, 100);
        DB::table('agent_mutation_receipts')->delete();
        $body = [...$this->reversalBody($payment->fresh() ?? $payment), ...$change];
        foreach (['reason', 'expected_version'] as $optional) {
            if (array_key_exists($optional, $change) && $change[$optional] === null) {
                unset($body[$optional]);
            }
        }

        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $body, 'synthetic-malformed', 422);
        $this->assertSame(100, $payment->fresh()?->refunded_amount);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_both_scopes_both_flags_and_the_manager_role_are_required(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $refundId = $this->refund($user, $workspace, $payment, 100);
        $url = $this->url($workspace, $payment->public_id, $refundId);
        foreach ([['payments:read'], ['payments:record']] as $scopes) {
            $this->actingAsMcp($user, $scopes);
            $this->withHeader('Idempotency-Key', 'synthetic-scope')->postJson($url, $this->reversalBody($payment->fresh() ?? $payment))->assertForbidden();
        }
        config(['agent_api.payment_writes_enabled' => false]);
        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-flag', 404);
        config(['agent_api.payment_writes_enabled' => true]);
        $workspace->memberships()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->update(['role' => 'member']);
        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-member', 403);
        $this->assertSame(100, $payment->fresh()?->refunded_amount);
    }

    #[DataProvider('transports')]
    public function test_another_workspaces_payment_and_refund_are_not_reachable(string $transport): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        [$other, $otherWorkspace, , $foreign] = $this->fixture();
        $foreignRefund = $this->refund($other, $otherWorkspace, $foreign, 500);
        $ownRefund = $this->refund($user, $workspace, $payment, 500);

        // Another workspace's payment is not found; its refund is not a refund
        // of this workspace's payment.
        $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $foreign->public_id, $foreignRefund, $this->reversalBody($foreign->fresh() ?? $foreign), 'synthetic-foreign', 404);
        $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $payment->public_id, $foreignRefund, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-foreign-refund', 422);
        // Nor is a refund of another payment in the same workspace.
        $service = app(InvoiceLifecycleService::class);
        $company = ClientCompany::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $second = $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'SYNTHETIC-'.Str::upper(Str::random(8))], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 5000],
        ]), $workspace);
        $sibling = $service->applyPayment($second, ['amount' => 5000, 'currency' => 'USD', 'method' => 'check', 'received_on' => now()->toDateString()], $workspace);
        $this->operate($transport, 'payments.reverse_refund', $user, $workspace, $sibling->public_id, $ownRefund, $this->reversalBody($sibling), 'synthetic-sibling', 422);

        $this->assertSame(500, $foreign->fresh()?->refunded_amount);
        $this->assertSame(500, $payment->fresh()?->refunded_amount);
        $this->assertSame(0, $sibling->fresh()?->refunded_amount);
        $this->assertSame(0, ClientInvoicePaymentRefund::query()->where('kind', PaymentRefundKind::Reversal->value)->count());
    }

    public function test_the_whole_request_scopes_every_tenant_read_and_write(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $refundId = $this->refund($user, $workspace, $payment, 100);
        $body = $this->reversalBody($payment->fresh() ?? $payment);
        $this->actingAsMcp($user, ['payments:record', 'payments:read']);
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $sql = str_replace(['"', '`'], '', strtolower($event->sql));
            if (preg_match('/^(?:select .*? from|update|insert into|delete from) ([a-z_]+)/', $sql, $match) === 1) {
                $queries[] = [$match[1], $sql];
            }
        });

        $this->withHeader('Idempotency-Key', 'synthetic-query')
            ->postJson($this->url($workspace, $payment->public_id, $refundId), $body)
            ->assertOk();

        $this->assertContains('client_invoice_payment_refunds', array_column($queries, 0));
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

    public function test_the_console_reverses_through_the_same_rule(): void
    {
        [$user, $workspace, $invoice, $payment] = $this->fixture();
        [, $other] = $this->fixture();
        $refundId = $this->refund($user, $workspace, $payment, 2500);
        $options = ['payment' => $payment->public_id, '--workspace' => $workspace->public_id, '--refund' => $refundId, '--reason' => 'Synthetic refund keyed twice'];

        $this->artisan('svc:billing:reverse-refund', ['--apply' => true, ...$options, '--workspace' => $other->public_id])->assertFailed();
        $this->artisan('svc:billing:reverse-refund', ['--apply' => true, ...$options, '--amount' => '1.5'])->assertExitCode(2);
        $this->artisan('svc:billing:reverse-refund', ['--apply' => true, ...$options, '--reason' => null])->assertExitCode(2);
        $this->artisan('svc:billing:reverse-refund', ['--apply' => true, ...$options, '--refund' => 'nope'])->assertExitCode(2);
        $this->artisan('svc:billing:reverse-refund', ['--apply' => true, ...$options, '--refund' => (string) Str::uuid()])
            ->expectsOutputToContain('Refunds of this payment not yet reversed: '.$refundId.'.')->assertFailed();
        $this->artisan('svc:billing:reverse-refund', ['--apply' => true, ...$options, '--expected-version' => str_repeat('a', 64)])->assertFailed();

        $this->artisan('svc:billing:reverse-refund', [...$options, '--amount' => '1000', '--dry-run' => true, '--format' => 'json'])
            ->expectsOutputToContain('"dry_run":true,"changed":true,"status":{"old":"succeeded","new":"succeeded"},"refunded_amount":{"old":2500,"new":1500}')->assertSuccessful();
        $this->assertSame(2500, $payment->fresh()?->refunded_amount);
        $this->assertSame(1, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->count());

        $this->artisan('svc:billing:reverse-refund', ['--apply' => true, ...$options, '--expected-version' => AgentApiVersion::for($payment->fresh() ?? $payment), '--format' => 'json'])
            ->expectsOutputToContain('"refunded_amount":{"old":2500,"new":0}')->assertSuccessful();
        $this->assertSame([2500, PaymentRefundKind::Reversal], [ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->latest('id')->firstOrFail()->amount, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->latest('id')->firstOrFail()->kind]);
        $this->assertSame(0, $payment->fresh()?->refunded_amount);
        $this->assertSame('paid', $invoice->fresh()?->status);
        $this->artisan('svc:billing:reverse-refund', ['--apply' => true] + ($options))->expectsOutputToContain('This payment has no refund left to reverse.')->assertFailed();
    }

    public function test_a_refund_row_is_immutable_and_reversed_at_most_once_by_the_schema(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $this->refund($user, $workspace, $payment, 100);
        $refund = ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->sole();

        try {
            $refund->forceFill(['amount' => 1])->save();
            $this->fail('A refund row must not be updated.');
        } catch (LogicException) {
        }
        try {
            $refund->delete();
            $this->fail('A refund row must not be deleted.');
        } catch (LogicException) {
        }
        $this->assertSame(100, $refund->fresh()?->amount);

        $row = ['workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id, 'kind' => 'reversal', 'amount' => 100,
            'reason' => 'Synthetic', 'reverses_refund_id' => $refund->id, 'created_at' => now()];
        DB::table('client_invoice_payment_refunds')->insert([...$row, 'public_id' => (string) Str::uuid()]);
        $this->expectException(QueryException::class);
        DB::table('client_invoice_payment_refunds')->insert([...$row, 'public_id' => (string) Str::uuid()]);
    }

    /** A refund and its reversal leave together when their payment does; the self-reference does not block it. */
    public function test_a_reversed_refund_cascades_with_its_payment(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $refundId = $this->refund($user, $workspace, $payment, 100);
        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-cascade');
        $this->assertSame(2, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->count());

        DB::table('client_invoice_payments')->where('workspace_id', $workspace->id)->where('id', $payment->id)->delete();
        $this->assertSame(0, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->count());
    }

    /** The bound is counted on the locked payment's own history, so a full one refuses both entry kinds. */
    public function test_a_full_history_refuses_another_refund_or_reversal(): void
    {
        [$user, $workspace, , $payment] = $this->fixture();
        $refundId = $this->refund($user, $workspace, $payment, 100);
        $rows = [];
        for ($i = 1; $i < ManualPaymentChange::MAX_REFUND_ENTRIES; $i++) {
            $rows[] = ['public_id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id,
                'kind' => 'refund', 'amount' => 1, 'reason' => 'Synthetic filler', 'created_at' => now()];
        }
        DB::table('client_invoice_payment_refunds')->insert($rows);

        $this->operate('rest', 'payments.refund', $user, $workspace, $payment->public_id, null, $this->refundBody($payment->fresh() ?? $payment, 1), 'synthetic-full-refund', 422);
        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, $refundId, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-full-reversal', 422);
        $this->assertSame(ManualPaymentChange::MAX_REFUND_ENTRIES, ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(100, $payment->fresh()?->refunded_amount);
    }

    /** A refund's reason is a manager's to read, as the note is. */
    public function test_a_portal_reader_sees_the_entries_without_their_reasons(): void
    {
        [$user, $workspace, $invoice, $payment] = $this->fixture();
        $this->refund($user, $workspace, $payment, 100);
        $loaded = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->whereKey($payment->id)->with(AgentPaymentPresenter::with($workspace))->sole();
        $presenter = app(AgentPaymentPresenter::class);

        $this->assertSame('Synthetic refund', $presenter->present($loaded, $invoice, true)['refunds'][0]['reason']);
        $this->assertNull($presenter->present($loaded, $invoice, false)['refunds'][0]['reason']);
        $this->assertSame(100, $presenter->present($loaded, $invoice, false)['refunds'][0]['amount']);

        $this->expectException(LogicException::class);
        $presenter->present(ClientInvoicePayment::query()->whereKey($payment->id)->sole(), $invoice, true);
    }

    /** A payment refunded by hand before refunds were entries gets one for its total, so it can be reversed. */
    public function test_the_migration_gives_an_earlier_manual_refund_one_entry(): void
    {
        [$user, $workspace, $invoice, $payment] = $this->fixture();
        $service = app(InvoiceLifecycleService::class);
        $stripe = $service->applyPayment($this->fixture(paid: false)[2], ['amount' => 10000, 'currency' => 'USD', 'method' => 'stripe',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_'.Str::random(8)]);
        $service->setRefundedAmount($stripe, 2000);
        DB::table('client_invoice_payments')->where('id', $payment->id)->update(['refunded_amount' => 3000]);
        // Refunded with an activity, then corrected later: the entry is dated
        // by the refund, not by the correction that moved updated_at.
        [$otherUser, $otherWorkspace, , $refunded] = $this->fixture();
        $this->refund($otherUser, $otherWorkspace, $refunded, 500);
        DB::table('client_company_activity')->where('workspace_id', $otherWorkspace->id)->where('action', 'invoice.payment_refunded')->update(['created_at' => '2026-03-04 05:06:07']);
        // Activity times are the workspace's wall clock; the entry is UTC.
        DB::table('workspaces')->where('id', $otherWorkspace->id)->update(['timezone' => 'America/Los_Angeles']);
        DB::table('client_invoice_payments')->where('id', $refunded->id)->update(['updated_at' => '2026-09-30 00:00:00']);
        $migration = require database_path('migrations/2026_10_19_140000_create_client_invoice_payment_refunds.php');
        Schema::drop('client_invoice_payment_refunds');
        $this->travelTo('2026-10-19 12:00:00');
        $migration->up();

        $this->assertSame('2026-03-04 13:06:07', ClientInvoicePaymentRefund::query()->where('workspace_id', $otherWorkspace->id)->sole()->created_at->format('Y-m-d H:i:s'));
        $entry = ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(2, ClientInvoicePaymentRefund::query()->count());
        $this->assertSame([$workspace->id, $payment->id, PaymentRefundKind::Refund, 3000, null], [$entry->workspace_id, $entry->client_invoice_payment_id, $entry->kind, $entry->amount, $entry->reverses_refund_id]);
        $this->assertStringContainsString('before refunds were recorded one by one', $entry->reason);
        // No refund activity to date it by: dated when it was backfilled.
        $this->assertSame('2026-10-19 12:00:00', $entry->created_at->format('Y-m-d H:i:s'));
        $this->travelBack();

        DB::table('client_invoices')->where('id', $invoice->id)->update(['paid_amount' => 7000, 'balance_amount' => 3000, 'status' => 'partially_paid']);
        $this->operate('rest', 'payments.reverse_refund', $user, $workspace, $payment->public_id, (string) $entry->public_id, $this->reversalBody($payment->fresh() ?? $payment), 'synthetic-backfilled');
        $this->assertSame(0, $payment->fresh()?->refunded_amount);
        $this->assertSame('paid', $invoice->fresh()?->status);
    }

    private function ledgerTotal(ClientInvoicePayment $payment): int
    {
        $entries = ClientInvoicePaymentRefund::query()->where('workspace_id', $payment->workspace_id)->where('client_invoice_payment_id', $payment->id)->get();

        return (int) $entries->sum(fn (ClientInvoicePaymentRefund $entry): int => match ($entry->kind) {
            PaymentRefundKind::Refund => $entry->amount,
            PaymentRefundKind::Reversal => -$entry->amount,
        });
    }

    private function refund(User $user, Workspace $workspace, ClientInvoicePayment $payment, int $amount): string
    {
        $result = $this->operate('rest', 'payments.refund', $user, $workspace, $payment->public_id, null, $this->refundBody($payment->fresh() ?? $payment, $amount), 'synthetic-refund-'.Str::random(8));
        $refunds = $result['refunds'] ?? [];

        return (string) (end($refunds)['id'] ?? '');
    }

    /** @return array<string, mixed> */
    private function refundBody(ClientInvoicePayment $payment, int $amount): array
    {
        return ['confirm' => true, 'expected_version' => AgentApiVersion::for($payment), 'reason' => 'Synthetic refund', 'amount' => $amount];
    }

    /** @return array<string, mixed> */
    private function reversalBody(ClientInvoicePayment $payment): array
    {
        return ['confirm' => true, 'expected_version' => AgentApiVersion::for($payment), 'reason' => 'Synthetic refund entered by mistake'];
    }

    /** @return array{User, Workspace, ClientInvoice, ClientInvoicePayment} */
    private function fixture(bool $paid = true): array
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        $user = User::factory()->create(['email' => 'synthetic-'.Str::lower(Str::random(8)).'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic refund reversals', 'slug' => 'synthetic-'.Str::lower(Str::random(10))]);
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

    private function url(Workspace $workspace, string $paymentId, ?string $refundId): string
    {
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/payments/'.$paymentId.'/refunds';

        return $refundId === null ? $base : $base.'/'.$refundId.'/reversal';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function operate(string $transport, string $operation, User $user, Workspace $workspace, string $paymentId, ?string $refundId, array $body, string $key, int $expected = 200): array
    {
        $this->actingAsMcp($user, ['mcp:use', 'payments:read', 'payments:record']);
        if ($transport === 'rest') {
            return (array) $this->withHeader('Idempotency-Key', $key)
                ->postJson($this->url($workspace, $paymentId, $refundId), $body)
                ->assertStatus($expected)
                ->json('data');
        }

        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic refund reversal test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = (string) $init->headers->get('Mcp-Session-Id');
        $arguments = [...$body, 'workspace_id' => $workspace->public_id, 'payment_id' => $paymentId, 'idempotency_key' => $key, ...($refundId === null ? [] : ['refund_id' => $refundId])];
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => $operation, 'arguments' => $arguments],
        ], $headers)->assertOk()->json();
        if ($expected !== 200) {
            $this->assertTrue($result['result']['isError'] ?? false, (string) json_encode($result));
            if ($expected !== 403) {
                $this->assertSame('failed', AgentMutationAudit::query()->where('workspace_id', $workspace->id)->latest('id')->firstOrFail()->outcome);
            }

            return [];
        }
        $this->assertFalse($result['result']['isError'] ?? false, (string) json_encode($result));

        return (array) $result['result']['structuredContent']['data'];
    }
}
