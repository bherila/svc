<?php

namespace Tests\Feature\Billing\Autopay;

use App\Exceptions\AutopayVersionConflict;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\InvoiceAutopayAttempt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayChargeService;
use App\Services\Billing\Autopay\AutopayResolutionService;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AutopayPresenter;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayChargeResult;
use App\Support\Billing\Autopay\AutopayInvoiceState;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsAutopay;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * Closing out an autopay attempt held for review (#641): after a person has
 * checked the Stripe dashboard they record that it charged (with the
 * PaymentIntent, which is read back and checked) or that it did not. It never
 * sends a charge, is workspace-scoped, and only one resolver wins.
 */
final class AutopayResolutionTest extends TestCase
{
    use BuildsAutopay;
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'clients:read', 'billing:read', 'billing:write'];

    private const string INTENT = 'pi_synthetic_held_1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://svc.example.test', 'agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->restoreAutopayStripe();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    /**
     * A due invoice whose autopay claim Stripe never answered, eleven minutes ago.
     *
     * @return array{0: Workspace, 1: User, 2: ClientCompany, 3: ClientInvoice, 4: InvoiceAutopayAttempt}
     */
    private function heldAttempt(string $slug = 'autopay', string $number = 'INV-0142'): array
    {
        Date::setTestNow('2030-10-03 18:00:00');
        [$workspace, $owner, $company, $contact] = $this->autopayFixture($slug);
        $this->turnAutopayOn($workspace, $company, $contact, 500_000);
        $invoice = $this->autopayInvoice($company, $number);
        Date::setTestNow('2030-12-01 15:00:00');
        $this->fakeAutopayStripe(static fn (): array => [['error' => ['type' => 'api_error', 'message' => 'Synthetic outage.']], 500]);
        $outcomes = app(AutopayChargeService::class)->run(50, true);
        $this->assertSame(AutopayChargeResult::NeedsReview, $outcomes[0]->result);
        Date::setTestNow('2030-12-01 15:11:00');

        return [$workspace, $owner, $company->refresh(), $invoice, InvoiceAutopayAttempt::query()->where('client_invoice_id', $invoice->id)->sole()];
    }

    /**
     * Stripe answering a read of the intent with this intent, adjusted.
     *
     * @param  array<string, mixed>  $override
     */
    private function stripeKnows(InvoiceAutopayAttempt $attempt, ClientInvoice $invoice, Workspace $workspace, array $override = []): void
    {
        $this->autopayStripeCalls = [];
        $this->fakeAutopayStripe(function (string $method, string $path) use ($attempt, $invoice, $workspace, $override): array {
            if (strtolower($method) === 'get' && str_starts_with($path, '/v1/payment_intents/')) {
                return [array_replace([
                    'id' => basename($path), 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 375_000, 'currency' => 'usd',
                    'metadata' => ['invoice_public_id' => $invoice->public_id, 'workspace_public_id' => $workspace->public_id, 'autopay_attempt_public_id' => $attempt->public_id],
                ], $override)];
            }

            return [['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent.']], 404];
        });
    }

    /** @return array<string, mixed> */
    private function chargedBody(ClientInvoice $invoice, string $intent = self::INTENT): array
    {
        return ['expected_version' => AgentApiVersion::for($invoice->refresh()), 'outcome' => 'charged', 'payment_intent_id' => $intent, 'confirm' => true];
    }

    /** @return array<string, mixed> */
    private function notChargedBody(ClientInvoice $invoice, string $note = 'Checked the Stripe dashboard: no payment for INV-0142.'): array
    {
        return ['expected_version' => AgentApiVersion::for($invoice->refresh()), 'outcome' => 'not_charged', 'note' => $note, 'confirm' => true];
    }

    #[DataProvider('transports')]
    public function test_charged_reads_the_intent_from_stripe_and_enters_it_in_the_ledger_for_the_webhook_to_settle(string $transport): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->stripeKnows($attempt, $invoice, $workspace, ['status' => 'processing']);
        $read = $this->success($transport, 'invoices.autopay.get', $workspace, ['invoice_id' => $invoice->public_id]);
        $this->assertSame(['needs_review', true], [$read['data']['state'], $read['data']['can_resolve']]);

        $done = $this->success($transport, 'invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->chargedBody($invoice) + ['note' => 'Dashboard shows the payment as processing.'], 'synthetic-resolve-charged');

        // Stripe was only read: the one call is a GET of the intent, and nothing was created.
        $this->assertSame([['get', '/v1/payment_intents/'.self::INTENT]], array_map(static fn (array $call): array => [strtolower($call['method']), $call['path']], $this->autopayStripeCalls));
        $this->assertSame([], $this->autopayCharges());
        $attempt->refresh();
        $this->assertSame([AutopayAttemptState::Submitted, self::INTENT, $owner->id, 'Dashboard shows the payment as processing.'], [$attempt->state, $attempt->payment_intent_id, $attempt->resolved_by_user_id, $attempt->resolution_note]);
        $this->assertSame('2030-12-01 15:11:00', $attempt->resolved_at?->format('Y-m-d H:i:s'));
        $payment = ClientInvoicePayment::query()->sole();
        $this->assertSame([self::INTENT, 'pending', 375_000, 'stripe', 'autopay:'.$attempt->public_id], [$payment->provider_payment_identifier, $payment->status, $payment->amount, $payment->provider, $payment->idempotency_key]);
        $this->assertSame(375_000, $invoice->fresh()->balance_amount, 'Not paid until Stripe\'s own event says so.');
        $activity = ClientCompanyActivity::query()->where('action', 'invoice.autopay_resolved')->sole();
        $this->assertSame(['charged', self::INTENT, $owner->id], [$activity->payload['outcome'], $activity->payload['payment_intent_id'], $activity->actor_user_id]);
        $this->assertSame(['processing', 'Sam Example', 'Dashboard shows the payment as processing.', false], [$done['data']['state'], $done['data']['attempt']['resolved_by_name'], $done['data']['attempt']['resolution_note'], $done['data']['can_resolve']]);
    }

    public function test_a_succeeded_intent_enters_the_ledger_as_succeeded_and_the_invoice_is_paid(): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->stripeKnows($attempt, $invoice, $workspace);

        $done = $this->success('rest', 'invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->chargedBody($invoice), 'synthetic-resolve-succeeded');

        $this->assertSame('succeeded', ClientInvoicePayment::query()->sole()->status);
        $this->assertSame(['paid', 0], [$invoice->fresh()->status, $invoice->fresh()->balance_amount]);
        $this->assertSame('charged', $done['data']['state']);
        $this->assertNull($done['data']['attempt']['resolution_note']);
    }

    #[DataProvider('transports')]
    public function test_not_charged_closes_the_attempt_and_leaves_the_invoice_for_manual_collection(string $transport): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->autopayStripeCalls = [];

        $done = $this->success($transport, 'invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->notChargedBody($invoice), 'synthetic-resolve-not-charged');

        $this->assertSame([], $this->autopayStripeCalls, 'Recording that nothing was charged needs no call to Stripe.');
        $attempt->refresh();
        $this->assertSame([AutopayAttemptState::NotCharged, null, $owner->id, 'Checked the Stripe dashboard: no payment for INV-0142.'], [$attempt->state, $attempt->payment_intent_id, $attempt->resolved_by_user_id, $attempt->resolution_note]);
        $this->assertNotNull($attempt->resolved_at);
        $this->assertSame(0, ClientInvoicePayment::query()->count());
        $this->assertSame(['issued', 375_000], [$invoice->fresh()->status, $invoice->fresh()->balance_amount]);
        $this->assertSame([AutopayInvoiceState::ResolvedNotCharged->value, 'Not autopaid: left for manual collection', false, 'Sam Example', 'not_charged'], [$done['data']['state'], $done['data']['state_label'], $done['data']['can_resolve'], $done['data']['attempt']['resolved_by_name'], $done['data']['attempt']['state']]);
        $this->assertSame('not_charged', ClientCompanyActivity::query()->where('action', 'invoice.autopay_resolved')->sole()->payload['outcome']);

        // One attempt per invoice still holds: no run claims or charges it again, and it cannot be resolved twice.
        $this->assertSame([], app(AutopayChargeService::class)->run(50, true));
        $this->assertSame([], $this->autopayCharges());
        $this->assertSame(1, InvoiceAutopayAttempt::query()->count());
        $this->failure($transport, 'invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->notChargedBody($invoice, 'A second look.'), 'synthetic-resolve-again', 422);
        $this->assertSame('Checked the Stripe dashboard: no payment for INV-0142.', $attempt->refresh()->resolution_note);
    }

    public function test_the_same_idempotency_key_replays_without_a_second_write_or_a_new_stripe_read(): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->stripeKnows($attempt, $invoice, $workspace, ['status' => 'processing']);
        $body = $this->chargedBody($invoice);

        $this->success('rest', 'invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-resolve-replay');
        $reads = count($this->autopayStripeCalls);
        $replay = $this->success('rest', 'invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-resolve-replay');

        $this->assertSame($reads, count($this->autopayStripeCalls), 'The attempt is no longer held, so the replay has nothing to read.');
        $this->assertSame(1, ClientInvoicePayment::query()->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.autopay_resolved')->count());
        $this->assertSame('processing', $replay['data']['state']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function mismatches(): iterable
    {
        yield 'an intent of another invoice' => [['metadata' => ['invoice_public_id' => '00000000-0000-4000-8000-0000000000aa', 'workspace_public_id' => 'ws']], 'not created for this invoice'];
        yield 'an intent of another amount' => [['amount' => 100], 'not for the amount'];
        yield 'an intent of another currency' => [['currency' => 'eur'], 'not in the currency'];
        yield 'an intent that did not charge' => [['status' => 'requires_payment_method'], 'has not charged the client'];
        yield 'a canceled intent' => [['status' => 'canceled'], 'has not charged the client'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('mismatches')]
    public function test_an_intent_that_is_not_this_attempts_charge_is_refused_and_nothing_is_recorded(array $override, string $reason): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);
        if (isset($override['metadata'])) {
            $override['metadata']['workspace_public_id'] = $workspace->public_id;
        }
        $this->stripeKnows($attempt, $invoice, $workspace, $override);

        $response = $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->chargedBody($invoice), 'synthetic-resolve-mismatch');

        $response->assertStatus(422);
        $this->assertStringContainsString($reason, (string) $response->json('errors.payment_intent_id.0'));
        $this->assertSame([AutopayAttemptState::Claimed, null, null], [$attempt->refresh()->state, $attempt->payment_intent_id, $attempt->resolved_by_user_id]);
        $this->assertSame(0, ClientInvoicePayment::query()->count());
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.autopay_resolved')->count());
    }

    public function test_an_intent_of_another_workspace_or_attempt_is_refused(): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);

        $this->stripeKnows($attempt, $invoice, $workspace, ['metadata' => ['invoice_public_id' => $invoice->public_id, 'workspace_public_id' => '00000000-0000-4000-8000-0000000000bb']]);
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->chargedBody($invoice), 'synthetic-resolve-other-workspace')
            ->assertStatus(422)->assertJsonPath('errors.payment_intent_id.0', 'That PaymentIntent does not belong to this workspace.');

        $this->stripeKnows($attempt, $invoice, $workspace, ['metadata' => ['invoice_public_id' => $invoice->public_id, 'workspace_public_id' => $workspace->public_id, 'autopay_attempt_public_id' => '00000000-0000-4000-8000-0000000000cc']]);
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->chargedBody($invoice), 'synthetic-resolve-other-attempt')
            ->assertStatus(422)->assertJsonPath('errors.payment_intent_id.0', 'That PaymentIntent belongs to a different autopay attempt.');

        $this->assertSame(AutopayAttemptState::Claimed, $attempt->refresh()->state);
        $this->assertSame(0, ClientInvoicePayment::query()->count());
    }

    public function test_an_intent_stripe_does_not_know_is_refused_in_words(): void
    {
        [$workspace, $owner, , $invoice] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->fakeAutopayStripe(static fn (): array => [['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent.']], 404]);

        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->chargedBody($invoice, 'pi_synthetic_unknown'), 'synthetic-resolve-unknown')
            ->assertStatus(422)->assertJsonPath('errors.payment_intent_id.0', 'Stripe has no PaymentIntent with that id in this workspace\'s account.');

        $this->fakeAutopayStripe(static fn (): array => [['error' => ['type' => 'api_error', 'message' => 'Synthetic outage.']], 500]);
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->chargedBody($invoice), 'synthetic-resolve-outage')
            ->assertStatus(422);
        $this->assertSame(0, ClientInvoicePayment::query()->count());
    }

    #[DataProvider('transports')]
    public function test_the_request_must_name_the_outcome_with_its_evidence_and_an_explicit_confirmation(string $transport): void
    {
        [$workspace, $owner, , $invoice] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->autopayStripeCalls = [];
        $charged = $this->chargedBody($invoice);
        $notCharged = $this->notChargedBody($invoice);
        $ids = ['invoice_id' => $invoice->public_id];

        $bodies = [
            'unconfirmed' => [...$charged, 'confirm' => false],
            'charged without the id' => array_diff_key($charged, ['payment_intent_id' => 1]),
            'a malformed id' => [...$charged, 'payment_intent_id' => 'ch_synthetic'],
            'charged with a stray note too short' => [...$charged, 'note' => 'x'],
            'not charged without the note' => array_diff_key($notCharged, ['note' => 1]),
            'not charged with a blank note' => [...$notCharged, 'note' => '   '],
            'not charged with an id' => [...$notCharged, 'payment_intent_id' => self::INTENT],
            'an unknown outcome' => [...$charged, 'outcome' => 'refunded'],
        ];
        $n = 0;
        foreach ($bodies as $label => $body) {
            $this->failure($transport, 'invoices.autopay.resolve', $workspace, $ids, $body, 'synthetic-resolve-bad-'.$n++, 422, $label);
        }
        $this->assertSame([AutopayAttemptState::Claimed, 0], [InvoiceAutopayAttempt::query()->sole()->state, ClientInvoicePayment::query()->count()]);
        $this->assertSame([], $this->autopayCharges());
    }

    public function test_a_stale_version_is_a_conflict(): void
    {
        [$workspace, $owner, , $invoice] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);
        $body = $this->notChargedBody($invoice);
        ClientInvoice::query()->whereKey($invoice->id)->increment('lock_version');

        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-resolve-stale')->assertStatus(409);

        $this->assertSame(AutopayAttemptState::Claimed, InvoiceAutopayAttempt::query()->sole()->state);
    }

    public function test_only_an_attempt_held_for_review_can_be_closed_out(): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();
        $this->actingAsMcp($owner, self::SCOPES);
        $service = app(AutopayResolutionService::class);

        // Too fresh: the run that claimed it may still be talking to Stripe.
        $attempt->forceFill(['claimed_at' => now()->subMinutes(9)])->save();
        $this->assertFalse($service->resolvable($attempt->refresh()));
        $this->assertFalse(app(AutopayPresenter::class)->invoice($workspace, $invoice)['can_resolve']);
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->notChargedBody($invoice), 'synthetic-resolve-fresh')
            ->assertStatus(422)->assertSee('may still be in progress');
        $attempt->forceFill(['claimed_at' => now()->subMinutes(10)])->save();
        $this->assertTrue($service->resolvable($attempt->refresh()), 'Ten minutes is the line the run reports it at.');

        foreach ([
            AutopayAttemptState::Failed->value => 'already failed',
            AutopayAttemptState::Skipped->value => 'was skipped',
            AutopayAttemptState::NotCharged->value => 'already closed out',
        ] as $state => $words) {
            $attempt->forceFill(['state' => $state])->save();
            $this->assertFalse($service->resolvable($attempt->refresh()));
            $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->notChargedBody($invoice), 'synthetic-resolve-'.$state)
                ->assertStatus(422)->assertSee($words);
        }
        $attempt->forceFill(['state' => AutopayAttemptState::Claimed->value, 'payment_intent_id' => 'pi_synthetic_known'])->save();
        $this->assertFalse($service->resolvable($attempt->refresh()));
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->notChargedBody($invoice), 'synthetic-resolve-known')
            ->assertStatus(422)->assertSee('already has a PaymentIntent');
        $attempt->delete();
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->notChargedBody($invoice), 'synthetic-resolve-none')
            ->assertStatus(422)->assertSee('has not tried to charge');
    }

    public function test_when_two_resolvers_race_only_the_first_write_lands(): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();
        $other = User::factory()->create(['name' => 'Alex Example']);
        $workspace->memberships()->create(['user_id' => $other->id, 'role' => 'admin']);
        $service = app(AutopayResolutionService::class);
        // The second person closes the attempt after this one has read it and before it writes.
        $reads = 0;
        $busy = false;
        DB::listen(function ($query) use (&$reads, &$busy, $attempt, $other): void {
            if ($busy || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'invoice_autopay_attempts')) {
                return;
            }
            if (++$reads === 1) {
                $busy = true;
                DB::table('invoice_autopay_attempts')->where('id', $attempt->id)->update(['state' => 'not_charged', 'resolved_by_user_id' => $other->id, 'resolution_note' => 'Closed first.']);
                $busy = false;
            }
        });

        try {
            $service->recordNotCharged($workspace, $invoice, $owner, 'Closed second.');
            $this->fail('The second resolver should have been refused.');
        } catch (AutopayVersionConflict $conflict) {
            $this->assertStringContainsString('closed out by someone else first', $conflict->getMessage());
        }

        $attempt->refresh();
        $this->assertSame([AutopayAttemptState::NotCharged, $other->id, 'Closed first.'], [$attempt->state, $attempt->resolved_by_user_id, $attempt->resolution_note]);
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.autopay_resolved')->count());
    }

    public function test_a_charge_that_lands_while_the_intent_is_being_checked_cannot_be_overwritten_or_doubled(): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();
        $this->stripeKnows($attempt, $invoice, $workspace);
        $service = app(AutopayResolutionService::class);
        $verified = $service->verify($workspace, $invoice, self::INTENT);
        $this->assertNotNull($verified);

        // Meanwhile the original run's answer arrives late and settles the attempt.
        DB::table('invoice_autopay_attempts')->where('id', $attempt->id)->update(['state' => 'submitted', 'payment_intent_id' => self::INTENT]);

        try {
            $service->recordCharged($workspace, $invoice, $owner, $verified, null);
            $this->fail('The attempt was no longer held.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('already has a PaymentIntent', $refusal->getMessage());
        }
        $this->assertSame(0, ClientInvoicePayment::query()->count(), 'Nothing was entered in the ledger twice.');
    }

    public function test_verify_checks_the_format_and_reads_nothing_for_an_attempt_that_is_not_held(): void
    {
        [$workspace, , , $invoice, $attempt] = $this->heldAttempt();
        $this->stripeKnows($attempt, $invoice, $workspace);
        $service = app(AutopayResolutionService::class);

        try {
            $service->verify($workspace, $invoice, 'not-an-intent');
            $this->fail('A malformed id is refused before Stripe is asked.');
        } catch (DomainException) {
            $this->assertSame([], $this->autopayStripeCalls);
        }
        $attempt->forceFill(['state' => AutopayAttemptState::Failed->value])->save();
        $this->assertNull($service->verify($workspace, $invoice, self::INTENT));
        $this->assertSame([], $this->autopayStripeCalls);
    }

    public function test_a_workspace_without_stripe_cannot_check_an_intent(): void
    {
        [$workspace, , , $invoice] = $this->heldAttempt();
        config(['services.stripe.secret_key' => null]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Stripe is not connected for this workspace');

        app(AutopayResolutionService::class)->verify($workspace, $invoice, self::INTENT);
    }

    public function test_it_is_withheld_from_non_managers_and_from_other_workspaces_and_changes_nothing(): void
    {
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt('one');
        [$other, $otherOwner, , $otherInvoice, $otherAttempt] = $this->heldAttempt('two', 'INV-0900');
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->stripeKnows($attempt, $invoice, $workspace);

        $this->actingAsMcp($member, self::SCOPES);
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->notChargedBody($invoice), 'synthetic-resolve-member')->assertForbidden();
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->chargedBody($invoice), 'synthetic-resolve-member-charged')->assertForbidden();
        $this->assertSame([], $this->autopayStripeCalls, 'A refused caller cannot make SVC read Stripe.');

        // The other workspace's owner names this workspace's invoice through their own workspace, and the reverse.
        $this->actingAsMcp($otherOwner, self::SCOPES);
        $this->rest('invoices.autopay.resolve', $other, ['invoice_id' => $invoice->public_id], $this->notChargedBody($invoice), 'synthetic-resolve-cross')->assertNotFound();
        $this->rest('invoices.autopay.resolve', $other, ['invoice_id' => $invoice->public_id], $this->chargedBody($invoice), 'synthetic-resolve-cross-charged')->assertNotFound();
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $invoice->public_id], $this->notChargedBody($invoice), 'synthetic-resolve-cross-ws')->assertStatus(404);
        $this->actingAsMcp($owner, self::SCOPES);
        $this->rest('invoices.autopay.resolve', $workspace, ['invoice_id' => $otherInvoice->public_id], $this->notChargedBody($otherInvoice), 'synthetic-resolve-cross-invoice')->assertNotFound();
        $this->assertSame([], $this->autopayStripeCalls);

        $this->assertSame([AutopayAttemptState::Claimed, AutopayAttemptState::Claimed], [$attempt->refresh()->state, $otherAttempt->refresh()->state]);
        $this->assertSame(0, ClientInvoicePayment::query()->count());

        // The service itself is scoped too: the other workspace has no attempt for this invoice, so there is nothing to read or write.
        $service = app(AutopayResolutionService::class);
        $this->assertNull($service->verify($other, $invoice, self::INTENT));
        $this->assertSame([], $this->autopayStripeCalls);
        $this->expectException(ModelNotFoundException::class);
        $service->recordNotCharged($other, $invoice, $otherOwner, 'Wrong workspace.');
    }

    public function test_the_website_closes_out_an_attempt_through_the_same_operation(): void
    {
        config(['agent_api.writes_enabled' => false, 'agent_api.invoice_writes_enabled' => false]);
        [$workspace, $owner, , $invoice, $attempt] = $this->heldAttempt();

        $this->actingAs($owner)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str_repeat('a', 40))
            ->withHeader('Idempotency-Key', 'synthetic-website-resolve')
            ->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/autopay/resolve", $this->notChargedBody($invoice))
            ->assertOk()
            ->assertJsonPath('data.state', 'resolved_not_charged');

        $this->assertSame(AutopayAttemptState::NotCharged, $attempt->refresh()->state);
    }

    public function test_the_invoice_page_offers_the_action_only_for_a_held_attempt_and_links_the_payments_settings(): void
    {
        [$workspace, $owner, $company, $invoice, $attempt] = $this->heldAttempt();

        $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $invoice], absolute: false))
            ->assertInertia(fn ($page) => $page
                ->where('autopay.state', 'needs_review')
                ->where('autopay.resolve_href', route('agent-api.v1.invoices.autopay.resolve', [$workspace, $invoice->public_id], absolute: false))
                ->where('autopay.payments_href', route('workspaces.payments', [$workspace], absolute: false)));

        $attempt->forceFill(['state' => AutopayAttemptState::NotCharged->value, 'resolved_at' => now(), 'resolved_by_user_id' => $owner->id, 'resolution_note' => 'Checked.'])->save();
        $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $invoice], absolute: false))
            ->assertInertia(fn ($page) => $page
                ->where('autopay.state', 'resolved_not_charged')
                ->where('autopay.resolve_href', null)
                ->where('autopay.attempt.resolved_by_name', 'Sam Example'));
    }

    /**
     * @param  array<string, string>  $ids
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function success(string $transport, string $operation, Workspace $workspace, array $ids, array $body = [], ?string $key = null): array
    {
        $response = $this->send($transport, $operation, $workspace, $ids, $body, $key);
        if ($response instanceof TestResponse) {
            $result = $response->assertOk()->json();
        } else {
            $this->assertFalse($response['result']['isError'] ?? true, json_encode($response, JSON_THROW_ON_ERROR));
            $result = $response['result']['structuredContent'];
        }
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($result, AgentApiResponseSchemaCatalog::forOperation($operation)), json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    /**
     * @param  array<string, string>  $ids
     * @param  array<string, mixed>  $body
     */
    private function failure(string $transport, string $operation, Workspace $workspace, array $ids, array $body, string $key, int $status, string $label = ''): void
    {
        $response = $this->send($transport, $operation, $workspace, $ids, $body, $key);
        if ($response instanceof TestResponse) {
            $response->assertStatus($status);

            return;
        }
        $this->assertTrue(($response['result']['isError'] ?? false) || isset($response['error']), $label.': '.json_encode($response, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, string>  $ids
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>|array<string, mixed>
     */
    private function send(string $transport, string $operation, Workspace $workspace, array $ids, array $body = [], ?string $key = null): TestResponse|array
    {
        return $transport === 'mcp'
            ? $this->callTool($this->initialize(), $operation, ['workspace_id' => $workspace->public_id, ...$ids, ...$body, ...($key === null ? [] : ['idempotency_key' => $key])])
            : $this->rest($operation, $workspace, $ids, $body, $key);
    }

    /**
     * @param  array<string, string>  $ids
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function rest(string $operation, Workspace $workspace, array $ids, array $body = [], ?string $key = null): TestResponse
    {
        [$method, $path] = match ($operation) {
            'invoices.autopay.get' => ['GET', "invoices/{$ids['invoice_id']}/autopay"],
            'invoices.autopay.resolve' => ['POST', "invoices/{$ids['invoice_id']}/autopay/resolve"],
            default => throw new \LogicException("Unknown synthetic operation {$operation}."),
        };

        return $this->json($method, "/api/v1/workspaces/{$workspace->public_id}/{$path}", $body, $key === null ? [] : ['Idempotency-Key' => $key]);
    }
}
