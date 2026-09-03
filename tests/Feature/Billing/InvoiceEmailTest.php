<?php

namespace Tests\Feature\Billing;

use App\Mail\InvoiceMail;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceEmailDraft;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
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

        // The exact sentence, not a substring of it. Asserting only that the
        // class name appears somewhere passes just as happily when the pieces
        // are in the wrong order or a fragment has fallen off - and this text
        // is the entire explanation an operator gets for a message that did
        // not go.
        $this->assertSame(
            'The mail server refused this message (TransportException). Nothing was sent.',
            $response->json('message'),
        );
        // Never the transport's own words: those quote the address it was
        // refused for and sometimes the credentials it used.
        $this->assertStringNotContainsString('535', $response->json('message'));

        $delivery = ClientInvoiceEmailDelivery::query()->sole();

        // The attempt survives the failure. A delivery row that rolled back
        // with the send would leave no evidence that anything was tried.
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->failed_at);
        $this->assertSame(
            'Email delivery failed (TransportException).',
            $delivery->error_summary,
        );
    }

    /**
     * Sending moves the invoice on twice, and the count is the assertion.
     *
     * Once when the attempt is registered and once when its outcome is known,
     * because an agent holding the version from before either is holding a
     * state that no longer describes the invoice. Asserting only that the
     * version changed proves nothing about the second: the first advance
     * already changed it, so the outcome could stop being recorded and the
     * test would still pass.
     *
     * @param  bool  $refused  whether the mailer rejects the message
     */
    #[DataProvider('sendOutcomes')]
    public function test_sending_advances_the_invoices_revision_twice(bool $refused): void
    {
        [$owner, $workspace, $invoice] = $this->issuedInvoice();
        $before = (int) $invoice->fresh()->lock_version;

        if ($refused) {
            Mail::shouldReceive('to')->once()->andThrow(new TransportException('refused'));
        } else {
            Mail::fake();
        }

        $this->actingAs($owner)
            ->postJson($this->sendUrl($workspace, $invoice))
            ->assertStatus($refused ? 422 : 200);

        $this->assertSame($before + 2, (int) $invoice->fresh()->lock_version);
        $this->assertNotSame($before, (int) $invoice->fresh()->lock_version);
    }

    /** @return array<string, array{0: bool}> */
    public static function sendOutcomes(): array
    {
        return ['delivered' => [false], 'refused' => [true]];
    }

    /**
     * What an invoice would be sent to if nobody chose.
     *
     * The client's billing address first, then the people who hold a login to
     * their portal. Suggested rather than imposed - the compose screen shows
     * them and the operator decides - but a wrong or missing suggestion is what
     * an operator will send on without reading.
     */
    public function test_the_suggested_recipients_are_the_billing_address_then_the_portal_users(): void
    {
        [, , $invoice] = $this->issuedInvoice();
        $company = $invoice->clientCompany;

        foreach ([['Ada Synthetic', 'ada@synthetic.test'], ['Bo Synthetic', 'bo@synthetic.test']] as [$name, $email]) {
            $company->portalUsers()->attach(
                User::factory()->create(['name' => $name, 'email' => $email]),
                ['role' => 'client'],
            );
        }

        $this->assertSame([
            ['email' => 'billing@synthetic.test', 'label' => 'Billing address'],
            ['email' => 'ada@synthetic.test', 'label' => 'Ada Synthetic'],
            ['email' => 'bo@synthetic.test', 'label' => 'Bo Synthetic'],
        ], app(InvoiceEmailService::class)->suggestedRecipients($invoice));
    }

    public function test_a_portal_user_at_the_billing_address_is_suggested_once(): void
    {
        [, , $invoice] = $this->issuedInvoice();
        $invoice->clientCompany->portalUsers()->attach(
            User::factory()->create(['name' => 'Ada Synthetic', 'email' => 'BILLING@synthetic.test']),
            ['role' => 'client'],
        );

        // The same address twice on one list is an operator's cue to remove one
        // and a chance to remove the wrong one. Matched case-insensitively,
        // because an address is not case-sensitive in the part that routes it.
        $this->assertSame(
            [['email' => 'billing@synthetic.test', 'label' => 'Billing address']],
            app(InvoiceEmailService::class)->suggestedRecipients($invoice),
        );
    }

    public function test_an_unusable_billing_address_is_not_suggested(): void
    {
        [, , $invoice] = $this->issuedInvoice();

        foreach (['', '   ', 'not-an-address'] as $stored) {
            $invoice->clientCompany->forceFill(['billing_email' => $stored])->save();

            // Offering one would put an address in the To line that the draft
            // then refuses, so the send fails on something the operator never
            // typed.
            $this->assertSame(
                [],
                app(InvoiceEmailService::class)->suggestedRecipients($invoice->fresh()),
                "A billing_email of [{$stored}] was suggested.",
            );
        }
    }

    public function test_the_default_subject_names_the_invoice(): void
    {
        [, , $invoice] = $this->issuedInvoice();

        $this->assertSame(
            'Invoice '.$invoice->invoice_number,
            app(InvoiceEmailService::class)->defaultSubject($invoice),
        );
    }

    /**
     * The deferred path refuses what the immediate one refuses.
     *
     * It registers a delivery and sends after the caller's transaction commits,
     * and by then there is nobody to report a refusal to - so the invoice has
     * to be checked before anything is written, not after.
     */
    public function test_registering_a_send_refuses_an_invoice_that_cannot_be_emailed(): void
    {
        [, $workspace, $company] = $this->tenant();
        $draft = app(InvoiceLifecycleService::class)
            ->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only collectible issued invoices can be emailed.');

        app(InvoiceEmailService::class)->sendAfterCommit(
            $draft,
            InvoiceEmailDraft::of(['ap@synthetic.test'], [], 'Invoice', null),
            $workspace,
        );
    }

    /**
     * The address the client will see it arrive from.
     *
     * Read-only on the compose screen and the one part of the message its
     * sender cannot change, so an operator who cannot see it here cannot tell a
     * client where to reply.
     */
    public function test_the_from_address_is_reported_with_its_name(): void
    {
        config(['mail.from.address' => 'billing@synthetic.test', 'mail.from.name' => 'Synthetic Books']);

        $this->assertSame(
            'Synthetic Books <billing@synthetic.test>',
            app(InvoiceEmailService::class)->fromAddress(),
        );

        // With no name configured the bare address is the whole answer, rather
        // than an empty pair of angle brackets around it.
        config(['mail.from.name' => '']);
        $this->assertSame(
            'billing@synthetic.test',
            app(InvoiceEmailService::class)->fromAddress(),
        );

        // And a deployment that never configured one says so, rather than
        // showing an operator a blank where the sender should be.
        config(['mail.from.address' => '']);
        $this->assertSame('Not configured', app(InvoiceEmailService::class)->fromAddress());
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
