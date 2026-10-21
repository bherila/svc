<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Square\SquarePaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesSquarePaymentSync;
use Tests\TestCase;

final class SquareDisputeRecoveryTest extends TestCase
{
    use FakesSquarePaymentSync;
    use RefreshDatabase;

    public function test_sweep_recovers_a_missed_dispute_and_later_win_without_webhooks(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        Queue::fake();
        config(['services.square.webhook_signature_key' => null]);
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->squareDisputes['dispute_fixture_1'] = self::squareDispute('dispute_fixture_1', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 345);

        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
        $this->assertSame('disputed', $invoice->payments()->sole()->status);
        $this->assertSame(345, $invoice->fresh()->balance_amount);
        $this->squareDisputes['dispute_fixture_1']['state'] = 'WON';
        $this->squareDisputes['dispute_fixture_1']['updated_at'] = '2030-02-02T11:00:00Z';
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
        $this->assertSame('succeeded', $invoice->payments()->sole()->status);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        Queue::assertNothingPushed();
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_dispute_received_before_payment_is_recovered_after_the_sweep_records_payment(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        $this->squareDisputes['dispute_fixture_1'] = self::squareDispute('dispute_fixture_1', 'pay_fixture_1', 'LOST', 345);
        $payload = json_encode(['event_id' => 'evt_before_payment_fixture', 'type' => 'dispute.created', 'data' => ['id' => 'dispute_fixture_1']], JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', 'https://svc.example.test/api/webhooks/square'.$payload, 'fixture-signature-key', true));
        $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature], content: $payload)->assertOk();
        $this->assertSame(1, DB::table('square_webhook_receipts')->whereNotNull('completed_at')->count());
        $this->assertDatabaseCount('client_invoice_payments', 0);

        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
        $this->assertSame('disputed', $invoice->payments()->sole()->status);
        $this->assertSame(345, $invoice->fresh()->balance_amount);
        $this->assertDatabaseCount('client_invoice_payments', 1);
    }

    public function test_paginated_disputes_use_only_payments_in_the_connected_workspace(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $other = Workspace::query()->create(['name' => 'Synthetic other disputes', 'slug' => 'synthetic-other-disputes']);
        $company = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Synthetic foreign company', 'slug' => 'synthetic-foreign-company']);
        $foreignInvoice = $invoice->replicate(['public_id']);
        $foreignInvoice->forceFill(['workspace_id' => $other->id, 'client_company_id' => $company->id])->save();
        $foreign = $invoice->payments()->sole()->replicate(['public_id']);
        $foreign->forceFill(['workspace_id' => $other->id, 'client_invoice_id' => $foreignInvoice->id, 'idempotency_key' => null, 'provider_payment_identifier' => 'pay_foreign_fixture'])->save();
        $this->squareDisputePages = [
            '' => ['disputes' => [self::squareDispute('dispute_foreign_fixture', 'pay_foreign_fixture', 'LOST')], 'cursor' => 'next-fixture'],
            'next-fixture' => ['disputes' => [self::squareDispute('dispute_fixture_1', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 345)]],
        ];

        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
        $this->assertSame('disputed', $invoice->payments()->sole()->status);
        $this->assertSame('succeeded', $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->provider_dispute_updated_at_ms);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v2/disputes?cursor=next-fixture')
            && ! isset($request['states']) && ! isset($request['limit']));
    }

    public function test_refused_dispute_read_fails_the_command_and_is_retried_on_the_next_sweep(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        config(['services.square.max_attempts' => 1]);
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->squareDisputes['dispute_fixture_1'] = self::squareDispute('dispute_fixture_1', 'pay_fixture_1', 'LOST', 345);
        $this->squareDisputeListStatus = 403;
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertFailed();
        $this->assertSame('succeeded', $invoice->payments()->sole()->status);
        $this->squareDisputeListStatus = null;
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
        $this->assertSame('disputed', $invoice->payments()->sole()->status);
    }

    /** @param  array<string, array<string, mixed>>  $pages */
    #[DataProvider('unreadablePages')]
    public function test_unreadable_dispute_lists_fail_without_touching_payments(array $pages): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $before = DB::table('client_invoice_payments')->get()->toJson();
        $this->squareDisputePages = $pages;
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertFailed();
        $this->assertSame($before, DB::table('client_invoice_payments')->get()->toJson());
    }

    /** @return iterable<string, array{array<string, array<string, mixed>>}> */
    public static function unreadablePages(): iterable
    {
        yield 'not a list' => [['' => ['disputes' => ['named' => []]]]];
        yield 'nonrecord' => [['' => ['disputes' => ['not-a-record']]]];
        yield 'missing identity' => [['' => ['disputes' => [[]]]]];
        yield 'invalid cursor' => [['' => ['disputes' => [], 'cursor' => 'contains space']]];
        yield 'nonstring cursor' => [['' => ['disputes' => [], 'cursor' => 12]]];
        yield 'cyclic cursor' => [[
            '' => ['disputes' => [], 'cursor' => 'repeat-fixture'],
            'repeat-fixture' => ['disputes' => [], 'cursor' => 'repeat-fixture'],
        ]];
        yield 'oversized page' => [['' => ['disputes' => array_fill(0, 101, [])]]];
    }

    #[DataProvider('errorBearingPages')]
    public function test_error_bearing_dispute_pages_preserve_every_existing_payment_and_invoice_row(string $transport, bool $laterPage, mixed $errors): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        $reconciler = app(SquarePaymentReconciler::class);
        $reconciler->invoice($workspace, 'inv_fixture_1');
        $this->squareDisputes = ['dispute_fixture_a' => self::squareDispute('dispute_fixture_a', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 345)];
        $reconciler->disputes($workspace);
        $this->assertSame('disputed', $invoice->payments()->sole()->status);
        $this->assertSame(345, $invoice->fresh()->balance_amount);
        $before = $this->financialRows();
        // The partial result omits the still-open dispute and returns only a
        // newer resolved one. Treating it as complete would release the hold.
        $won = [...self::squareDispute('dispute_fixture_b', 'pay_fixture_1', 'WON', 200), 'updated_at' => '2030-02-02T11:00:00Z'];
        $this->squareDisputes = ['dispute_fixture_b' => $won];
        $this->squareDisputePages = $laterPage
            ? ['' => ['disputes' => [$won], 'cursor' => 'error_page_fixture'], 'error_page_fixture' => ['disputes' => [], 'errors' => $errors]]
            : ['' => ['disputes' => [$won], 'errors' => $errors]];

        if ($transport === 'sweep') {
            $this->artisan('svc:square:reconcile-payments', ['--apply' => true, '--format' => 'json'])
                ->expectsOutput('{"failed":1}')->doesntExpectOutputToContain('synthetic_private_error')->assertFailed();
        } else {
            $payload = json_encode(['event_id' => 'evt_error_page_fixture', 'type' => 'dispute.state.updated', 'data' => ['id' => 'dispute_fixture_b']], JSON_THROW_ON_ERROR);
            $signature = base64_encode(hash_hmac('sha256', 'https://svc.example.test/api/webhooks/square'.$payload, 'fixture-signature-key', true));
            $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature], content: $payload)
                ->assertStatus(503)->assertExactJson(['message' => 'Webhook reconciliation must be retried.']);
        }
        $this->assertSame($before, $this->financialRows());
        $this->assertSame('disputed', $invoice->payments()->sole()->status);
        $this->assertSame(345, $invoice->payments()->sole()->disputed_amount);
        $this->assertSame(345, $invoice->fresh()->balance_amount);
    }

    /** @return iterable<string, array{string, bool, mixed}> */
    public static function errorBearingPages(): iterable
    {
        $cases = [
            'reported error list' => [['category' => 'API_ERROR', 'code' => 'INTERNAL_SERVER_ERROR', 'detail' => 'synthetic_private_error']],
            'malformed named errors' => ['detail' => 'synthetic_private_error'],
            'malformed error string' => 'synthetic_private_error',
            'malformed error integer' => 1,
            'malformed error boolean' => true,
            'malformed error member' => [null],
        ];
        foreach (['sweep', 'webhook'] as $transport) {
            foreach ([false, true] as $laterPage) {
                foreach ($cases as $name => $errors) {
                    yield $transport.' '.($laterPage ? 'later' : 'first').' '.$name => [$transport, $laterPage, $errors];
                }
            }
        }
    }

    #[DataProvider('transports')]
    public function test_empty_errors_on_every_dispute_page_do_not_refuse_a_complete_snapshot(string $transport): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        $reconciler = app(SquarePaymentReconciler::class);
        $reconciler->invoice($workspace, 'inv_fixture_1');
        $this->squareDisputes = ['dispute_fixture_a' => self::squareDispute('dispute_fixture_a', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 345)];
        $reconciler->disputes($workspace);
        $won = [...self::squareDispute('dispute_fixture_a', 'pay_fixture_1', 'WON', 345), 'updated_at' => '2030-02-02T11:00:00Z'];
        $this->squareDisputes = ['dispute_fixture_a' => $won];
        $this->squareDisputePages = ['' => ['disputes' => [$won], 'errors' => [], 'cursor' => 'empty_errors_fixture'],
            'empty_errors_fixture' => ['disputes' => [], 'errors' => []]];

        $this->recover($transport);
        $this->assertSame('succeeded', $invoice->payments()->sole()->status);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function financialRows(): array
    {
        $rows = [];
        foreach (['client_invoice_payments', 'client_invoices', 'client_invoice_lines', 'payment_reconciliations'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    public function test_page_bound_is_a_failed_sweep_instead_of_silent_completion(): void
    {
        $this->paymentFixture();
        for ($page = 0; $page < 100; $page++) {
            $this->squareDisputePages[$page === 0 ? '' : 'page-'.$page] = ['disputes' => [], 'cursor' => 'page-'.($page + 1)];
        }
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertFailed();
        $reads = Http::recorded(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v2/disputes');
        $this->assertCount(100, $reads);
    }

    public function test_preview_never_lists_disputes_or_writes_payments(): void
    {
        $this->paymentFixture();
        $this->artisan('svc:square:reconcile-payments')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    #[DataProvider('transports')]
    public function test_an_open_dispute_is_not_released_by_a_different_newer_won_dispute(string $transport): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $open = self::squareDispute('dispute_fixture_a', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 100);
        $won = [...self::squareDispute('dispute_fixture_b', 'pay_fixture_1', 'WON', 200), 'updated_at' => '2030-02-02T11:00:00Z'];
        $this->squareDisputes = ['dispute_fixture_a' => $open, 'dispute_fixture_b' => $won];
        $this->squareDisputePages = ['' => ['disputes' => [$open], 'cursor' => 'next-fixture'], 'next-fixture' => ['disputes' => [$won]]];

        $this->recover($transport, true, 'dispute_fixture_b');
        $this->assertSame('disputed', $invoice->payments()->sole()->status);
        $this->assertSame(100, $invoice->payments()->sole()->disputed_amount);
        $this->assertSame(100, $invoice->fresh()->balance_amount);
    }

    #[DataProvider('transports')]
    public function test_two_distinct_open_disputes_are_summed_once_across_pages(string $transport): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $first = self::squareDispute('dispute_fixture_a', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 100);
        $second = [...self::squareDispute('dispute_fixture_b', 'pay_fixture_1', 'LOST', 200), 'updated_at' => '2030-02-02T11:00:00Z'];
        $this->squareDisputes = ['dispute_fixture_a' => $first, 'dispute_fixture_b' => $second];
        $this->squareDisputePages = ['' => ['disputes' => [$first], 'cursor' => 'next-fixture'], 'next-fixture' => ['disputes' => [$second]]];

        $this->recover($transport);
        $this->assertSame('disputed', $invoice->payments()->sole()->status);
        $this->assertSame(300, $invoice->payments()->sole()->disputed_amount);
        $this->assertSame(300, $invoice->fresh()->balance_amount);
    }

    #[DataProvider('transports')]
    public function test_a_payment_changed_while_the_dispute_snapshot_is_read_is_not_overwritten(string $transport): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $payment = $invoice->payments()->sole();
        $this->squareDisputes['dispute_fixture_a'] = self::squareDispute('dispute_fixture_a', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 100);
        $this->whileReadingSquareDisputes = static function () use ($workspace, $payment): void {
            app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 700, $workspace);
        };

        $this->recover($transport, false);
        $this->assertSame(700, $payment->fresh()->disputed_amount);
        $this->assertSame(700, $invoice->fresh()->balance_amount);
    }

    #[DataProvider('transports')]
    public function test_a_later_missing_page_prevents_every_dispute_write(string $transport): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->squareDisputes['dispute_fixture_a'] = self::squareDispute('dispute_fixture_a', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 100);
        $this->squareDisputePages = ['' => ['disputes' => array_values($this->squareDisputes), 'cursor' => 'next-fixture'], 'next-fixture' => ['disputes' => 'unreadable']];

        $this->recover($transport, false);
        $this->assertSame('succeeded', $invoice->payments()->sole()->status);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        $this->assertNull($invoice->payments()->sole()->provider_dispute_updated_at_ms);
    }

    #[DataProvider('transports')]
    public function test_duplicate_dispute_ids_fail_before_any_payment_write(string $transport): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->squareDisputes['dispute_fixture_a'] = self::squareDispute('dispute_fixture_a', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 100);
        $this->squareDisputePages = ['' => ['disputes' => array_values($this->squareDisputes), 'cursor' => 'next-fixture'], 'next-fixture' => ['disputes' => array_values($this->squareDisputes)]];

        $this->recover($transport, false);
        $this->assertSame('succeeded', $invoice->payments()->sole()->status);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
    }

    #[DataProvider('transports')]
    public function test_a_combined_hold_above_the_payment_capacity_is_refused_without_capping(string $transport): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->squareDisputes = [
            'dispute_fixture_a' => self::squareDispute('dispute_fixture_a', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 8000),
            'dispute_fixture_b' => self::squareDispute('dispute_fixture_b', 'pay_fixture_1', 'LOST', 8000),
        ];
        $this->recover($transport, false);
        $this->assertSame('succeeded', $invoice->payments()->sole()->status);
        $this->assertNull($invoice->payments()->sole()->disputed_amount);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
    }

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'scheduled sweep' => ['sweep'];
        yield 'signed webhook' => ['webhook'];
    }

    private function recover(string $transport, bool $succeeds = true, string $hint = 'dispute_fixture_a'): void
    {
        if ($transport === 'sweep') {
            $command = $this->artisan('svc:square:reconcile-payments', ['--apply' => true]);
            if ($succeeds) {
                $command->assertSuccessful();
            } else {
                $command->assertFailed();
            }

            return;
        }
        $payload = json_encode(['event_id' => 'evt_grouped_dispute_fixture', 'type' => 'dispute.state.updated', 'data' => ['id' => $hint]], JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', 'https://svc.example.test/api/webhooks/square'.$payload, 'fixture-signature-key', true));
        $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature], content: $payload)->assertStatus($succeeds ? 200 : 503);
    }
}
