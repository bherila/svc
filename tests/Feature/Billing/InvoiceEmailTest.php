<?php

namespace Tests\Feature\Billing;

use App\Mail\InvoiceMail;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Sending an invoice, and being told what happened.
 *
 * The behaviour these replace: "Send to client" dispatched a queued job onto
 * the database driver and answered "Invoice delivery queued." Nothing on the
 * deployment runs a worker, so the row sat in `jobs` unread and the delivery
 * stayed `pending` forever - while the screen said the encouraging thing. The
 * old test asserted a `sent` delivery and passed, because `QUEUE_CONNECTION` is
 * `sync` under PHPUnit and the job ran inline. A test environment that differs
 * from production on the single axis the feature depends on is not covering the
 * feature, so these assert the send itself rather than the dispatch.
 */
class InvoiceEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('svc.billing.invoices.send')) {
            require base_path('routes/billing.php');
        }
    }

    public function test_sending_delivers_the_message_and_records_what_was_sent(): void
    {
        Mail::fake();
        [$owner, $workspace, $invoice] = $this->issuedInvoice();

        $this->actingAs($owner)
            ->postJson($this->sendUrl($workspace, $invoice), [
                'recipients' => ['ap@synthetic.test'],
                'subject' => 'Your March invoice',
                'message' => "Hello,\n\nThe usual monthly work.",
                'bcc_self' => true,
            ])
            ->assertOk();

        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($owner): bool {
            return $mail->hasTo('ap@synthetic.test')
                && $mail->hasBcc($owner->email)
                && $mail->subjectLine === 'Your March invoice'
                && str_contains((string) $mail->note, 'The usual monthly work.');
        });

        $delivery = ClientInvoiceEmailDelivery::query()->sole();

        $this->assertSame('sent', $delivery->status);
        $this->assertSame(['ap@synthetic.test'], $delivery->recipients);
        $this->assertSame([$owner->email], $delivery->bcc);
        $this->assertSame('Your March invoice', $delivery->subject);
        // The words as well as the addresses: a dispute about an invoice is as
        // often about the covering note as the figures.
        $this->assertStringContainsString('The usual monthly work.', (string) $delivery->body);
        $this->assertNotNull($delivery->sent_at);
    }

    public function test_sending_without_a_choice_uses_the_clients_billing_address(): void
    {
        Mail::fake();
        [$owner, $workspace, $invoice] = $this->issuedInvoice();

        $this->actingAs($owner)->postJson($this->sendUrl($workspace, $invoice))->assertOk();

        Mail::assertSent(
            InvoiceMail::class,
            fn (InvoiceMail $mail): bool => $mail->hasTo('billing@synthetic.test'),
        );
    }

    public function test_the_sender_is_not_blind_copied_unless_they_asked(): void
    {
        Mail::fake();
        [$owner, $workspace, $invoice] = $this->issuedInvoice();

        $this->actingAs($owner)
            ->postJson($this->sendUrl($workspace, $invoice), ['bcc_self' => false])
            ->assertOk();

        $this->assertSame([], ClientInvoiceEmailDelivery::query()->sole()->bcc ?? []);
    }

    public function test_an_address_already_on_the_to_line_is_not_also_blind_copied(): void
    {
        Mail::fake();
        [$owner, $workspace, $invoice] = $this->issuedInvoice();

        $this->actingAs($owner)
            ->postJson($this->sendUrl($workspace, $invoice), [
                'recipients' => [$owner->email],
                'bcc_self' => true,
            ])
            ->assertOk();

        // Otherwise the sender receives the same message twice and cannot tell
        // which copy is which.
        $this->assertSame([], ClientInvoiceEmailDelivery::query()->sole()->bcc ?? []);
    }

    public function test_a_refused_message_is_reported_and_recorded_as_failed(): void
    {
        [$owner, $workspace, $invoice] = $this->issuedInvoice();

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new TransportException('535 auth failed for postmaster@synthetic.test'));

        $response = $this->actingAs($owner)
            ->postJson($this->sendUrl($workspace, $invoice))
            ->assertStatus(422);

        // The class of failure, never the transport's own words: those quote
        // addresses and sometimes credentials, and this reaches a screen.
        $this->assertStringContainsString('TransportException', $response->json('message'));
        $this->assertStringNotContainsString('535', $response->json('message'));

        $delivery = ClientInvoiceEmailDelivery::query()->sole();

        // The attempt survives the failure. A delivery row that rolled back
        // with the send would leave no evidence that anything was tried.
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->failed_at);
        $this->assertStringNotContainsString('535', (string) $delivery->error_summary);
    }

    public function test_a_draft_invoice_cannot_be_emailed(): void
    {
        Mail::fake();
        [$owner, $workspace, $company] = $this->tenant();
        $invoice = app(InvoiceLifecycleService::class)
            ->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);

        $this->actingAs($owner)
            ->postJson($this->sendUrl($workspace, $invoice))
            ->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertSame(0, ClientInvoiceEmailDelivery::query()->count());
    }

    public function test_a_member_who_cannot_manage_the_workspace_cannot_send_its_invoices(): void
    {
        Mail::fake();
        [, $workspace, $invoice] = $this->issuedInvoice();
        $viewer = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'viewer']);

        $this->actingAs($viewer)
            ->postJson($this->sendUrl($workspace, $invoice))
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    private function sendUrl(Workspace $workspace, ClientInvoice $invoice): string
    {
        return "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send";
    }

    /** @return array{0: User, 1: Workspace, 2: ClientInvoice} */
    private function issuedInvoice(): array
    {
        [$owner, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);

        return [$owner, $workspace, $invoice->fresh()];
    }

    /** @return array{0: User, 1: Workspace, 2: ClientCompany} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic Email Workspace',
            'slug' => 'synthetic-email-workspace-'.uniqid(),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Email Client',
            'slug' => 'synthetic-email-client-'.uniqid(),
            'billing_email' => 'billing@synthetic.test',
        ]);

        return [$owner, $workspace, $company];
    }

    /** @return array<string, mixed> */
    private function invoiceData(): array
    {
        return [
            'invoice_number' => 'INV-SYNTH-'.str()->upper(str()->random(8)),
            'currency' => 'USD',
            'issue_date' => '2026-08-15',
            'due_date' => '2026-09-14',
        ];
    }

    /** @return array<string, mixed> */
    private function line(): array
    {
        return [
            'type' => 'fee',
            'description' => 'Synthetic monthly retainer',
            'quantity' => 1,
            'unit_amount' => 150000,
            'total_amount' => 150000,
        ];
    }
}
