<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripeDisputeRecovery;
use App\Support\Billing\StripeEventMustBeRetried;
use App\Support\Finance\ReconciliationReviewReason;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

final class StripeDisputeRecoveryTest extends TestCase
{
    use FakesStripeApi;
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> */
    private array $pages = [];

    private ?\Closure $whileReading = null;

    /** @var array<string, int> */
    private array $pageStatuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-01-20 12:00:00');
        config(['services.stripe.webhook_secret' => 'whsec_dispute_synthetic']);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
        $this->fakeStripeApi(function (string $method, string $path, array $params): array {
            $this->assertSame('get', $method);
            $this->assertSame('/v1/disputes', $path);
            $this->assertSame(100, $params['limit'] ?? null);
            if ($this->whileReading !== null) {
                $whileReading = $this->whileReading;
                $this->whileReading = null;
                $whileReading();
            }

            $cursor = $params['starting_after'] ?? '';
            if (isset($this->pageStatuses[$cursor])) {
                return self::stripeError($this->pageStatuses[$cursor]);
            }

            return [$this->pages[$cursor] ?? self::stripeList('/v1/disputes', [])];
        });
    }

    protected function tearDown(): void
    {
        $this->restoreStripeApi();
        parent::tearDown();
    }

    public function test_a_dispute_delivered_before_payment_is_recovered_after_payment_recording(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $this->deliver('evt_dispute_before_payment', self::dispute('du_fixture_a', 'needs_response', 400))->assertOk();
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_dispute_before_payment', 'status' => 'processed']);
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $payment = $this->record($workspace, $invoice);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', 'needs_response', 400)]);

        $snapshot = app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
        $this->assertSame(400, $snapshot->withheldMinor);
        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(400, $payment->fresh()->disputed_amount);
        $this->assertSame(400, $invoice->fresh()->balance_amount);
        $this->assertSame('disputed', $invoice->fresh()->status);
    }

    public function test_preview_returns_the_complete_group_without_any_writes(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        $this->pages[''] = [...self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', 'needs_response', 100)]), 'has_more' => true];
        $this->pages['du_fixture_a'] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_b', 'lost', 200), self::dispute('du_fixture_c', 'won', 300)]);
        $before = $this->financialRows();
        $snapshot = app(StripeDisputeRecovery::class)->recover($workspace, $payment->id);
        $this->assertSame(300, $snapshot->withheldMinor);
        $this->assertCount(3, $snapshot->disputes);
        $this->assertSame($workspace->id, $snapshot->workspaceId);
        $this->assertSame($payment->id, $snapshot->paymentId);
        $this->assertSame('pi_dispute_fixture', $snapshot->providerIdentifier);
        $this->assertSame($payment->lock_version, $snapshot->version);
        $this->assertSame($before, $this->financialRows());
        $this->assertSame('pi_dispute_fixture', $this->stripeCalls[0]['params']['payment_intent'] ?? null);
        $this->assertSame('du_fixture_a', $this->stripeCalls[1]['params']['starting_after'] ?? null);
    }

    public function test_an_unchanged_group_records_its_revision_timestamp_in_utc(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', 'won', 100)]);
        $service = app(StripeDisputeRecovery::class);
        $snapshot = $service->read($workspace, $payment->id);
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $service->apply($workspace, $snapshot, true);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
        $this->assertSame('2030-01-20 12:00:00', $payment->fresh()->getRawOriginal('updated_at'));
        $this->assertSame($snapshot->version + 1, $payment->fresh()->lock_version);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
    }

    public function test_multiple_disputes_are_summed_and_a_separate_win_does_not_release_an_open_hold(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        $allocation = PaymentReconciliation::query()->create(['workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id,
            'external_system_slug' => 'synthetic-finance', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 1000, 'currency' => 'USD', 'is_active' => true]);
        $open = self::dispute('du_fixture_a', 'needs_response', 100);
        $second = self::dispute('du_fixture_b', 'under_review', 200);
        $won = self::dispute('du_fixture_c', 'won', 300);
        $this->pages[''] = [...self::stripeList('/v1/disputes', [$open]), 'has_more' => true];
        $this->pages['du_fixture_a'] = self::stripeList('/v1/disputes', [$second, $won]);
        app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
        $this->assertSame(300, $payment->fresh()->disputed_amount);
        $this->assertSame(300, $invoice->fresh()->balance_amount);
        $this->assertSame(1000, $payment->fresh()->amount);
        $this->assertSame(0, $payment->fresh()->refunded_amount);
        $this->assertSame(1000, $allocation->fresh()->allocated_amount);
        $this->assertTrue($allocation->fresh()->is_active);
        $this->assertSame(ReconciliationReviewReason::ProcessorDispute, $allocation->fresh()->review_reason);
    }

    #[DataProvider('resolvedStatuses')]
    public function test_only_a_complete_resolved_group_restores_the_payment(string $status): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 400, $workspace);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', $status, 400)]);
        app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->disputed_amount);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
    }

    /** @return iterable<string, array{string}> */
    public static function resolvedStatuses(): iterable
    {
        foreach (['won', 'warning_closed', 'prevented'] as $status) {
            yield $status => [$status];
        }
    }

    public function test_a_payment_changed_during_the_provider_read_refuses_the_snapshot(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', 'needs_response', 100)]);
        $this->whileReading = static function () use ($workspace, $payment): void {
            app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 700, $workspace);
        };
        try {
            app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
            $this->fail('A stale dispute snapshot was applied.');
        } catch (StripeEventMustBeRetried) {
            $this->assertSame(700, $payment->fresh()->disputed_amount);
            $this->assertSame(700, $invoice->fresh()->balance_amount);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function unchangedGroups(): iterable
    {
        yield 'already restored' => [false];
        yield 'same existing hold' => [true];
    }

    #[DataProvider('unchangedGroups')]
    public function test_accepting_an_unchanged_newer_group_invalidates_an_older_concurrent_read(bool $held): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        if ($held) {
            app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 100, $workspace);
        }
        $service = app(StripeDisputeRecovery::class);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', $held ? 'won' : 'needs_response', 100)]);
        $older = $service->read($workspace, $payment->id);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', $held ? 'needs_response' : 'won', 100)]);
        $newer = $service->read($workspace, $payment->id);
        $this->assertSame($older->version, $newer->version);
        $service->apply($workspace, $newer, true);
        try {
            $service->apply($workspace, $older, true);
            $this->fail('An older read must lose even when newer facts changed no amount.');
        } catch (StripeEventMustBeRetried) {
            $this->assertSame($held ? 'disputed' : 'succeeded', $payment->fresh()->status);
            $this->assertSame($held ? 100 : null, $payment->fresh()->disputed_amount);
            $this->assertSame($held ? 100 : 0, $invoice->fresh()->balance_amount);
        }
    }

    public function test_read_and_apply_can_share_the_same_provider_objects_with_movement_ingestion(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        $dispute = [...self::dispute('du_fixture_a', 'needs_response', 400), 'balance_transactions' => [
            ['id' => 'txn_fixture_a', 'object' => 'balance_transaction', 'amount' => -400, 'fee' => 10, 'net' => -410, 'currency' => 'usd', 'created' => 1893456000],
        ]];
        $this->pages[''] = self::stripeList('/v1/disputes', [$dispute]);
        $service = app(StripeDisputeRecovery::class);
        $snapshot = $service->read($workspace, $payment->id);
        $this->assertSame([$dispute], $snapshot->disputes);
        $service->apply($workspace, $snapshot, true);
        $this->assertCount(1, $this->stripeCalls);
        $this->assertSame(400, $payment->fresh()->disputed_amount);
    }

    /** @param  array<string, array<string, mixed>>  $pages */
    #[DataProvider('unreadablePages')]
    public function test_unreadable_or_incomplete_groups_never_apply_a_partial_hold(array $pages): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        $this->pages = $pages;
        $before = $this->financialRows();
        try {
            app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
            $this->fail('An incomplete dispute group was applied.');
        } catch (StripeEventMustBeRetried) {
            $this->assertSame($before, $this->financialRows());
        }
    }

    /** @return iterable<string, array{array<string, array<string, mixed>>}> */
    public static function unreadablePages(): iterable
    {
        $open = self::dispute('du_fixture_a', 'needs_response', 100);
        yield 'unknown state' => [['' => self::stripeList('/v1/disputes', [[...$open, 'status' => 'future_status']])]];
        yield 'other currency' => [['' => self::stripeList('/v1/disputes', [[...$open, 'currency' => 'eur']])]];
        yield 'other payment' => [['' => self::stripeList('/v1/disputes', [[...$open, 'payment_intent' => 'pi_foreign_fixture']])]];
        yield 'over capacity' => [['' => self::stripeList('/v1/disputes', [[...$open, 'amount' => 1001]])]];
        yield 'combined over capacity' => [['' => self::stripeList('/v1/disputes', [[...$open, 'amount' => 600], [...$open, 'id' => 'du_fixture_b', 'amount' => 600]])]];
        yield 'nonnumeric amount' => [['' => self::stripeList('/v1/disputes', [[...$open, 'amount' => '100']])]];
        yield 'nonlist data' => [['' => ['object' => 'list', 'data' => ['named' => $open], 'has_more' => false]]];
        yield 'missing completion flag' => [['' => ['object' => 'list', 'data' => [$open]]]];
        yield 'late empty unfinished page' => [['' => [...self::stripeList('/v1/disputes', [$open]), 'has_more' => true], 'du_fixture_a' => [...self::stripeList('/v1/disputes', []), 'has_more' => true]]];
        yield 'cyclic duplicate' => [['' => [...self::stripeList('/v1/disputes', [$open]), 'has_more' => true], 'du_fixture_a' => [...self::stripeList('/v1/disputes', [$open]), 'has_more' => true]]];
        yield 'too many records' => [['' => self::stripeList('/v1/disputes', array_fill(0, 101, $open))]];
    }

    public function test_a_foreign_or_unknown_payment_is_refused_before_provider_reads(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        [$foreign] = $this->invoice('foreign');
        foreach ([[$foreign, $payment->id], [$workspace, 999999]] as [$scope, $id]) {
            try {
                app(StripeDisputeRecovery::class)->read($scope, $id);
                $this->fail('A foreign payment was read.');
            } catch (DomainException $exception) {
                $this->assertSame('The Stripe dispute payment is unavailable.', $exception->getMessage());
            }
        }
        $this->assertSame([], $this->stripeCalls);
    }

    public function test_an_empty_group_does_not_invent_a_resolution_for_an_existing_hold(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 400, $workspace);
        $before = $this->financialRows();
        app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
        $this->assertSame($before, $this->financialRows());
    }

    public function test_a_fully_refunded_payment_remains_refunded_even_with_an_open_dispute(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 1000, $workspace);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', 'needs_response', 400)]);
        $before = $this->financialRows();
        app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
        $this->assertSame($before, $this->financialRows());
    }

    public function test_previewing_a_resolved_group_does_not_restore_or_modify_anything(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 400, $workspace);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', 'won', 400)]);
        $before = $this->financialRows();
        $snapshot = app(StripeDisputeRecovery::class)->recover($workspace, $payment->id);
        $this->assertSame(0, $snapshot->withheldMinor);
        $this->assertSame($before, $this->financialRows());
    }

    #[DataProvider('unresolvedStatuses')]
    public function test_each_known_unresolved_status_keeps_its_exact_hold(string $status): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', $status, 400)]);
        app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(400, $payment->fresh()->disputed_amount);
        $this->assertSame(400, $invoice->fresh()->balance_amount);
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvedStatuses(): iterable
    {
        foreach (['warning_needs_response', 'warning_under_review', 'needs_response', 'under_review', 'lost'] as $status) {
            yield $status => [$status];
        }
    }

    public function test_the_page_bound_fails_without_applying_any_partial_snapshot(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        for ($page = 0; $page < 100; $page++) {
            $cursor = $page === 0 ? '' : 'du_bound_'.($page - 1);
            $this->pages[$cursor] = [...self::stripeList('/v1/disputes', [self::dispute('du_bound_'.$page, 'needs_response', 1)]), 'has_more' => true];
        }
        $before = $this->financialRows();
        try {
            app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
            $this->fail('A truncated dispute snapshot was applied.');
        } catch (StripeEventMustBeRetried) {
            $this->assertSame($before, $this->financialRows());
            $this->assertCount(100, $this->stripeCalls);
        }
    }

    public function test_a_later_provider_read_failure_applies_no_partial_dispute_group(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        $this->pages[''] = [...self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', 'needs_response', 400)]), 'has_more' => true];
        $this->pageStatuses['du_fixture_a'] = 503;
        $before = $this->financialRows();
        try {
            app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
            $this->fail('A failed read applied a partial dispute snapshot.');
        } catch (StripeEventMustBeRetried) {
            $this->assertSame($before, $this->financialRows());
        }
    }

    public function test_charge_identified_legacy_payments_use_the_charge_filter_and_exact_identity(): void
    {
        [$workspace, $invoice] = $this->invoice();
        $payment = $this->record($workspace, $invoice);
        $payment->update(['provider_payment_identifier' => 'ch_dispute_fixture']);
        $this->pages[''] = self::stripeList('/v1/disputes', [self::dispute('du_fixture_a', 'needs_response', 400)]);
        app(StripeDisputeRecovery::class)->recover($workspace, $payment->id, true);
        $this->assertSame('ch_dispute_fixture', $this->stripeCalls[0]['params']['charge'] ?? null);
        $this->assertArrayNotHasKey('payment_intent', $this->stripeCalls[0]['params']);
        $this->assertSame(400, $payment->fresh()->disputed_amount);
    }

    /** @return array{Workspace, ClientInvoice} */
    private function invoice(string $suffix = 'owned'): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic dispute workspace '.$suffix, 'slug' => 'synthetic-dispute-'.$suffix]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic dispute company', 'slug' => 'synthetic-dispute-'.$suffix]);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, ['invoice_number' => 'SYNTHETIC-DISPUTE-'.$suffix, 'currency' => 'USD'], [
            ['type' => 'service', 'description' => 'Synthetic service', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0],
        ]);
        $service->issue($invoice, $workspace);

        return [$workspace, $invoice];
    }

    private function record(Workspace $workspace, ClientInvoice $invoice): ClientInvoicePayment
    {
        return app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'succeeded',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_dispute_fixture', 'received_on' => '2030-01-10'], $workspace);
    }

    /** @return array<string, mixed> */
    private static function dispute(string $id, string $status, int $amount): array
    {
        return ['id' => $id, 'object' => 'dispute', 'payment_intent' => 'pi_dispute_fixture', 'charge' => 'ch_dispute_fixture', 'status' => $status, 'amount' => $amount, 'currency' => 'usd', 'balance_transactions' => []];
    }

    /** @return list<list<array<string, mixed>>> */
    private function financialRows(): array
    {
        $rows = [];
        foreach (['client_invoice_payments', 'client_invoices', 'payment_reconciliations', 'client_company_activity'] as $table) {
            $rows[] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return TestResponse<Response>
     */
    private function deliver(string $id, array $object): TestResponse
    {
        $payload = json_encode(['id' => $id, 'object' => 'event', 'type' => 'charge.dispute.created', 'created' => 100, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_dispute_synthetic');

        return $this->call('POST', '/api/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], content: $payload);
    }
}
