<?php

namespace Tests\Feature\Billing\Autopay;

use App\Models\ClientAutopayConsent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\InvoiceAutopayAttempt;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayChargeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayChargeResult;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAutopay;
use Tests\TestCase;

final class AutopayChargeTest extends TestCase
{
    use BuildsAutopay;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->restoreAutopayStripe();
        parent::tearDown();
    }

    /** @return array{0: Workspace, 1: ClientCompany, 2: ClientInvoice} */
    private function dueInvoice(int $balance = 375_000, int $limit = 500_000): array
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact, $limit);
        $invoice = $this->autopayInvoice($company, balance: $balance);
        // 10:00 on the due date in New York.
        $this->travelTo('2030-12-01 15:00:00');

        return [$workspace, $company->refresh(), $invoice];
    }

    public function test_a_due_invoice_is_charged_once_against_the_default_method_and_enters_the_ledger_pending(): void
    {
        [$workspace, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe();

        $outcomes = app(AutopayChargeService::class)->run(50, true);

        $this->assertCount(1, $outcomes);
        $this->assertSame(AutopayChargeResult::Submitted, $outcomes[0]->result);
        $charges = $this->autopayCharges();
        $this->assertCount(1, $charges);
        $attempt = InvoiceAutopayAttempt::query()->sole();
        $this->assertSame([
            'amount' => 375_000, 'currency' => 'usd', 'customer' => 'cus_synthetic_autopay', 'payment_method' => 'pm_synthetic_autopay',
            'off_session' => 'true', 'confirm' => 'true',
        ], array_intersect_key($charges[0]['params'], array_flip(['amount', 'currency', 'customer', 'payment_method', 'off_session', 'confirm'])));
        // The metadata the webhook resolves the invoice from, and the idempotency key derived from the attempt.
        $this->assertSame($invoice->public_id, $charges[0]['params']['metadata']['invoice_public_id']);
        $this->assertSame($workspace->public_id, $charges[0]['params']['metadata']['workspace_public_id']);
        $this->assertSame($attempt->public_id, $charges[0]['params']['metadata']['autopay_attempt_public_id']);
        $this->assertSame('autopay-attempt-'.$attempt->public_id, $charges[0]['idempotency']);

        $this->assertSame(AutopayAttemptState::Submitted, $attempt->state);
        $this->assertSame(375_000, $attempt->amount);
        $this->assertSame('Visa ending 4242', $attempt->payment_method_label);
        $this->assertNotNull($attempt->payment_intent_id);
        $this->assertNotNull($attempt->consent_public_id);
        $payment = ClientInvoicePayment::query()->sole();
        $this->assertSame([$attempt->payment_intent_id, 'pending', 375_000, 'stripe'], [$payment->provider_payment_identifier, $payment->status, $payment->amount, $payment->provider]);
        // Not paid until Stripe's own event says so: no parallel bookkeeping.
        $this->assertSame(375_000, $invoice->fresh()->balance_amount);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.autopay_submitted')->count());
    }

    public function test_a_second_run_never_charges_the_same_invoice_again(): void
    {
        $this->dueInvoice();
        $this->fakeAutopayStripe();
        $service = app(AutopayChargeService::class);

        $service->run(50, true);
        $again = $service->run(50, true);

        $this->assertSame([], $again);
        $this->assertCount(1, $this->autopayCharges());
        $this->assertSame(1, InvoiceAutopayAttempt::query()->count());
    }

    public function test_the_command_previews_by_default_and_charges_with_apply(): void
    {
        [, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe();

        Artisan::call('svc:billing:charge-autopay', ['--format' => 'json']);
        $preview = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($preview['dry_run']);
        $this->assertSame('would_charge', $preview['outcomes'][0]['result']);
        $this->assertSame($invoice->public_id, $preview['outcomes'][0]['invoice_id']);
        $this->assertSame([], $this->autopayStripeCalls);
        $this->assertSame(0, InvoiceAutopayAttempt::query()->count());

        Artisan::call('svc:billing:charge-autopay', ['--apply' => true, '--format' => 'json']);
        $applied = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($applied['dry_run']);
        $this->assertSame('submitted', $applied['outcomes'][0]['result']);
        $this->assertCount(1, $this->autopayCharges());
    }

    public function test_the_command_is_scheduled_every_fifteen_minutes_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(static fn ($event): bool => str_contains((string) $event->command, 'svc:billing:charge-autopay'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertStringContainsString('--apply', (string) $event->command);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_limit_is_inclusive_and_an_invoice_over_it_is_left_alone(): void
    {
        [, , $invoice] = $this->dueInvoice(balance: 500_000, limit: 500_000);
        $this->fakeAutopayStripe();
        $this->assertSame(AutopayChargeResult::Submitted, app(AutopayChargeService::class)->run(50, true)[0]->result);

        $invoice->forceFill(['balance_amount' => 500_001])->save();
        InvoiceAutopayAttempt::query()->delete();
        ClientInvoicePayment::query()->delete();
        $this->autopayStripeCalls = [];
        $this->assertSame([], app(AutopayChargeService::class)->run(50, true));
        $this->assertSame([], $this->autopayStripeCalls);
        // Left payable by hand: no attempt recorded, nothing failed.
        $this->assertSame(0, InvoiceAutopayAttempt::query()->count());
    }

    public function test_an_invoice_not_yet_due_in_the_workspace_calendar_waits_for_its_day(): void
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->autopayInvoice($company);
        $this->fakeAutopayStripe();

        // 22:00 on Nov 30 in New York, but already Dec 1 in UTC.
        $this->travelTo('2030-12-01 03:00:00');
        $this->assertSame([], app(AutopayChargeService::class)->run(50, true));
        // 05:00 UTC is midnight on Dec 1 in New York.
        $this->travelTo('2030-12-01 05:00:00');
        $this->assertCount(1, app(AutopayChargeService::class)->run(50, true));
    }

    public function test_an_invoice_that_fell_due_before_autopay_was_turned_on_is_not_charged(): void
    {
        $this->travelTo('2030-12-05 18:00:00');
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->autopayInvoice($company, due: '2030-12-01');
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->fakeAutopayStripe();

        $this->assertSame([], app(AutopayChargeService::class)->run(50, true));
    }

    public function test_a_declined_card_is_one_failed_attempt_and_is_never_retried(): void
    {
        [, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe(static fn (): array => [['error' => [
            'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds',
            'message' => 'Your card has insufficient funds.', 'payment_intent' => ['id' => 'pi_synthetic_declined', 'object' => 'payment_intent'],
        ]], 402]);
        $service = app(AutopayChargeService::class);

        $outcomes = $service->run(50, true);

        $this->assertSame(AutopayChargeResult::Failed, $outcomes[0]->result);
        $attempt = InvoiceAutopayAttempt::query()->sole();
        $this->assertSame(AutopayAttemptState::Failed, $attempt->state);
        $this->assertSame('card_declined', $attempt->failure_code);
        $this->assertSame('Your card has insufficient funds.', $attempt->failure_reason);
        $this->assertSame('pi_synthetic_declined', $attempt->payment_intent_id);
        // The failure joins the ordinary payment list; the invoice stays collectible.
        $payment = ClientInvoicePayment::query()->sole();
        $this->assertSame(['failed', 'pi_synthetic_declined'], [$payment->status, $payment->provider_payment_identifier]);
        $this->assertSame(['issued', 375_000], [$invoice->fresh()->status, $invoice->fresh()->balance_amount]);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.autopay_failed')->count());

        $this->assertSame([], $service->run(50, true));
        $this->assertCount(1, $this->autopayCharges());
    }

    public function test_a_payment_that_needs_the_client_is_a_failed_attempt_and_its_intent_is_cancelled(): void
    {
        $this->dueInvoice();
        $this->fakeAutopayStripe(static fn (string $method, string $path): array => str_ends_with($path, '/cancel')
            ? [['id' => 'pi_synthetic_auth', 'object' => 'payment_intent', 'status' => 'canceled']]
            : [['id' => 'pi_synthetic_auth', 'object' => 'payment_intent', 'status' => 'requires_action']]);

        $this->assertSame(AutopayChargeResult::Failed, app(AutopayChargeService::class)->run(50, true)[0]->result);

        $attempt = InvoiceAutopayAttempt::query()->sole();
        $this->assertSame(['failed', 'authentication_required'], [$attempt->state->value, $attempt->failure_code]);
        $this->assertContains('/v1/payment_intents/pi_synthetic_auth/cancel', array_column($this->autopayStripeCalls, 'path'));
    }

    public function test_an_authentication_required_card_error_is_a_failed_attempt(): void
    {
        $this->dueInvoice();
        $this->fakeAutopayStripe(static fn (): array => [['error' => [
            'type' => 'card_error', 'code' => 'authentication_required', 'message' => 'This payment requires authentication.',
        ]], 402]);

        $this->assertSame(AutopayChargeResult::Failed, app(AutopayChargeService::class)->run(50, true)[0]->result);
        $attempt = InvoiceAutopayAttempt::query()->sole();
        $this->assertSame(['failed', 'authentication_required', null], [$attempt->state->value, $attempt->failure_code, $attempt->payment_intent_id]);
        $this->assertSame(0, ClientInvoicePayment::query()->count());
    }

    public function test_an_unreadable_answer_leaves_a_claim_for_review_and_is_not_retried(): void
    {
        $this->dueInvoice();
        $this->fakeAutopayStripe(static fn (): array => [['error' => ['type' => 'api_error', 'message' => 'Synthetic outage.']], 500]);
        $service = app(AutopayChargeService::class);

        $this->assertSame(AutopayChargeResult::NeedsReview, $service->run(50, true)[0]->result);

        $attempt = InvoiceAutopayAttempt::query()->sole();
        $this->assertSame([AutopayAttemptState::Claimed, null], [$attempt->state, $attempt->payment_intent_id]);
        $this->assertSame([], $service->needingReview(), 'Too fresh to report.');
        $this->travel(11)->minutes();
        $this->assertSame([$attempt->id], array_map(static fn (InvoiceAutopayAttempt $row): int => $row->id, $service->needingReview()));
        $calls = count($this->autopayStripeCalls);

        $this->assertSame([], $service->run(50, true));
        $this->assertCount($calls, $this->autopayStripeCalls);

        Artisan::call('svc:billing:charge-autopay', ['--format' => 'json']);
        $this->assertSame($attempt->public_id, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['needs_review'][0]['attempt_id']);
    }

    public function test_a_claim_taken_by_another_run_stops_this_one_before_stripe(): void
    {
        [$workspace, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe();
        // The other run claims after this run's last read of the invoice (its payments) and before its own claim.
        $injected = false;
        DB::listen(function ($query) use (&$injected, $workspace, $invoice): void {
            if (! $injected && str_contains($query->sql, 'from "client_invoice_payments"')) {
                $injected = true;
                InvoiceAutopayAttempt::query()->create([
                    'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'client_company_id' => $invoice->client_company_id,
                    'state' => AutopayAttemptState::Claimed, 'claimed_at' => now(),
                ]);
            }
        });

        $outcomes = app(AutopayChargeService::class)->run(50, true);

        $this->assertSame(AutopayChargeResult::AlreadyClaimed, $outcomes[0]->result);
        $this->assertSame([], $this->autopayCharges());
        $this->assertSame(1, InvoiceAutopayAttempt::query()->where('client_invoice_id', $invoice->id)->count());
    }

    public function test_an_invoice_that_changes_after_the_claim_releases_it_and_sends_nothing(): void
    {
        [$workspace, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe();
        InvoiceAutopayAttempt::created(function (InvoiceAutopayAttempt $claim) use ($invoice): void {
            // A payment lands by hand between the claim and the charge.
            ClientInvoice::query()->whereKey($invoice->id)->update(['balance_amount' => 100_000]);
        });

        $outcomes = app(AutopayChargeService::class)->run(50, true);

        $this->assertSame(AutopayChargeResult::Released, $outcomes[0]->result);
        $this->assertSame([], $this->autopayCharges());
        $this->assertSame(0, InvoiceAutopayAttempt::query()->count(), 'The claim was given back: nothing was sent for it.');
        $this->assertSame($workspace->id, $invoice->workspace_id);
    }

    public function test_a_pending_payment_is_waited_for_not_doubled(): void
    {
        [, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe();
        app(InvoiceLifecycleService::class)->applyPayment($invoice, [
            'amount' => 375_000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_portal',
        ]);

        $this->assertSame([], app(AutopayChargeService::class)->run(50, true));
        $this->assertSame([], $this->autopayCharges());
    }

    public function test_stripes_own_success_settles_the_invoice_through_the_ordinary_ledger(): void
    {
        [$workspace, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe();
        app(AutopayChargeService::class)->run(50, true);
        $payment = ClientInvoicePayment::query()->sole();

        // What payment_intent.succeeded does.
        app(InvoiceLifecycleService::class)->setPaymentStatus($payment, 'succeeded', $workspace);

        $this->assertSame(['paid', 0], [$invoice->fresh()->status, $invoice->fresh()->balance_amount]);
        $this->assertSame([], app(AutopayChargeService::class)->run(50, true));
        $this->assertSame(1, ClientInvoicePayment::query()->count());
    }

    public function test_the_limit_of_a_run_bounds_how_many_are_charged(): void
    {
        [$workspace, $company] = $this->dueInvoice();
        $this->autopayInvoice($company, 'INV-0143');
        $this->autopayInvoice($company, 'INV-0144');
        $this->fakeAutopayStripe();

        $this->assertCount(2, app(AutopayChargeService::class)->run(2, true));
        $this->assertCount(1, app(AutopayChargeService::class)->run(2, true));
        $this->assertSame(3, InvoiceAutopayAttempt::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_an_over_limit_invoice_at_the_head_of_the_queue_does_not_starve_the_ones_behind_it(): void
    {
        [$workspace, $company, $big] = $this->dueInvoice(balance: 900_000);
        $this->autopayInvoice($company, 'INV-0150');
        $this->fakeAutopayStripe();

        $outcomes = app(AutopayChargeService::class)->run(1, true);

        $this->assertCount(1, $outcomes);
        $this->assertSame('INV-0150', $outcomes[0]->invoiceNumber);
        $this->assertSame(0, InvoiceAutopayAttempt::query()->where('client_invoice_id', $big->id)->count());
        $this->assertSame(1, ClientAutopayConsent::query()->where('workspace_id', $workspace->id)->count());
    }
}
