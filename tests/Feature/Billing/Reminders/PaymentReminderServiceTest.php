<?php

namespace Tests\Feature\Billing\Reminders;

use App\Exceptions\PaymentReminderInFlight;
use App\Exceptions\PaymentReminderRefused;
use App\Exceptions\PaymentReminderVersionConflict;
use App\Mail\InvoicePaymentReminderMail;
use App\Models\ClientCompanyActivity;
use App\Models\InvoiceReminderLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\RecordReceivedPayment;
use App\Services\Billing\Reminders\PaymentReminderRepository;
use App\Services\Billing\Reminders\PaymentReminderService;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Billing\Reminders\PaymentReminderRefusal;
use App\Support\Billing\Reminders\PaymentReminderStatus;
use App\Support\Billing\Reminders\PaymentReminderTrigger;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\BuildsPaymentReminders;
use Tests\TestCase;

/**
 * Payment reminders through the service (#438): what each sends, to whom,
 * with which pay link, and that every one is logged and none is sent twice.
 */
final class PaymentReminderServiceTest extends TestCase
{
    use BuildsPaymentReminders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 in New York on the due date.
        Date::setTestNow('2030-03-15 14:00:00');
        config(['app.url' => 'https://svc.example.test']);
        Mail::fake();
    }

    public function test_a_manual_reminder_for_an_invoice_svc_collects_carries_the_pdf_and_is_logged(): void
    {
        [$workspace, $owner, , $invoice] = $this->reminderFixture();
        $service = app(PaymentReminderService::class);

        $registered = $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website);
        $this->assertSame(PaymentReminderStatus::Pending, $registered->status);
        Mail::assertNothingSent();

        $sent = $service->sendRegistered($workspace, $invoice, $registered->public_id);

        $this->assertSame(PaymentReminderStatus::Sent, $sent->status);
        $this->assertSame(['billing@reminders.example.test'], $sent->recipients);
        $this->assertSame('none', $sent->pay_link->value);
        $this->assertNull($sent->cadence_step);
        $this->assertSame('manual', $sent->origin->value);
        $this->assertSame('website', $sent->triggered_by->value);
        $this->assertSame($owner->id, $sent->triggered_by_user_id);
        $this->assertSame(12_345, $sent->amount_due);
        $this->assertSame('2030-03-15', $sent->due_on?->toDateString());
        $this->assertSame('Payment reminder: invoice SYN-REM-1', $sent->subject);
        Mail::assertSent(InvoicePaymentReminderMail::class, function (InvoicePaymentReminderMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('billing@reminders.example.test')
                && count($mail->attachments()) === 1
                && $mail->payUrl === null
                && str_contains($html, 'remaining balance of USD 123.45')
                && str_contains($html, 'due on 2030-03-15')
                && ! str_contains($html, 'squareup');
        });

        // Sending again is a no-op: the row is no longer pending.
        $this->assertSame(PaymentReminderStatus::Sent, $service->sendRegistered($workspace, $invoice, $registered->public_id)->status);
        Mail::assertSentCount(1);
    }

    public function test_a_client_with_a_portal_user_is_sent_to_the_portal_invoice_without_a_token(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->reminderFixture();
        $portal = User::factory()->create(['email' => 'portal@reminders.example.test']);
        $company->portalUsers()->attach($portal, ['role' => 'client']);

        $service = app(PaymentReminderService::class);
        $sent = $service->sendRegistered($workspace, $invoice, $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website)->public_id);

        $this->assertSame('svc_portal', $sent->pay_link->value);
        $this->assertSame(['billing@reminders.example.test', 'portal@reminders.example.test'], $sent->recipients);
        $expected = 'https://svc.example.test'.route('portal.invoice', [$company, $invoice], absolute: false);
        Mail::assertSent(InvoicePaymentReminderMail::class, fn (InvoicePaymentReminderMail $mail): bool => $mail->payUrl === $expected
            && str_contains($mail->render(), $expected) && count($mail->attachments()) === 1);
    }

    public function test_an_invoice_square_collects_carries_squares_page_and_nothing_of_svcs(): void
    {
        [$workspace, $owner, , $invoice] = $this->reminderFixture();
        $this->linkReminderInvoiceToSquare($workspace, $owner, $invoice);
        $service = app(PaymentReminderService::class);

        $sent = $service->sendRegistered($workspace, $invoice, $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website)->public_id);

        $this->assertSame(PaymentReminderStatus::Sent, $sent->status);
        $this->assertSame('square', $sent->pay_link->value);
        $this->assertSame('000777-R-0003', $sent->square_invoice_number);
        Mail::assertSent(InvoicePaymentReminderMail::class, function (InvoicePaymentReminderMail $mail): bool {
            $html = $mail->render();

            return $mail->payUrl === self::SQUARE_PAGE
                && $mail->attachments() === []
                && str_contains($html, self::SQUARE_PAGE)
                && str_contains($html, 'Square invoice 000777-R-0003')
                && ! str_contains($html, '/portal/');
        });
    }

    /** @return iterable<string, array{string, PaymentReminderRefusal}> */
    public static function squareFailures(): iterable
    {
        yield 'no page in the mirror' => ['missing_page', PaymentReminderRefusal::SquarePageMissing];
        yield 'not mirrored at all' => ['missing_snapshot', PaymentReminderRefusal::SquarePageMissing];
        yield 'a page off Square' => ['foreign_page', PaymentReminderRefusal::SquarePageMissing];
        yield 'retired from the mirror' => ['retired', PaymentReminderRefusal::SquarePageMissing];
        yield 'paid in Square' => ['paid', PaymentReminderRefusal::SquareNotOpen];
        yield 'Square disconnected from this workspace' => ['disconnected', PaymentReminderRefusal::SquarePageMissing];
        yield 'billed in Square without a link' => ['unlinked', PaymentReminderRefusal::SquareNotLinked];
        yield 'paid outside Square' => ['outside', PaymentReminderRefusal::PaidOutsideSquare];
    }

    #[DataProvider('squareFailures')]
    public function test_an_invoice_square_collects_fails_closed_without_a_usable_square_page(string $case, PaymentReminderRefusal $refusal): void
    {
        [$workspace, $owner, $company, $invoice] = $this->reminderFixture();
        if ($case === 'unlinked') {
            $invoice = $this->squareBilledInvoice($company);
        } elseif ($case === 'missing_snapshot') {
            $this->connectSquare($workspace);
            app(SquareLinkService::class)->link($workspace, $invoice, $owner, AgentApiVersion::for($invoice->refresh()), new SquareInvoiceReference('inv:0-reminder1', '000777-R-0003', 'USD', SquareMatchMethod::Manual), false);
        } else {
            $snapshot = $this->linkReminderInvoiceToSquare($workspace, $owner, $invoice, match ($case) {
                'missing_page' => ['public_url' => null],
                'foreign_page' => ['public_url' => 'https://pay.example.test/inv:0-reminder1'],
                'paid' => ['status' => 'PAID'],
                default => [],
            });
            if ($case === 'retired') {
                $snapshot->delete();
            }
            if ($case === 'disconnected') {
                $this->connectSquare(null);
            }
            if ($case === 'outside') {
                app(RecordReceivedPayment::class)->record($workspace, $invoice->refresh(), ReceivedPaymentData::from(['amount' => 1_000, 'currency' => 'USD', 'received_on' => '2030-03-10', 'method' => 'check']));
            }
        }

        try {
            app(PaymentReminderService::class)->register($workspace, $invoice->refresh(), $owner, PaymentReminderTrigger::Website);
            $this->fail('A reminder was registered for an invoice with no usable Square page.');
        } catch (PaymentReminderRefused $refused) {
            $this->assertSame($refusal, $refused->refusal);
            $this->assertNotInstanceOf(PaymentReminderInFlight::class, $refused);
        }
        $this->assertSame(0, InvoiceReminderLog::query()->count());
        Mail::assertNothingSent();
    }

    public function test_an_invoice_is_not_reminded_about_once_paid_or_while_another_reminder_is_leaving(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->reminderFixture();
        $service = app(PaymentReminderService::class);

        $pending = $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website);
        $this->assertThrows(fn () => $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website), PaymentReminderInFlight::class);
        // A stale claim no longer blocks: an ambiguous outcome is not a message about to leave.
        $pending->forceFill(['queued_at' => now()->subMinutes(61)])->save();
        $this->assertSame(PaymentReminderStatus::Pending, $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website)->status);

        $paid = $this->reminderInvoice($company, 'SYN-REM-PAID', 'paid');
        $this->assertThrows(fn () => $service->register($workspace, $paid, $owner, PaymentReminderTrigger::Website), PaymentReminderRefused::class, PaymentReminderRefusal::NotOpen->message());
    }

    public function test_a_registered_reminder_whose_invoice_was_paid_before_it_was_sent_is_cancelled(): void
    {
        [$workspace, $owner, , $invoice] = $this->reminderFixture();
        $service = app(PaymentReminderService::class);
        $registered = $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website);
        app(RecordReceivedPayment::class)->record($workspace, $invoice->refresh(), ReceivedPaymentData::from(['amount' => 12_345, 'currency' => 'USD', 'received_on' => '2030-03-14', 'method' => 'check']));

        $cancelled = $service->sendRegistered($workspace, $invoice, $registered->public_id);

        $this->assertSame(PaymentReminderStatus::Cancelled, $cancelled->status);
        $this->assertStringStartsWith('Not sent: Only an issued or partially paid invoice', (string) $cancelled->error_summary);
        Mail::assertNothingSent();
    }

    public function test_a_square_link_landing_between_claim_and_send_stops_the_reminder(): void
    {
        [$workspace, $owner, , $invoice] = $this->reminderFixture();
        $service = app(PaymentReminderService::class);
        $registered = $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website);
        // The claim commits; the link lands before the message leaves.
        InvoiceReminderLog::saved(function (InvoiceReminderLog $reminder) use ($workspace, $owner, $invoice): void {
            if ($reminder->status === PaymentReminderStatus::Sending && $reminder->wasChanged('status')) {
                $this->linkReminderInvoiceToSquare($workspace, $owner, $invoice->refresh());
            }
        });

        $result = $service->sendRegistered($workspace, $invoice, $registered->public_id);

        $this->assertSame(PaymentReminderStatus::Cancelled, $result->status);
        $this->assertSame('Not sent: the invoice changed after the reminder was claimed.', $result->error_summary);
        Mail::assertNothingSent();
    }

    public function test_a_recipient_removed_between_claim_and_send_does_not_receive_the_reminder(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->reminderFixture();
        $service = app(PaymentReminderService::class);
        $registered = $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website);
        // The claim commits with the old address; the address changes before the message leaves.
        InvoiceReminderLog::saved(function (InvoiceReminderLog $reminder) use ($company): void {
            if ($reminder->status === PaymentReminderStatus::Sending && $reminder->wasChanged('status')) {
                $company->forceFill(['billing_email' => 'new-billing@reminders.example.test'])->save();
            }
        });

        $result = $service->sendRegistered($workspace, $invoice, $registered->public_id);

        $this->assertSame(PaymentReminderStatus::Cancelled, $result->status);
        $this->assertSame('Not sent: the client\'s recipients changed after the reminder was claimed.', $result->error_summary);
        Mail::assertNothingSent();
    }

    public function test_an_invoice_reminded_about_cannot_be_corrected_in_place(): void
    {
        [$workspace, $owner, , $invoice] = $this->reminderFixture();
        $service = app(PaymentReminderService::class);
        $service->sendRegistered($workspace, $invoice, $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website)->public_id);

        $this->assertThrows(
            fn () => app(InvoiceCorrectionService::class)->correct($invoice->refresh(), $workspace, $invoice->document_revision, true, '2030-04-15', 'Synthetic correction', null),
            \DomainException::class,
            'An invoice the client has been sent a payment reminder for, or is being sent one, cannot be corrected in place.',
        );
        $this->assertSame('2030-03-15', $invoice->refresh()->due_date?->toDateString());
    }

    public function test_a_provider_refusal_is_logged_as_failed_with_the_class_only(): void
    {
        [$workspace, $owner, , $invoice] = $this->reminderFixture();
        $service = app(PaymentReminderService::class);
        $registered = $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Agent, 'synthetic-oauth-client');
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('synthetic refusal billing@reminders.example.test'));

        $failed = $service->sendRegistered($workspace, $invoice, $registered->public_id);

        $this->assertSame(PaymentReminderStatus::Failed, $failed->status);
        $this->assertSame('Email delivery failed (TransportException).', $failed->error_summary);
        $this->assertNull($failed->next_attempt_at, 'A person retries a manual reminder by asking again');
        $this->assertSame('agent', $failed->triggered_by->value);
        $this->assertSame('synthetic-oauth-client', $failed->oauth_client_id);
    }

    public function test_deciding_reads_a_bounded_number_of_reminders_however_many_were_sent(): void
    {
        [$workspace, , , $invoice] = $this->reminderFixture();
        foreach (range(1, 60) as $index) {
            InvoiceReminderLog::query()->create([
                'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'origin' => 'manual', 'channel' => 'email', 'pay_link' => 'none',
                'recipients' => ['billing@reminders.example.test'], 'subject' => 'Synthetic '.$index, 'currency' => 'USD', 'amount_due' => 1, 'status' => 'sent',
                'triggered_by' => 'website', 'queued_at' => now()->subDays(2), 'sent_at' => now()->subDays(2),
            ]);
        }
        $loaded = 0;
        InvoiceReminderLog::retrieved(function () use (&$loaded): void {
            $loaded++;
        });

        $facts = app(PaymentReminderRepository::class)->facts($workspace, $invoice);

        $this->assertSame(0, $loaded, 'A person\'s reminders are not loaded to decide the next one');
        $this->assertFalse($facts->inFlight);
        $this->assertSame([], $facts->claims);
    }

    public function test_reminders_are_workspace_scoped(): void
    {
        [$workspace, $owner, , $invoice] = $this->reminderFixture('synthetic-reminders-a');
        [$other, $otherOwner, , $otherInvoice] = $this->reminderFixture('synthetic-reminders-b');
        $service = app(PaymentReminderService::class);
        $registered = $service->register($workspace, $invoice, $owner, PaymentReminderTrigger::Website);

        // Another tenant's in-flight reminder does not block this one.
        $this->assertSame(PaymentReminderStatus::Pending, $service->register($other, $otherInvoice, $otherOwner, PaymentReminderTrigger::Website)->status);
        // Another tenant can neither register against nor send this workspace's invoice or reminder.
        $this->assertThrows(fn () => $service->register($other, $invoice, $otherOwner, PaymentReminderTrigger::Website), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->sendRegistered($other, $invoice, $registered->public_id), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->sendRegistered($other, $otherInvoice, $registered->public_id), ModelNotFoundException::class);
        $this->assertSame(PaymentReminderStatus::Pending, $registered->refresh()->status);
        $this->assertThrows(fn () => app(PaymentReminderRepository::class)->facts($other, $invoice), \DomainException::class);
        $this->assertSame([$registered->public_id], app(PaymentReminderRepository::class)->history($invoice)->pluck('public_id')->all());
        Mail::assertNothingSent();
    }

    public function test_switching_the_cadence_records_when_and_who_and_checks_the_version(): void
    {
        [$workspace, $owner, $company] = $this->reminderFixture();
        $service = app(PaymentReminderService::class);
        $stale = AgentApiVersion::for($company);

        $enabled = $service->setCadence($workspace, $company, true, $owner, $stale);
        $this->assertSame('2030-03-15 14:00:00', $enabled->payment_reminders_enabled_at?->utc()->toDateTimeString());
        $this->assertNotSame($stale, AgentApiVersion::for($enabled));
        // Enabling again changes nothing, and keeps the original time.
        Date::setTestNow('2030-03-16 14:00:00');
        $this->assertSame('2030-03-15 14:00:00', $service->setCadence($workspace, $company, true, $owner)->payment_reminders_enabled_at?->utc()->toDateTimeString());
        try {
            $service->setCadence($workspace, $company, false, $owner, $stale);
            $this->fail('A stale version switched the cadence.');
        } catch (PaymentReminderVersionConflict) {
            $this->assertNotNull($company->refresh()->payment_reminders_enabled_at);
        }
        $this->assertNull($service->setCadence($workspace, $company, false, $owner, AgentApiVersion::for($company->refresh()))->payment_reminders_enabled_at);

        $activity = ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'client.payment_reminders_updated')->orderBy('id')->get();
        $this->assertCount(2, $activity);
        $this->assertSame(['before' => ['enabled' => false], 'after' => ['enabled' => true]], $activity[0]->payload);
        $this->assertSame($owner->id, $activity[1]->actor_user_id);

        [$other] = $this->reminderFixture('synthetic-reminders-other');
        $this->assertThrows(fn () => $service->setCadence($other, $company, true, $owner), ModelNotFoundException::class);
    }
}
