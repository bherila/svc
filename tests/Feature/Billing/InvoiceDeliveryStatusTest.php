<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * What the mail provider says became of an invoice email.
 *
 * Our own `status` can only report that the message left here. Delivered,
 * bounced, blocked and marked-as-spam are facts only Brevo learns, and they
 * arrive over a webhook Brevo does not sign - so most of what is asserted here
 * is about refusing callers rather than recording events.
 */
class InvoiceDeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/webhooks/brevo';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('svc.billing.brevo.webhook')) {
            require base_path('routes/billing.php');
        }

        config(['services.brevo.webhook_token' => 'synthetic-webhook-token']);
    }

    public function test_a_delivered_event_is_recorded_against_the_message_it_names(): void
    {
        $delivery = $this->delivery('synthetic-message-id-1');

        $this->postJson(self::URL, [
            'event' => 'delivered',
            'message-id' => 'synthetic-message-id-1',
            'email' => 'ap@synthetic.test',
            'ts_event' => 1788000000,
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
            ->assertOk()
            ->assertJsonPath('recorded', 1);

        $delivery->refresh();

        $this->assertSame('delivered', $delivery->provider_status);
        // Our own status is untouched. Conflating the two would let "sent" read
        // as "received", and an operator who believes that chases a client who
        // never got the invoice.
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1788000000, $delivery->provider_status_at?->getTimestamp());
    }

    public function test_a_padded_message_id_still_finds_its_delivery(): void
    {
        $delivery = $this->delivery('synthetic-message-id-padded');

        // The id is read back out of somebody else's JSON. Untrimmed it matches
        // nothing, and the delivery sits reading "not reported yet" forever
        // while the provider has already said what became of it.
        $this->postJson(self::URL, [
            'event' => 'delivered',
            'message-id' => "  synthetic-message-id-padded\n",
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
            ->assertOk()
            ->assertJsonPath('recorded', 1);

        $this->assertSame('delivered', $delivery->fresh()->provider_status);
    }

    public function test_a_batch_of_events_is_accepted_as_well_as_a_single_one(): void
    {
        $first = $this->delivery('synthetic-message-id-a');
        $second = $this->delivery('synthetic-message-id-b');

        $this->postJson(self::URL, [
            ['event' => 'delivered', 'message-id' => 'synthetic-message-id-a'],
            ['event' => 'hard_bounce', 'message-id' => 'synthetic-message-id-b'],
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
            ->assertOk()
            ->assertJsonPath('recorded', 2);

        $this->assertSame('delivered', $first->fresh()->provider_status);
        $this->assertSame('hard_bounce', $second->fresh()->provider_status);
    }

    public function test_a_later_harmless_event_does_not_overwrite_a_bounce(): void
    {
        $delivery = $this->delivery('synthetic-message-id-2');

        // The refused one is counted as not recorded, which is the only way a
        // reader of this response can tell the two outcomes apart.
        foreach (['hard_bounce' => 1, 'opened' => 0] as $event => $recorded) {
            $this->postJson(self::URL, [
                'event' => $event,
                'message-id' => 'synthetic-message-id-2',
            ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
                ->assertOk()
                ->assertJsonPath('recorded', $recorded);
        }

        // These arrive out of order - the provider retries - and a message that
        // hard-bounced did not arrive, whatever a scanner opened afterwards.
        $this->assertSame('hard_bounce', $delivery->fresh()->provider_status);
    }

    public function test_an_event_for_a_message_we_do_not_know_is_accepted_and_ignored(): void
    {
        $delivery = $this->delivery('synthetic-message-id-3');

        // This endpoint hears about every message the Brevo account has ever
        // sent. Answering with a failure would only make the provider retry
        // something we will never recognise.
        $this->postJson(self::URL, [
            'event' => 'delivered',
            'message-id' => 'a-message-from-some-other-application',
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
            ->assertOk()
            ->assertJsonPath('recorded', 0);

        $this->assertNull($delivery->fresh()->provider_status);
    }

    public function test_a_caller_with_no_token_is_refused(): void
    {
        $delivery = $this->delivery('synthetic-message-id-4');

        $this->postJson(self::URL, [
            'event' => 'hard_bounce',
            'message-id' => 'synthetic-message-id-4',
        ])->assertStatus(401);

        $this->assertNull($delivery->fresh()->provider_status);
    }

    public function test_a_caller_with_the_wrong_token_is_refused(): void
    {
        $delivery = $this->delivery('synthetic-message-id-5');

        $this->postJson(self::URL, [
            'event' => 'hard_bounce',
            'message-id' => 'synthetic-message-id-5',
        ], ['X-Webhook-Token' => 'not-the-token'])->assertStatus(401);

        $this->assertNull($delivery->fresh()->provider_status);
    }

    public function test_an_unconfigured_deployment_refuses_every_caller(): void
    {
        config(['services.brevo.webhook_token' => null]);
        $delivery = $this->delivery('synthetic-message-id-6');

        // The state a fresh deployment is in. Defaulting it to "admit anyone"
        // would turn the only guard this endpoint has into a formality.
        foreach ([null, '', 'anything'] as $presented) {
            $this->postJson(self::URL, [
                'event' => 'hard_bounce',
                'message-id' => 'synthetic-message-id-6',
            ], $presented === null ? [] : ['X-Webhook-Token' => $presented])
                ->assertStatus(401);
        }

        $this->assertNull($delivery->fresh()->provider_status);
    }

    public function test_an_event_of_equal_weight_replaces_the_one_before_it(): void
    {
        $delivery = $this->delivery('synthetic-message-id-equal');

        foreach (['delivered', 'opened'] as $event) {
            $this->postJson(self::URL, [
                'event' => $event,
                'message-id' => 'synthetic-message-id-equal',
            ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
                ->assertOk()
                ->assertJsonPath('recorded', 1);
        }

        // Only a *worse* earlier event holds its ground. These two weigh the
        // same, and the later one says more: the invoice was opened. Refusing
        // it would freeze the row on the first thing ever heard about it.
        $this->assertSame('opened', $delivery->fresh()->provider_status);
    }

    public function test_an_event_name_in_the_providers_own_casing_is_still_understood(): void
    {
        $delivery = $this->delivery('synthetic-message-id-cased');

        // Which events arrive is chosen from a list of labels in someone else's
        // console, and the casing that comes back is not ours to fix. An event
        // name we fail to recognise is dropped without a word.
        $this->postJson(self::URL, [
            'event' => 'Hard_Bounce',
            'message-id' => 'synthetic-message-id-cased',
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
            ->assertOk()
            ->assertJsonPath('recorded', 1);

        $this->assertSame('hard_bounce', $delivery->fresh()->provider_status);
    }

    public function test_an_event_naming_no_message_is_not_pinned_on_a_delivery_that_has_none(): void
    {
        // A delivery that has not reached the provider yet carries no
        // reference, and there is always at least one of those. Eloquent turns
        // `where($column, null)` into `where $column is null`, so those rows
        // are precisely what an event with no message id would match.
        $delivery = $this->delivery(null);

        $this->postJson(self::URL, [
            'event' => 'hard_bounce',
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
            ->assertOk()
            ->assertJsonPath('recorded', 0);

        $this->assertNull($delivery->fresh()->provider_status);
    }

    public function test_an_event_name_that_is_not_a_string_is_ignored_rather_than_fatal(): void
    {
        $delivery = $this->delivery('synthetic-message-id-shape');

        // The body is whatever was posted. Brevo sends `event` as a string, but
        // nothing about the shape is guaranteed and `trim()` on an array is a
        // TypeError - which is a 500, and a provider that retries it.
        $this->postJson(self::URL, [
            'event' => ['delivered'],
            'message-id' => 'synthetic-message-id-shape',
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
            ->assertOk()
            ->assertJsonPath('recorded', 0);

        $this->assertNull($delivery->fresh()->provider_status);
    }

    public function test_a_blank_message_id_falls_through_to_the_other_spelling(): void
    {
        $delivery = $this->delivery('synthetic-message-id-alt');

        // Brevo has posted this key both ways. A blank `message-id` beside a
        // populated `message_id` is what decides whether the fall-through is
        // real: taken untrimmed, the blank one reads as an answer and the
        // lookup goes out with an empty reference.
        $this->postJson(self::URL, [
            'event' => 'delivered',
            'message-id' => '   ',
            'message_id' => 'synthetic-message-id-alt',
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])
            ->assertOk()
            ->assertJsonPath('recorded', 1);

        $this->assertSame('delivered', $delivery->fresh()->provider_status);
    }

    public function test_a_timestamp_sent_as_a_string_of_digits_is_read_as_one(): void
    {
        $delivery = $this->delivery('synthetic-message-id-ts-string');

        // JSON has one number type and providers are inconsistent about which
        // side of the quotes they put a unix timestamp on. Refusing the quoted
        // form silently substitutes our clock for theirs.
        $this->postJson(self::URL, [
            'event' => 'delivered',
            'message-id' => 'synthetic-message-id-ts-string',
            'ts_event' => '1788000000',
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])->assertOk();

        $this->assertSame(1788000000, $delivery->fresh()->provider_status_at?->getTimestamp());
    }

    public function test_a_timestamp_we_cannot_read_falls_back_to_our_own_clock(): void
    {
        $delivery = $this->delivery('synthetic-message-id-ts-unreadable');

        // `(int) '2026-05-01T10:00:00Z'` is 2026, which is a January morning in
        // 1970. Reading a date we do not understand as a number is worse than
        // not reading it: our own clock is at least approximately right, and a
        // bounce dated 1970 sorts to the bottom of every screen that shows it.
        $this->postJson(self::URL, [
            'event' => 'delivered',
            'message-id' => 'synthetic-message-id-ts-unreadable',
            'ts_event' => '2026-05-01T10:00:00Z',
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])->assertOk();

        $recorded = $delivery->fresh()->provider_status_at;

        $this->assertNotNull($recorded);
        $this->assertGreaterThan(1700000000, $recorded->getTimestamp());
    }

    public function test_the_event_time_is_preferred_over_the_time_the_notice_was_assembled(): void
    {
        $delivery = $this->delivery('synthetic-message-id-ts-both');

        // Brevo sends both: `ts_event` is when the thing happened and `ts` is
        // when this notification was put together, and after a retry the two
        // are hours apart. "Bounced at" is about the bounce.
        $this->postJson(self::URL, [
            'event' => 'hard_bounce',
            'message-id' => 'synthetic-message-id-ts-both',
            'ts_event' => 1788000000,
            'ts' => 1788009999,
        ], ['X-Webhook-Token' => 'synthetic-webhook-token'])->assertOk();

        $this->assertSame(1788000000, $delivery->fresh()->provider_status_at?->getTimestamp());
    }

    private function delivery(?string $reference): ClientInvoiceEmailDelivery
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic Status Workspace',
            'slug' => 'synthetic-status-workspace-'.uniqid(),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Status Client',
            'slug' => 'synthetic-status-client-'.uniqid(),
        ]);

        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-SYNTH-'.str()->upper(str()->random(8)),
            'currency' => 'USD',
            'issue_date' => '2026-08-15',
            'due_date' => '2026-09-14',
        ], [[
            'type' => 'fee',
            'description' => 'Synthetic monthly retainer',
            'quantity' => 1,
            'unit_amount' => 150000,
            'total_amount' => 150000,
        ]]);
        $service->issue($invoice, $workspace);

        return ClientInvoiceEmailDelivery::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'recipients' => ['ap@synthetic.test'],
            'subject' => 'Invoice',
            'status' => 'sent',
            'provider_message_reference' => $reference,
        ]);
    }
}
