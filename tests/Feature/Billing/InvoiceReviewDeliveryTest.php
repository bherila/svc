<?php

namespace Tests\Feature\Billing;

use App\Mail\AdministratorInvoiceIssuedMail;
use App\Mail\InvoiceMail;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceAdministratorNotification;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceEmailDraft;
use Closure;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

final class InvoiceReviewDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_committed_issue_sends_one_administrator_mailable_with_the_issued_pdf_and_authenticated_open_link(): void
    {
        Mail::fake();
        [$owner, $workspace, $company, $invoice] = $this->draft();

        $issued = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        app(InvoiceLifecycleService::class)->issue($invoice->fresh(), $workspace);

        $this->assertInstanceOf(DateTimeInterface::class, $issued->issue_date);
        $this->assertTrue($company->fresh()->is_active);
        $this->assertSame('date', $issued->getCasts()['issue_date'] ?? null);
        $this->assertSame('boolean', $company->getCasts()['is_active'] ?? null);
        Mail::assertSent(AdministratorInvoiceIssuedMail::class, 1);
        Mail::assertSent(AdministratorInvoiceIssuedMail::class, function (AdministratorInvoiceIssuedMail $mail) use ($owner, $invoice): bool {
            $attachment = $mail->attachments()[0] ?? null;

            return $mail->hasTo($owner->email)
                && $mail->invoiceNumber === $invoice->invoice_number
                && $mail->clientName === 'Synthetic Review Client'
                && $mail->currency === 'USD'
                && $mail->totalAmount === 12500
                && str_contains($mail->openUrl, "/clients/{$invoice->clientCompany->public_id}/invoices/{$invoice->public_id}")
                && $attachment instanceof Attachment
                && $attachment->mime === 'application/pdf'
                && str_starts_with($this->attachmentData($attachment), '%PDF-');
        });

        $notification = ClientInvoiceAdministratorNotification::query()->sole();
        $this->assertSame('sent', $notification->status);
        $this->assertSame(1, $notification->invoice_revision);
        $this->assertNotNull($notification->pdf_content_base64);
        $this->assertSame(['attempted', 'accepted'], collect($notification->attempt_history)->pluck('result')->all());

        $this->get($notification->open_url)
            ->assertRedirect(route('login'));
        $this->assertSame($notification->open_url, session('url.intended'));

        $this->actingAs($owner)->get($notification->open_url)->assertOk();
        $this->get("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $outsider = User::factory()->create(['email' => 'outsider@synthetic.test']);
        $this->actingAs($outsider)->get($notification->open_url)->assertForbidden();
        $this->get("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/pdf")
            ->assertNotFound();
    }

    public function test_rolled_back_issuance_registers_and_sends_nothing(): void
    {
        Mail::fake();
        [, $workspace, , $invoice] = $this->draft();

        DB::beginTransaction();
        app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        $this->assertSame(1, ClientInvoiceAdministratorNotification::query()->count());
        Mail::assertNotSent(AdministratorInvoiceIssuedMail::class);
        DB::rollBack();

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame(0, ClientInvoiceAdministratorNotification::query()->count());
        Mail::assertNotSent(AdministratorInvoiceIssuedMail::class);
    }

    public function test_project_manager_is_preferred_and_the_workspace_owner_is_the_fallback(): void
    {
        Mail::fake();
        [$owner, $workspace, , $invoice] = $this->draft();
        $projectManager = User::factory()->create(['email' => 'project-manager@synthetic.test']);
        $workspace->memberships()->create(['user_id' => $projectManager->id, 'role' => 'viewer']);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $invoice->client_company_id,
            'name' => 'Synthetic Review Project',
            'status' => 'active',
            'is_visible_to_client' => true,
        ]);
        $project->members()->attach($projectManager->id, [
            'workspace_id' => $workspace->id,
            'role' => 'manager',
        ]);
        $invoice->lines()->where('workspace_id', $workspace->id)->update(['client_project_id' => $project->id]);

        app(InvoiceLifecycleService::class)->issue($invoice->fresh(), $workspace);

        Mail::assertSent(AdministratorInvoiceIssuedMail::class, fn (AdministratorInvoiceIssuedMail $mail): bool => $mail->hasTo($projectManager->email));
        Mail::assertNotSent(AdministratorInvoiceIssuedMail::class, fn (AdministratorInvoiceIssuedMail $mail): bool => $mail->hasTo($owner->email));
    }

    public function test_missing_administrator_is_visible_and_does_not_undo_issue(): void
    {
        Mail::fake();
        [, $workspace, , $invoice] = $this->draft(ownerRole: 'viewer');

        $issued = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);

        $this->assertSame('issued', $issued->status);
        $notification = ClientInvoiceAdministratorNotification::query()->sole();
        $this->assertSame('missing_recipient', $notification->status);
        $this->assertStringContainsString('No eligible administrator', (string) $notification->error_summary);
        $this->assertNotNull($notification->pdf_content_base64);
        Mail::assertNotSent(AdministratorInvoiceIssuedMail::class);
    }

    public function test_a_missing_administrator_is_re_resolved_without_losing_the_original_pdf(): void
    {
        Mail::fake();
        Date::setTestNow('2026-09-15 12:00:00 UTC');
        [, $workspace, , $invoice] = $this->draft(ownerRole: 'viewer');
        app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        $notification = ClientInvoiceAdministratorNotification::query()->sole();
        $originalPdf = $notification->pdf_content_base64;

        $administrator = User::factory()->create(['email' => 'new-administrator@synthetic.test']);
        $workspace->memberships()->create(['user_id' => $administrator->id, 'role' => 'admin']);
        Date::setTestNow('2026-09-16 12:01:00 UTC');
        Artisan::call('svc:billing:dispatch-invoice-emails');

        Mail::assertSent(AdministratorInvoiceIssuedMail::class, fn (AdministratorInvoiceIssuedMail $mail): bool => $mail->hasTo($administrator->email));
        $notification = $notification->fresh();
        $this->assertSame('sent', $notification->status);
        $this->assertSame($administrator->id, $notification->recipient_user_id);
        $this->assertSame($originalPdf, $notification->pdf_content_base64);
    }

    public function test_administrator_transport_failure_does_not_undo_issue_and_is_retried_from_the_durable_record(): void
    {
        Date::setTestNow('2026-09-15 12:00:00 UTC');
        [, $workspace, , $invoice] = $this->draft();
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('synthetic refusal'));

        $issued = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);

        $this->assertSame('issued', $issued->status);
        $notification = ClientInvoiceAdministratorNotification::query()->sole();
        $this->assertSame('failed', $notification->status);
        $this->assertSame(1, $notification->attempt_count);
        $this->assertNotNull($notification->next_attempt_at);

        Date::setTestNow('2026-09-15 12:06:00 UTC');
        Mail::clearResolvedInstance('mail.manager');
        app()->forgetInstance('mail.manager');
        Mail::fake();
        Artisan::call('svc:billing:dispatch-invoice-emails');

        Mail::assertSent(AdministratorInvoiceIssuedMail::class, 1);
        $this->assertSame('sent', $notification->fresh()->status);
        $this->assertSame(2, $notification->fresh()->attempt_count);
    }

    public function test_accepted_administrator_notification_with_an_unpersisted_result_is_not_blindly_retried(): void
    {
        Mail::fake();
        [, $workspace, , $invoice] = $this->draft();
        $rejectAcceptedResult = true;
        ClientInvoiceAdministratorNotification::updating(
            static function (ClientInvoiceAdministratorNotification $notification) use (&$rejectAcceptedResult): void {
                if ($notification->status === 'sent' && $rejectAcceptedResult) {
                    $rejectAcceptedResult = false;
                    throw new \RuntimeException('Synthetic local failure.');
                }
            },
        );

        try {
            $issued = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);

            $this->assertSame('issued', $issued->status);
            Mail::assertSent(AdministratorInvoiceIssuedMail::class, 1);
            $this->assertSame('sending', ClientInvoiceAdministratorNotification::query()->sole()->status);
            Artisan::call('svc:billing:dispatch-invoice-emails');
            Mail::assertSent(AdministratorInvoiceIssuedMail::class, 1);
        } finally {
            $rejectAcceptedResult = false;
        }
    }

    public function test_automatic_delivery_is_off_by_default_and_activation_requires_recipients_and_a_bounded_delay(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        $company->forceFill(['billing_email' => null])->save();
        $invalidRecipient = User::factory()->create(['email' => 'not-an-email-address']);
        $company->portalUsers()->attach($invalidRecipient->id, [
            'workspace_id' => $workspace->id,
            'role' => 'client',
        ]);
        $url = "/workspaces/{$workspace->public_id}/clients/{$company->public_id}";

        $this->actingAs($owner)->patch($url, [
            'name' => $company->name,
            'billing_email' => null,
            'is_active' => true,
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 1,
        ])->assertSessionHasErrors('automatic_invoice_email_enabled');

        $this->actingAs($owner)->patch($url, [
            'name' => $company->name,
            'billing_email' => 'billing@synthetic.test',
            'is_active' => true,
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 366,
        ])->assertSessionHasErrors('automatic_invoice_email_delay_days');

        $this->actingAs($owner)->patch($url, [
            'name' => $company->name,
            'billing_email' => 'billing@synthetic.test',
            'is_active' => true,
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 1.5,
        ])->assertSessionHasErrors('automatic_invoice_email_delay_days');

        $this->actingAs($owner)->patch($url, [
            'name' => $company->name,
            'billing_email' => 'billing@synthetic.test',
            'is_active' => true,
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 0,
        ])->assertRedirect();

        $this->assertTrue($company->fresh()->automatic_invoice_email_enabled);
        $this->assertSame(0, $company->fresh()->automatic_invoice_email_delay_days);
        $this->assertDatabaseHas('client_company_activity', [
            'workspace_id' => $workspace->id,
            'action' => 'client.invoice_delivery_settings_updated',
        ]);
    }

    public function test_settings_are_manager_only_and_affect_only_new_issuance_without_releasing_cancelled_work(): void
    {
        Mail::fake();
        [$owner, $workspace, $company, $historical] = $this->draft();
        $historical = app(InvoiceLifecycleService::class)->issue($historical, $workspace);
        $viewer = User::factory()->create(['email' => 'viewer@synthetic.test']);
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'viewer']);
        $url = "/workspaces/{$workspace->public_id}/clients/{$company->public_id}";
        $enabled = [
            'name' => $company->name,
            'billing_email' => $company->billing_email,
            'is_active' => true,
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 0,
        ];

        $this->actingAs($viewer)->patch($url, $enabled)->assertForbidden();
        $this->actingAs($owner)->patch($url, $enabled)->assertRedirect();
        $historical = $historical->fresh();
        $this->assertNull($historical->automatic_delivery_status);
        $this->assertNull($historical->automatic_delivery_delay_days);
        $this->assertNull($historical->automatic_delivery_due_at);
        $this->assertNull($historical->automatic_delivery_held_at);
        $this->assertNull($historical->automatic_delivery_note);

        [, , , $newInvoice] = $this->draft('INV-NEW-OPT-IN', existing: [$owner, $workspace, $company->fresh()]);
        $newInvoice = app(InvoiceLifecycleService::class)->issue($newInvoice, $workspace);
        $this->assertSame('scheduled', $newInvoice->automatic_delivery_status);

        $this->actingAs($owner)->patch($url, [
            ...$enabled,
            'automatic_invoice_email_enabled' => false,
            'automatic_invoice_email_delay_days' => null,
        ])->assertRedirect();
        $this->assertSame('cancelled', $newInvoice->fresh()->automatic_delivery_status);

        $this->actingAs($owner)->patch($url, $enabled)->assertRedirect();
        $this->assertSame('cancelled', $newInvoice->fresh()->automatic_delivery_status);
    }

    public function test_recipient_suggestions_do_not_cross_company_or_workspace_boundaries(): void
    {
        [, , $company] = $this->tenant();
        [$foreignUser, $foreignWorkspace, $foreignCompany] = $this->tenant();
        $foreignCompany->portalUsers()->attach($foreignUser->id, [
            'workspace_id' => $foreignWorkspace->id,
            'role' => 'client',
        ]);

        $recipients = app(InvoiceEmailService::class)->suggestedRecipientsForCompany($company);

        $this->assertSame(['billing@synthetic.test'], array_column($recipients, 'email'));
        $this->assertNotContains($foreignUser->email, array_column($recipients, 'email'));
    }

    public function test_delay_uses_the_committed_issue_instant_and_preserves_wall_clock_across_dst(): void
    {
        Mail::fake();
        Date::setTestNow('2026-03-07 15:00:00 UTC');
        [, $workspace, $company, $invoice] = $this->draft(timezone: 'America/New_York');
        $company->forceFill([
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 1,
        ])->save();

        $issued = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);

        $this->assertSame('2026-03-08 10:00:00', $issued->automatic_delivery_due_at->setTimezone('America/New_York')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-08 14:00:00', $issued->automatic_delivery_due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $issued->automatic_delivery_status);
        $this->assertSame(1, $issued->automatic_delivery_delay_days);
    }

    public function test_due_delivery_uses_current_recipients_and_repeated_scheduler_runs_do_not_duplicate_it(): void
    {
        Mail::fake();
        Date::setTestNow('2026-09-15 12:00:00 UTC');
        [, $workspace, $company, $invoice] = $this->draft();
        $company->forceFill([
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 0,
        ])->save();
        app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        $company->forceFill(['billing_email' => 'current-recipient@synthetic.test'])->save();

        Artisan::call('svc:billing:dispatch-invoice-emails');
        Artisan::call('svc:billing:dispatch-invoice-emails');

        Mail::assertSent(InvoiceMail::class, 1);
        Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->hasTo('current-recipient@synthetic.test'));
        $delivery = ClientInvoiceEmailDelivery::query()->where('origin', 'automatic')->sole();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1, $delivery->invoice_revision);
        $this->assertSame('automatically_sent', $invoice->fresh()->automatic_delivery_status);
    }

    public function test_failed_automatic_delivery_uses_bounded_backoff_before_retry(): void
    {
        Mail::fake();
        Date::setTestNow('2026-09-15 12:00:00 UTC');
        [, $workspace, $company, $invoice] = $this->draft();
        $company->forceFill([
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 0,
        ])->save();
        $invoice = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);

        Mail::clearResolvedInstance('mail.manager');
        app()->forgetInstance('mail.manager');
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('synthetic client refusal'));
        Artisan::call('svc:billing:dispatch-invoice-emails');

        $failed = ClientInvoiceEmailDelivery::query()->where('origin', 'automatic')->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('2026-09-15 12:05:00', $invoice->fresh()->automatic_delivery_due_at->utc()->format('Y-m-d H:i:s'));

        Mail::clearResolvedInstance('mail.manager');
        app()->forgetInstance('mail.manager');
        Mail::fake();
        Artisan::call('svc:billing:dispatch-invoice-emails');
        Mail::assertNotSent(InvoiceMail::class);

        Date::setTestNow('2026-09-15 12:06:00 UTC');
        Artisan::call('svc:billing:dispatch-invoice-emails');
        Mail::assertSent(InvoiceMail::class, 1);
        $this->assertSame(2, ClientInvoiceEmailDelivery::query()->where('origin', 'automatic')->count());
        $this->assertSame('automatically_sent', $invoice->fresh()->automatic_delivery_status);
    }

    public function test_manual_delivery_suppresses_the_pending_automatic_delivery_without_counting_the_admin_notice(): void
    {
        Mail::fake();
        Date::setTestNow('2026-09-15 12:00:00 UTC');
        [$owner, $workspace, $company, $invoice] = $this->draft();
        $company->forceFill([
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 1,
        ])->save();
        $invoice = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);

        $this->actingAs($owner)->postJson(
            "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send",
        )->assertOk();
        Date::setTestNow('2026-09-17 12:00:00 UTC');
        Artisan::call('svc:billing:dispatch-invoice-emails');

        Mail::assertSent(AdministratorInvoiceIssuedMail::class, 1);
        Mail::assertSent(InvoiceMail::class, 1);
        $this->assertSame('manually_sent', $invoice->fresh()->automatic_delivery_status);
        $this->assertSame(0, ClientInvoiceEmailDelivery::query()->where('origin', 'automatic')->count());
    }

    public function test_a_lost_manual_response_can_be_retried_with_the_same_key_without_a_second_accepted_delivery(): void
    {
        Mail::fake();
        [$owner, $workspace, , $invoice] = $this->draft();
        $invoice = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        $url = "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send";
        $payload = [
            'recipients' => ['billing@synthetic.test'],
            'subject' => 'Synthetic invoice',
            'idempotency_key' => '11111111-1111-4111-8111-111111111111',
        ];

        $this->actingAs($owner)->postJson($url, $payload)->assertOk();
        $this->actingAs($owner)->postJson($url, $payload)->assertOk();

        Mail::assertSent(InvoiceMail::class, 1);
        $this->assertSame(1, ClientInvoiceEmailDelivery::query()->count());

        $payload['subject'] = 'A different message';
        $this->actingAs($owner)->postJson($url, $payload)->assertStatus(422);

        $secondKey = 'opaque-agent-retry-key';
        $this->actingAs($owner)->withHeader('Idempotency-Key', $secondKey)
            ->postJson($url, ['subject' => 'Another delivery'])
            ->assertOk();
        $this->actingAs($owner)->withHeader('Idempotency-Key', $secondKey)
            ->postJson($url, ['subject' => 'Another delivery'])
            ->assertOk();
        Mail::assertSent(InvoiceMail::class, 2);
    }

    public function test_provider_acceptance_followed_by_local_failure_stays_claimed_and_is_not_blindly_retried(): void
    {
        Mail::fake();
        Date::setTestNow('2026-09-15 12:00:00 UTC');
        [$owner, $workspace, $company, $invoice] = $this->draft();
        $company->forceFill([
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 0,
        ])->save();
        $invoice = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        $rejectAcceptedResult = true;
        ClientInvoiceEmailDelivery::updating(
            static function (ClientInvoiceEmailDelivery $delivery) use (&$rejectAcceptedResult): void {
                if ($delivery->status === 'sent' && $rejectAcceptedResult) {
                    $rejectAcceptedResult = false;
                    throw new \RuntimeException('Synthetic local failure.');
                }
            },
        );

        try {
            $this->actingAs($owner)->postJson(
                "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send",
            )->assertStatus(422);

            Mail::assertSent(InvoiceMail::class, 1);
            $this->assertSame('sending', ClientInvoiceEmailDelivery::query()->sole()->status);
            Artisan::call('svc:billing:dispatch-invoice-emails');
            Mail::assertSent(InvoiceMail::class, 1);
        } finally {
            $rejectAcceptedResult = false;
        }
    }

    public function test_hold_void_and_payment_each_prevent_automatic_delivery(): void
    {
        Mail::fake();
        Date::setTestNow('2026-09-15 12:00:00 UTC');
        [$owner, $workspace, $company, $held] = $this->draft('INV-HOLD');
        $company->forceFill([
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 0,
        ])->save();
        $held = app(InvoiceLifecycleService::class)->issue($held, $workspace);
        $this->actingAs($owner)->post("/workspaces/{$workspace->public_id}/invoices/{$held->public_id}/automatic-delivery/hold")->assertRedirect();

        [, , , $voided] = $this->draft('INV-VOID', existing: [$owner, $workspace, $company]);
        $voided = app(InvoiceLifecycleService::class)->issue($voided, $workspace);
        app(InvoiceLifecycleService::class)->void($voided, $workspace);

        [, , , $paid] = $this->draft('INV-PAID', existing: [$owner, $workspace, $company]);
        $paid = app(InvoiceLifecycleService::class)->issue($paid, $workspace);
        app(InvoiceLifecycleService::class)->applyPayment($paid, [
            'amount' => 100,
            'currency' => 'USD',
            'method' => 'test',
        ], $workspace);

        Artisan::call('svc:billing:dispatch-invoice-emails');

        Mail::assertNotSent(InvoiceMail::class);
        $this->assertSame('held', $held->fresh()->automatic_delivery_status);
        $this->assertSame('cancelled', $voided->fresh()->automatic_delivery_status);
        $this->assertSame('cancelled', $paid->fresh()->automatic_delivery_status);
    }

    public function test_audited_correction_updates_the_revision_and_pdf_facts_then_holds_delivery(): void
    {
        Mail::fake();
        [$owner, $workspace, $company, $invoice] = $this->draft();
        $company->forceFill([
            'automatic_invoice_email_enabled' => true,
            'automatic_invoice_email_delay_days' => 2,
        ])->save();
        $invoice = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        $line = $invoice->lines()->sole();
        $originalPdf = ClientInvoiceAdministratorNotification::query()->sole()->pdf_content_base64;

        $this->actingAs($owner)->postJson(
            "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/correct",
            [
                'expected_revision' => 1,
                'reason' => 'Correct the synthetic service wording and rate.',
                'due_date' => '2026-10-15',
                'lines' => [[
                    'id' => $line->public_id,
                    'description' => 'Corrected synthetic service',
                    'quantity' => '1',
                    'unit_amount' => 13000,
                    'tax_amount' => 0,
                ]],
            ],
        )->assertOk();

        $corrected = $invoice->fresh();
        $this->assertSame(2, $corrected->document_revision);
        $this->assertSame(13000, $corrected->total_amount);
        $this->assertSame('held', $corrected->automatic_delivery_status);
        $this->assertSame('Corrected synthetic service', $line->fresh()->description);
        $this->assertSame($originalPdf, ClientInvoiceAdministratorNotification::query()->sole()->pdf_content_base64);
        $this->assertDatabaseHas('client_company_activity', [
            'workspace_id' => $workspace->id,
            'action' => 'invoice.corrected',
        ]);

        $this->actingAs($owner)->postJson(
            "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send",
        )->assertOk();
        $delivery = ClientInvoiceEmailDelivery::query()->where('origin', 'manual')->sole();
        $this->assertSame(2, $delivery->invoice_revision);
        Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->invoice->total_amount === 13000);
        $this->assertSame('manually_sent', $invoice->fresh()->automatic_delivery_status);
    }

    public function test_correction_is_refused_after_client_send_or_payment_and_for_stale_revision(): void
    {
        Mail::fake();
        [$owner, $workspace, , $invoice] = $this->draft();
        $invoice = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
        $line = $invoice->lines()->sole();
        $payload = [
            'expected_revision' => 99,
            'reason' => 'Synthetic stale correction.',
            'due_date' => $invoice->due_date?->toDateString(),
            'lines' => [[
                'id' => $line->public_id,
                'description' => $line->description,
                'quantity' => '1',
                'unit_amount' => 12500,
                'tax_amount' => 0,
            ]],
        ];
        $url = "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/correct";

        $this->actingAs($owner)->postJson($url, $payload)->assertStatus(422);
        $payload['expected_revision'] = 1;
        app(InvoiceEmailService::class)->send(
            $invoice,
            InvoiceEmailDraft::of(['billing@synthetic.test'], [], 'Invoice', null),
            $workspace,
        );
        $this->actingAs($owner)->postJson($url, $payload)->assertStatus(422);
        $this->assertSame(1, $invoice->fresh()->document_revision);
    }

    public function test_description_only_correction_normalizes_equivalent_quantity_strings(): void
    {
        Mail::fake();
        [$owner, $workspace, , $invoice] = $this->draft();
        $invoice->lines()->update(['type' => 'retainer']);
        $invoice = app(InvoiceLifecycleService::class)->issue($invoice->fresh(), $workspace);
        $line = $invoice->lines()->sole();

        $this->actingAs($owner)->postJson(
            "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/correct",
            [
                'expected_revision' => 1,
                'reason' => 'Correct wording without changing accounting facts.',
                'due_date' => $invoice->due_date?->toDateString(),
                'lines' => [[
                    'id' => $line->public_id,
                    'description' => 'Corrected retainer wording',
                    'quantity' => '1',
                    'unit_amount' => 12500,
                    'tax_amount' => 0,
                ]],
            ],
        )->assertOk();

        $this->assertSame(2, $invoice->fresh()->document_revision);
        $this->assertSame('Corrected retainer wording', $line->fresh()->description);
    }

    private function attachmentData(Attachment $attachment): string
    {
        return $attachment->attachWith(
            fn (): never => throw new \LogicException('Expected an in-memory attachment.'),
            static fn (Closure $data): string => $data(),
        );
    }

    /** @return array{0:User,1:Workspace,2:ClientCompany} */
    private function tenant(string $ownerRole = 'owner', string $timezone = 'UTC'): array
    {
        $owner = User::factory()->create(['email' => 'operator-'.str()->random(8).'@synthetic.test']);
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic Review Workspace',
            'slug' => 'synthetic-review-'.str()->random(8),
            'timezone' => $timezone,
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => $ownerRole]);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Review Client',
            'slug' => 'synthetic-review-client-'.str()->random(8),
            'billing_email' => 'billing@synthetic.test',
        ]);

        return [$owner, $workspace, $company];
    }

    /**
     * @param  array{0:User,1:Workspace,2:ClientCompany}|null  $existing
     * @return array{0:User,1:Workspace,2:ClientCompany,3:ClientInvoice}
     */
    private function draft(
        string $number = 'INV-REVIEW',
        string $ownerRole = 'owner',
        string $timezone = 'UTC',
        ?array $existing = null,
    ): array {
        [$owner, $workspace, $company] = $existing ?? $this->tenant($ownerRole, $timezone);
        $invoice = app(InvoiceLifecycleService::class)->createDraft($workspace, $company, [
            'invoice_number' => $number,
            'currency' => 'USD',
            'issue_date' => '2026-09-15',
            'due_date' => '2026-10-15',
        ], [[
            'type' => 'fee',
            'description' => 'Synthetic service',
            'quantity' => '1',
            'unit_amount' => 12500,
            'tax_amount' => 0,
        ]]);

        return [$owner, $workspace, $company, $invoice];
    }
}
