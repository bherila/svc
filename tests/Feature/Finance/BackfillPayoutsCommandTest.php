<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorPayout;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\FakesSquare;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

/**
 * `svc:finance:backfill-payouts` (S7): fills payouts and each processor
 * payment's settlement for history recorded before svc stored them, through
 * the webhooks' own code. A dry run unless `--apply`; a second run changes
 * nothing; a run that stops resumes when run again. Stripe and Square are
 * answered in-process.
 */
final class BackfillPayoutsCommandTest extends TestCase
{
    use FakesSquare;
    use FakesStripeApi;
    use RefreshDatabase;

    /** Whether the second Stripe payout's balance transactions can be read. */
    private bool $secondReadable = true;

    protected function tearDown(): void
    {
        $this->restoreStripeApi();
        parent::tearDown();
    }

    public function test_a_dry_run_reports_what_it_would_change_and_writes_nothing(): void
    {
        [$workspace, $company] = $this->tenant('dry');
        $settled = $this->payment($workspace, $company, 'stripe', 'pi_synthetic_a');
        $unpaid = $this->payment($workspace, $company, 'stripe', 'pi_synthetic_b');
        $this->fakeStripe();

        $report = $this->backfill(['--provider' => 'stripe']);

        $this->assertSame(0, $report['exit']);
        $this->assertFalse($report['applied']);
        $this->assertSame(['payouts_read' => 1, 'payout_rows_changed' => 1, 'payments_changed' => 1, 'charges_read' => 2, 'charges_changed' => 2, 'charges_unreadable' => 0, 'note' => null], $report['providers']['stripe']);
        $this->assertSame(0, ProcessorPayout::query()->count());
        foreach ([$settled, $unpaid] as $payment) {
            $this->assertSame($payment->lock_version, $payment->fresh()?->lock_version);
            $this->assertNull($payment->fresh()?->provider_charged_at);
        }
        $this->assertArrayNotHasKey('square', $report['providers']);
    }

    public function test_apply_writes_and_a_second_run_changes_nothing(): void
    {
        [$workspace, $company] = $this->tenant('apply');
        $settled = $this->payment($workspace, $company, 'stripe', 'pi_synthetic_a');
        $unpaid = $this->payment($workspace, $company, 'stripe', 'pi_synthetic_b');
        $manual = $this->payment($workspace, $company, null, null);
        $this->fakeStripe();

        $first = $this->backfill(['--provider' => 'stripe', '--apply' => true, '--since' => '2025-10-01']);

        $this->assertTrue($first['applied']);
        $this->assertTrue($first['completed']);
        // The payout pass settles the first payment; the charge pass reads only the other.
        $this->assertSame(['payouts_read' => 1, 'payout_rows_changed' => 1, 'payments_changed' => 1, 'charges_read' => 1, 'charges_changed' => 1, 'charges_unreadable' => 0, 'note' => null], $first['providers']['stripe']);
        $this->assertSame(['po_synthetic_one', 59], [$settled->fresh()?->provider_payout_identifier, $settled->fresh()?->provider_fee_amount]);
        $this->assertSame([null, 31, '2025-10-10T12:00:00+00:00'], [$unpaid->fresh()?->provider_payout_identifier, $unpaid->fresh()?->provider_fee_amount, $unpaid->fresh()?->provider_charged_at?->toIso8601String()]);
        $this->assertSame(941, ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole()->amount);
        $this->assertNull($manual->fresh()?->provider_charged_at);
        // Payouts created since the day given.
        $listed = collect($this->stripeCalls)->firstWhere('path', '/v1/payouts');
        $this->assertSame(['gte' => 1_759_276_800], $listed['params']['created']);

        $versions = [$settled->fresh()?->lock_version, $unpaid->fresh()?->lock_version, ProcessorPayout::query()->sole()->lock_version];
        $second = $this->backfill(['--provider' => 'stripe', '--apply' => true]);
        $this->assertSame(['payouts_read' => 1, 'payout_rows_changed' => 0, 'payments_changed' => 0, 'charges_read' => 0, 'charges_changed' => 0, 'charges_unreadable' => 0, 'note' => null], $second['providers']['stripe']);
        $this->assertSame($versions, [$settled->fresh()?->lock_version, $unpaid->fresh()?->lock_version, ProcessorPayout::query()->sole()->lock_version]);
    }

    public function test_a_stripe_outage_stops_the_run_and_running_it_again_resumes(): void
    {
        [$workspace, $company] = $this->tenant('outage');
        $this->payment($workspace, $company, 'stripe', 'pi_synthetic_a');
        $second = $this->payment($workspace, $company, 'stripe', 'pi_synthetic_c');
        $this->secondReadable = false;
        $this->fakeStripe(withSecondPayout: true);

        $stopped = $this->backfill(['--provider' => 'stripe', '--apply' => true]);

        $this->assertSame(1, $stopped['exit']);
        $this->assertFalse($stopped['completed']);
        $this->assertStringStartsWith('stopped: ', (string) $stopped['providers']['stripe']['note']);
        $this->assertSame(['po_synthetic_one'], ProcessorPayout::query()->pluck('provider_payout_identifier')->all());
        $this->assertNull($second->fresh()?->provider_payout_identifier);

        $this->secondReadable = true;
        $resumed = $this->backfill(['--provider' => 'stripe', '--apply' => true]);
        $this->assertSame(0, $resumed['exit']);
        // The payout already stored is not written again.
        $this->assertSame(1, $resumed['providers']['stripe']['payout_rows_changed']);
        $this->assertSame('po_synthetic_two', $second->fresh()?->provider_payout_identifier);
    }

    public function test_a_workspace_option_keeps_every_write_to_that_workspace(): void
    {
        [$first, $firstCompany] = $this->tenant('first');
        [$second, $secondCompany] = $this->tenant('second');
        $mine = $this->payment($first, $firstCompany, 'stripe', 'pi_synthetic_a');
        $theirs = $this->payment($second, $secondCompany, 'stripe', 'pi_synthetic_c');
        $this->fakeStripe(sharedWith: 'pi_synthetic_c');

        $report = $this->backfill(['--provider' => 'stripe', '--apply' => true, '--workspace' => $first->slug]);

        $this->assertSame(0, $report['exit']);
        $this->assertSame([$first->id], ProcessorPayout::query()->pluck('workspace_id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->assertSame('po_synthetic_one', $mine->fresh()?->provider_payout_identifier);
        $this->assertNull($theirs->fresh()?->provider_payout_identifier);
        $this->assertNull($theirs->fresh()?->provider_charged_at);
    }

    public function test_square_payouts_are_read_for_every_location_since_a_day(): void
    {
        Date::setTestNow('2030-02-02 12:00:00');
        Http::preventStrayRequests();
        [$workspace, $company] = $this->tenant('square');
        $this->connectSquare($workspace);
        $settled = $this->payment($workspace, $company, 'square', 'pay_fixture_1', 12_345);
        $unpaid = $this->payment($workspace, $company, 'square', 'pay_fixture_2', 12_345);
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match (true) {
                $path === '/v2/locations' => Http::response(['locations' => [self::squareLocation('LOCFIXTURE01'), self::squareLocation('LOCFIXTURE02')]]),
                $path === '/v2/payouts' => Http::response(['payouts' => $query['location_id'] === 'LOCFIXTURE01' ? [[
                    'id' => 'po_fixture_1', 'status' => 'PAID', 'type' => 'BATCH', 'arrival_date' => '2030-01-20', 'updated_at' => '2030-01-19T09:00:00Z',
                    'amount_money' => ['amount' => 11_957, 'currency' => 'USD'],
                ]] : []]),
                $path === '/v2/payouts/po_fixture_1/payout-entries' => Http::response(['payout_entries' => [[
                    'payout_id' => 'po_fixture_1', 'type' => 'CHARGE', 'type_charge_details' => ['payment_id' => 'pay_fixture_1'],
                    'gross_amount_money' => ['amount' => 12_345, 'currency' => 'USD'], 'fee_amount_money' => ['amount' => 388, 'currency' => 'USD'],
                ]]]),
                str_starts_with($path, '/v2/payments/') => Http::response(['payment' => [
                    ...self::squarePayment(substr($path, strlen('/v2/payments/'))),
                    'card_details' => ['card_payment_timeline' => ['captured_at' => '2030-01-15T16:00:00Z']],
                    'processing_fee' => [['type' => 'INITIAL', 'amount_money' => ['amount' => -388, 'currency' => 'USD']]],
                ]]),
                default => Http::response([], 404),
            };
        });

        $report = $this->backfill(['--provider' => 'square', '--apply' => true, '--since' => '2030-01-01']);

        $this->assertSame(['payouts_read' => 1, 'payout_rows_changed' => 1, 'payments_changed' => 1, 'charges_read' => 2, 'charges_changed' => 2, 'charges_unreadable' => 0, 'note' => null], $report['providers']['square']);
        $this->assertSame(['po_fixture_1', 388, '2030-01-15T16:00:00+00:00'], [$settled->fresh()?->provider_payout_identifier, $settled->fresh()?->provider_fee_amount, $settled->fresh()?->provider_charged_at?->toIso8601String()]);
        $this->assertSame([null, 388], [$unpaid->fresh()?->provider_payout_identifier, $unpaid->fresh()?->provider_fee_amount]);
        $payoutReads = Http::recorded(static fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/v2/payouts'));
        $this->assertCount(2, $payoutReads);
        foreach ($payoutReads as [$request]) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('2030-01-01T00:00:00+00:00', $query['begin_time']);
        }
        $this->assertSame('skipped: not configured', $this->backfill(['--provider' => 'stripe'])['providers']['stripe']['note']);
    }

    public function test_options_are_checked_before_anything_is_read(): void
    {
        $this->fakeStripe();
        foreach ([['--provider' => 'paypal'], ['--since' => '2026-13-01'], ['--format' => 'xml'], ['--workspace' => 'no-such-workspace']] as $options) {
            $this->assertSame(2, Artisan::call('svc:finance:backfill-payouts', $options));
        }
        $this->assertSame([], $this->stripeCalls);

        // Neither processor connected: nothing to do, and said so.
        config(['services.stripe.secret_key' => null]);
        $this->assertSame(0, Artisan::call('svc:finance:backfill-payouts'));
        $this->assertStringContainsString('Dry run', Artisan::output());
    }

    /** @return array{0: Workspace, 1: ClientCompany} */
    private function tenant(string $suffix): array
    {
        $workspace = Workspace::query()->create(['name' => 'Backfill '.$suffix, 'slug' => 'backfill-'.$suffix]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Backfill client '.$suffix, 'slug' => 'backfill-client-'.$suffix]);

        return [$workspace, $company];
    }

    private function payment(Workspace $workspace, ClientCompany $company, ?string $provider, ?string $identifier, int $amount = 1000): ClientInvoicePayment
    {
        $invoices = app(InvoiceLifecycleService::class);
        $invoice = $invoices->createDraft($workspace, $company, ['invoice_number' => 'INV-'.Str::upper(Str::random(8)), 'currency' => 'USD'],
            [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => $amount, 'tax_amount' => 0]]);
        $invoices->issue($invoice, $workspace);

        return $invoices->applyPayment($invoice, array_filter([
            'amount' => $amount, 'currency' => 'USD', 'method' => $provider ?? 'check', 'status' => 'succeeded',
            'provider' => $provider, 'provider_payment_identifier' => $identifier,
        ], static fn (mixed $value): bool => $value !== null), $workspace);
    }

    /**
     * Stripe with one automatic payout settling `pi_synthetic_a` (and
     * `$sharedWith`, when named), optionally a second settling
     * `pi_synthetic_c`, and every payment intent's charge.
     */
    private function fakeStripe(bool $withSecondPayout = false, ?string $sharedWith = null): void
    {
        $this->fakeStripeApi(function (string $method, string $path, array $params) use ($withSecondPayout, $sharedWith): array {
            if ($path === '/v1/payouts') {
                return [self::stripeList($path, array_values(array_filter([
                    self::stripePayout('po_synthetic_one', 941),
                    $withSecondPayout ? self::stripePayout('po_synthetic_two', 970, arrival: 1_761_004_800) : null,
                ])))];
            }
            if ($path === '/v1/balance_transactions') {
                if ($params['payout'] === 'po_synthetic_two') {
                    return $this->secondReadable ? [self::stripeList($path, [self::stripeChargeEntry('ch_synthetic_c', 'pi_synthetic_c', 1000, 30)])] : self::stripeError(503);
                }

                return [self::stripeList($path, array_values(array_filter([
                    self::stripeChargeEntry('ch_synthetic_a', 'pi_synthetic_a', 1000, 59),
                    $sharedWith === null ? null : self::stripeChargeEntry('ch_synthetic_shared', $sharedWith, 1000, 41),
                ])))];
            }
            if ($path === '/v1/charges') {
                $intent = (string) $params['payment_intent'];

                return [self::stripeList($path, [[
                    'id' => 'ch_'.$intent, 'object' => 'charge', 'status' => 'succeeded', 'created' => 1_760_097_600,
                    'balance_transaction' => ['id' => 'txn_'.$intent, 'object' => 'balance_transaction', 'fee' => 31, 'currency' => 'usd'],
                ]])];
            }

            return self::stripeError(404);
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function backfill(array $options): array
    {
        $exit = Artisan::call('svc:finance:backfill-payouts', [...$options, '--format' => 'json']);

        return ['exit' => $exit, ...json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
    }
}
