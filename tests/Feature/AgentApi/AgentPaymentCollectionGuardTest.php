<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\PaymentCollectionGuard;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockAcquisition;
use App\Support\Concurrency\LockMode;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * `payments.record` refuses a payment while another on the invoice is still in
 * flight, or while Square collects the invoice (G6), asked under the invoice
 * lock; `invoices.get` reports both facts so a caller can see why first.
 */
final class AgentPaymentCollectionGuardTest extends TestCase
{
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'payments:record', 'payments:read', 'billing:read'];

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_a_pending_payment_on_the_invoice_refuses_another_until_it_settles(string $transport): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $pending = $this->record($transport, $owner, $workspace, [...$this->payload($invoice, 4000), 'status' => 'pending'], 'synthetic-cheque');
        $this->assertSame('pending', $pending[0]['status']);

        $refused = $this->record($transport, $owner, $workspace, $this->payload($invoice->refresh(), 6000), 'synthetic-transfer', 422);
        $this->assertStringContainsString('has 1 payment still in flight', $refused);
        $this->assertSame(1, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $audit = AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'payments.record')->latest('id')->firstOrFail();
        $this->assertSame(['failed', 'domain'], [$audit->outcome, $audit->error_category]);

        // Once the cheque clears, the next payment is recorded.
        $this->actingAsMcp($owner, self::SCOPES);
        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole();
        $version = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/payments?invoice_id='.$invoice->public_id)->assertOk()->json('data.0.version');
        $this->withHeader('Idempotency-Key', 'synthetic-clear')->postJson('/api/v1/workspaces/'.$workspace->public_id.'/payments/'.$payment->public_id.'/status', [
            'status' => 'succeeded', 'expected_version' => $version, 'reason' => 'Synthetic cheque cleared', 'confirm' => true,
        ])->assertOk();
        $this->record($transport, $owner, $workspace, $this->payload($invoice->refresh(), 6000), 'synthetic-transfer-after');
        $this->assertSame(2, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame('paid', $invoice->refresh()->status);
    }

    public function test_a_payment_with_an_unreadable_status_counts_as_in_flight(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'cash'], $workspace);
        DB::table('client_invoice_payments')->where('workspace_id', $workspace->id)->update(['status' => 'synthetic-unknown']);

        $this->assertSame(1, app(PaymentCollectionGuard::class)->summary($invoice->refresh())['pending_payments']);
        $this->record('rest', $owner, $workspace, $this->payload($invoice, 1000), 'synthetic-unreadable', 422);
    }

    /**
     * A declined Stripe intent returns to requires_payment_method and its
     * client secret can be confirmed again, so a Stripe payment stored as
     * failed is still in flight; a failed payment recorded by hand is final.
     */
    public function test_a_failed_stripe_payment_is_still_in_flight_but_a_failed_manual_one_is_not(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $lifecycle = app(InvoiceLifecycleService::class);
        $lifecycle->applyPayment($invoice, ['amount' => 10000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_declined'], $workspace);
        DB::table('client_invoice_payments')->where('workspace_id', $workspace->id)->update(['status' => 'failed']);

        $this->assertSame(1, app(PaymentCollectionGuard::class)->summary($invoice->refresh())['pending_payments']);
        $refused = $this->record('rest', $owner, $workspace, $this->payload($invoice, 10000), 'synthetic-after-decline', 422);
        $this->assertStringContainsString('has 1 payment still in flight', $refused);

        // Once canceled in Stripe it is final, and so is a failed manual payment.
        DB::table('client_invoice_payments')->where('workspace_id', $workspace->id)->update(['status' => 'canceled']);
        $lifecycle->applyPayment($invoice->refresh(), ['amount' => 1000, 'currency' => 'USD', 'method' => 'cheque', 'status' => 'pending'], $workspace);
        DB::table('client_invoice_payments')->where('workspace_id', $workspace->id)->where('method', 'cheque')->update(['status' => 'failed']);
        $this->assertSame(0, app(PaymentCollectionGuard::class)->summary($invoice->refresh())['pending_payments']);
        $this->record('rest', $owner, $workspace, $this->payload($invoice, 10000), 'synthetic-after-cancel');
    }

    /**
     * Classified in PHP, not by SQL: MariaDB's case-insensitive collation
     * would read `SUCCEEDED` as `succeeded`. Runs on the MariaDB lane too.
     */
    public function test_a_status_in_another_case_counts_as_in_flight(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'cash'], $workspace);
        DB::table('client_invoice_payments')->where('workspace_id', $workspace->id)->update(['status' => 'SUCCEEDED']);

        $this->assertSame(1, app(PaymentCollectionGuard::class)->summary($invoice->refresh())['pending_payments']);
        $this->record('rest', $owner, $workspace, $this->payload($invoice, 1000), 'synthetic-uppercase', 422);
        $this->assertSame(1, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
    }

    /**
     * Every new payment advances the invoice's revision, even a pending one
     * that moves no amount, so a caller - or a transaction snapshot - that
     * predates it is refused as stale instead of counting without it.
     */
    public function test_a_pending_payment_recorded_elsewhere_advances_the_invoice_revision(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $before = AgentApiVersion::for($invoice);
        app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'cheque', 'status' => 'pending'], $workspace);
        $this->assertNotSame($before, AgentApiVersion::for($invoice->refresh()));
        $this->assertSame('issued', $invoice->status, 'A pending payment moves no amount');

        $this->record('rest', $owner, $workspace, [...$this->payload($invoice, 1000), 'expected_version' => $before], 'synthetic-stale-reader', 409);
        $this->assertSame(1, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
    }

    /**
     * The payment rows are read plainly - a share lock after the invoice's
     * would invert the lock order - so the guard first checks that the
     * transaction's view of the invoice is the locked one. A locked row newer
     * than the snapshot is a 409 to retry.
     */
    public function test_a_snapshot_older_than_the_locked_invoice_is_a_conflict(): void
    {
        [, , $invoice] = $this->fixture();
        $locked = $invoice->refresh();
        $locked->setAttribute('lock_version', (int) $locked->lock_version + 1);

        try {
            $guard = app(PaymentCollectionGuard::class);
            $guard->assertMayRecord($locked, $guard->holdAgreement($locked));
            $this->fail('A stale snapshot should be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $guard = app(PaymentCollectionGuard::class);
        $guard->assertMayRecord($invoice->refresh(), $guard->holdAgreement($invoice));
    }

    /**
     * The agreement's billed-in-Square marker moves no invoice, so it is read
     * under a share lock on the agreement, taken before the invoice's lock in
     * the registry's order: current, and held until this payment commits.
     */
    public function test_the_agreement_marker_is_share_locked_before_the_invoice(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        LockOrderRecorder::start();
        try {
            $this->record('rest', $owner, $workspace, $this->payload($invoice, 1000), 'synthetic-lock-order');
            $sequence = collect(LockOrderRecorder::acquisitions())
                ->first(static fn (array $locks): bool => in_array(LockResource::ClientInvoice, array_map(static fn (LockAcquisition $lock): LockResource => $lock->resource, $locks), true));
            $this->assertIsArray($sequence);
            $resources = array_map(static fn (LockAcquisition $lock): LockResource => $lock->resource, $sequence);
            $agreement = array_search(LockResource::ClientAgreement, $resources, true);
            $invoiceLock = array_search(LockResource::ClientInvoice, $resources, true);
            $this->assertIsInt($agreement, 'The agreement is locked');
            $this->assertIsInt($invoiceLock);
            $this->assertLessThan($invoiceLock, $agreement, 'The agreement is locked before the invoice');
            $this->assertSame(LockMode::Share, $sequence[$agreement]->mode);
        } finally {
            LockOrderRecorder::stop();
        }
    }

    /**
     * The guard decides from the marker as the share-locked read saw it, not
     * from a later snapshot read, and refuses an invoice whose agreement
     * changed after that read.
     */
    public function test_the_held_agreement_marker_decides(): void
    {
        [, , $invoice] = $this->fixture();
        $guard = app(PaymentCollectionGuard::class);
        $held = $guard->holdAgreement($invoice);
        $this->assertSame(['agreement_id' => (int) $invoice->client_agreement_id, 'series_key' => null], $held);

        try {
            $guard->assertMayRecord($invoice, [...$held, 'series_key' => '000001']);
            $this->fail('A marker the locked read saw should refuse.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('is collected through Square', $exception->getMessage());
        }
        try {
            $guard->assertMayRecord($invoice, ['agreement_id' => null, 'series_key' => null]);
            $this->fail('An invoice whose agreement moved should be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $guard->assertMayRecord($invoice, $held);
    }

    /** @return iterable<string, array{string, string}> */
    public static function squareReasons(): iterable
    {
        foreach (['rest', 'mcp'] as $transport) {
            foreach (['linked', 'agreement'] as $reason) {
                yield "{$transport}, {$reason}" => [$transport, $reason];
            }
        }
    }

    #[DataProvider('squareReasons')]
    public function test_an_invoice_square_collects_is_refused(string $transport, string $reason): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $this->makeSquareCollected($reason, $owner, $workspace, $invoice);

        $refused = $this->record($transport, $owner, $workspace, $this->payload($invoice->refresh(), 10000), 'synthetic-square', 422);
        $this->assertStringContainsString('is collected through Square', $refused);
        $this->assertSame(0, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    #[DataProvider('squareReasons')]
    public function test_only_the_trusted_operator_session_can_record_square_bookkeeping(string $transport, string $reason): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $this->makeSquareCollected($reason, $owner, $workspace, $invoice);
        $body = $this->payload($invoice->refresh(), 5000);
        $refused = $this->record($transport, $owner, $workspace, $body, 'synthetic-agent-square-refusal', 422);
        $this->assertStringContainsString('is collected through Square', $refused);
        $this->assertSame(0, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());

        config(['agent_api.writes_enabled' => false, 'agent_api.payment_writes_enabled' => false]);
        $this->operatorSession($owner);
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/payments';
        $id = $this->postJson($url, $body, ['Idempotency-Key' => 'synthetic-operator-square'])->assertCreated()->json('data.0.id');
        $this->postJson($url, $body, ['Idempotency-Key' => 'synthetic-operator-square'])->assertCreated()->assertJsonPath('data.0.id', $id);
        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertNull($payment->provider);
        $this->assertNull($payment->provider_payment_identifier);
        $this->assertSame(5000, $payment->amount);
        $this->assertSame(['partially_paid', 5000], [$invoice->fresh()->status, $invoice->fresh()->balance_amount]);
        $this->assertSame(PaymentCollectionGuard::OWNER_SQUARE, app(PaymentCollectionGuard::class)->summary($invoice->fresh())['collection_owner']);
    }

    public function test_operator_square_bookkeeping_retains_in_flight_and_version_guards(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $this->makeSquareCollected('linked', $owner, $workspace, $invoice);
        $lifecycle = app(InvoiceLifecycleService::class);
        $pending = $lifecycle->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'cheque', 'status' => 'pending'], $workspace);
        $body = $this->payload($invoice->fresh(), 1000);
        $this->operatorSession($owner);
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/payments';
        $this->postJson($url, $body, ['Idempotency-Key' => 'synthetic-operator-pending'])->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'still in flight'));
        $this->assertSame(1, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $lifecycle->setPaymentStatus($pending, 'succeeded', $workspace);
        $this->postJson($url, $body, ['Idempotency-Key' => 'synthetic-operator-stale'])->assertConflict();
        $this->postJson($url, $this->payload($invoice->fresh(), 1000), ['Idempotency-Key' => 'synthetic-operator-settled'])->assertCreated();
        $this->assertSame([2000, 8000], [$invoice->fresh()->paid_amount, $invoice->fresh()->balance_amount]);
    }

    public function test_operator_bookkeeping_still_requires_manager_and_tenant_ownership(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        [, $foreign, $foreignInvoice] = $this->fixture();
        $this->makeSquareCollected('linked', $owner, $workspace, $invoice);
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/payments';
        $this->operatorSession($member);
        $this->postJson($url, $this->payload($invoice->fresh(), 1000), ['Idempotency-Key' => 'synthetic-member-square'])->assertForbidden();
        $this->operatorSession($owner);
        $this->postJson($url, $this->payload($foreignInvoice, 1000), ['Idempotency-Key' => 'synthetic-foreign-invoice'])->assertNotFound();
        $this->postJson('/api/v1/workspaces/'.$foreign->public_id.'/payments', $this->payload($foreignInvoice, 1000), ['Idempotency-Key' => 'synthetic-foreign-workspace'])->assertNotFound();
        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    private function operatorSession(User $user): void
    {
        $this->actingAs($user, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str()->random(40));
    }

    /**
     * The caller read the invoice before the other payment arrived: the
     * version it holds is still current (a payment row does not move the
     * invoice's version), so only the question asked under the lock stops it.
     *
     * @return iterable<string, array{string}>
     */
    public static function arrivals(): iterable
    {
        yield 'a pending payment' => ['pending'];
        yield 'a Square link' => ['square'];
    }

    #[DataProvider('arrivals')]
    public function test_the_guard_is_asked_of_the_locked_invoice(string $arrival): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $payload = $this->payload($invoice, 10000);
        $arrived = false;
        DB::listen(function (QueryExecuted $event) use ($owner, $workspace, $invoice, $arrival, &$arrived): void {
            $sql = str_replace(['"', '`'], '', strtolower($event->sql));
            // The action's unlocked lookup by public id, before the lock.
            if ($arrived || ! str_starts_with($sql, 'select') || ! str_contains($sql, 'from client_invoices') || ! str_contains($sql, 'public_id =')) {
                return;
            }
            $arrived = true;
            if ($arrival === 'pending') {
                DB::table('client_invoice_payments')->insert([
                    'public_id' => (string) str()->uuid(), 'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id,
                    'status' => 'pending', 'amount' => 10000, 'refunded_amount' => 0, 'currency' => 'USD', 'received_on' => now()->toDateString(),
                    'method' => 'stripe', 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('invoice_square_links')->insert([
                    'public_id' => (string) str()->uuid(), 'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id,
                    'square_invoice_id' => 'inv:0-fixture1', 'square_invoice_number' => '000001-R-0001', 'series_key' => '000001',
                    'currency' => 'USD', 'match_method' => SquareMatchMethod::Manual->value, 'reasons' => '[]',
                    'active_invoice_slot' => $invoice->id, 'active_square_invoice_slot' => 'inv:0-fixture1',
                    'linked_by_user_id' => $owner->id, 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->record('rest', $owner, $workspace, $payload, 'synthetic-raced', 422);
        $this->assertTrue($arrived);
        $this->assertSame(AgentApiVersion::for($invoice), AgentApiVersion::for($invoice->refresh()), 'The arrival leaves the invoice version alone');
        // The arrival was written inside the refused request's transaction, so
        // it is rolled back with it: nothing at all is left behind.
        $this->assertSame(0, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    /**
     * A retry of a payment already recorded answers with that payment, even
     * though the payment it recorded - or Square collecting the invoice since -
     * would refuse a new one.
     */
    #[DataProvider('transports')]
    public function test_a_completed_retry_replays_past_the_guard(string $transport): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $pending = [...$this->payload($invoice, 4000), 'status' => 'pending'];
        $first = $this->record($transport, $owner, $workspace, $pending, 'synthetic-pending-retry');
        $this->assertSame($first, $this->record($transport, $owner, $workspace, $pending, 'synthetic-pending-retry'));

        $settled = $this->payload($invoice->refresh(), 6000);
        ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->update(['status' => 'canceled']);
        $second = $this->record($transport, $owner, $workspace, $settled, 'synthetic-settled-retry');
        $this->makeSquareCollected('linked', $owner, $workspace, $invoice->refresh());
        $this->assertSame($second[0]['id'], $this->record($transport, $owner, $workspace, $settled, 'synthetic-settled-retry')[0]['id']);

        $this->assertSame(2, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(2, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'payments.record')->where('outcome', 'replay')->count());
    }

    public function test_invoices_get_reports_pending_payments_and_the_collection_owner_to_managers_only(): void
    {
        [$owner, $workspace, $invoice, $company] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/invoices/'.$invoice->public_id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.pending_payments', 0)->assertJsonPath('data.collection_owner', 'svc');

        $lifecycle = app(InvoiceLifecycleService::class);
        $lifecycle->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'cheque', 'status' => 'pending'], $workspace);
        $lifecycle->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'cheque', 'status' => 'pending'], $workspace);
        $lifecycle->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'cash'], $workspace);
        $this->getJson($url)->assertOk()->assertJsonPath('data.pending_payments', 2)->assertJsonPath('data.collection_owner', 'svc');

        $this->makeSquareCollected('linked', $owner, $workspace, $invoice->refresh());
        $read = $this->getJson($url)->assertOk()->assertJsonPath('data.collection_owner', 'square');
        $this->assertTrue($read->json('data.square.square_collects'));

        $portal = User::factory()->create(['email' => 'synthetic-collection-portal@example.test']);
        $company->portalUsers()->attach($portal->id, ['workspace_id' => $workspace->id]);
        $invoice->update(['is_visible_to_client' => true]);
        $this->actingAsMcp($portal, ['billing:read']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.pending_payments', null)->assertJsonPath('data.collection_owner', null);
    }

    /** @return array{User, Workspace, ClientInvoice, ClientCompany} */
    private function fixture(): array
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        $owner = User::factory()->create(['email' => 'synthetic-'.str()->uuid().'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic collection guard', 'slug' => 'synthetic-'.str()->uuid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Monthly', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 5000]);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'SYNTHETIC-'.str()->uuid()], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 10000],
        ]), $workspace);
        $invoice->forceFill(['client_agreement_id' => $agreement->id])->save();

        return [$owner, $workspace, $invoice->refresh(), $company];
    }

    private function makeSquareCollected(string $reason, User $owner, Workspace $workspace, ClientInvoice $invoice): void
    {
        $links = app(SquareLinkService::class);
        if ($reason === 'linked') {
            $links->link($workspace, $invoice, $owner, AgentApiVersion::for($invoice), new SquareInvoiceReference('inv:0-fixture1', '000001-R-0001', 'USD', SquareMatchMethod::Manual), false);
        } else {
            $agreement = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($invoice->client_agreement_id)->firstOrFail();
            $links->markAgreement($workspace, $agreement, $owner, AgentApiVersion::for($agreement), '000001', 'LOCFIXTURE01');
        }
        $this->assertSame(PaymentCollectionGuard::OWNER_SQUARE, app(PaymentCollectionGuard::class)->summary($invoice->refresh())['collection_owner']);
    }

    /** @return array<string, mixed> */
    private function payload(ClientInvoice $invoice, int $amount): array
    {
        return ['confirm' => true, 'expected_version' => AgentApiVersion::for($invoice), 'invoice_id' => $invoice->public_id, 'amount' => $amount, 'currency' => 'USD', 'received_on' => now()->toDateString(), 'method' => 'ach'];
    }

    /**
     * Record over REST or MCP. A success returns the payments; a refusal
     * returns its message.
     *
     * @param  array<string, mixed>  $payload
     */
    private function record(string $transport, User $user, Workspace $workspace, array $payload, string $key, int $expected = 201): mixed
    {
        $this->actingAsMcp($user, self::SCOPES);
        if ($transport === 'rest') {
            $response = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/workspaces/'.$workspace->public_id.'/payments', $payload)->assertStatus($expected);

            return $expected === 201 ? $response->json('data') : (string) $response->json('message');
        }
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic guard test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = $init->headers->get('Mcp-Session-Id');
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'payments.record', 'arguments' => [...$payload, 'workspace_id' => $workspace->public_id, 'idempotency_key' => $key]],
        ], $headers)->assertOk()->json();
        if ($expected !== 201) {
            // The tool reaches the REST route, so a refusal comes back as a
            // tool error carrying the route's own message.
            $this->assertTrue($result['result']['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
            $audit = AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'payments.record')->latest('id')->firstOrFail();
            $this->assertSame('failed', $audit->outcome);

            return (string) ($result['result']['content'][0]['text'] ?? '');
        }
        $this->assertFalse($result['result']['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        return $result['result']['structuredContent']['data'];
    }
}
