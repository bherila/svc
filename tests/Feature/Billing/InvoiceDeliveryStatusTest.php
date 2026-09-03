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

        foreach (['hard_bounce', 'opened'] as $event) {
            $this->postJson(self::URL, [
                'event' => $event,
                'message-id' => 'synthetic-message-id-2',
            ], ['X-Webhook-Token' => 'synthetic-webhook-token'])->assertOk();
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

    private function delivery(string $reference): ClientInvoiceEmailDelivery
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
