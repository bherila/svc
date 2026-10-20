<?php

namespace Tests\Feature\Billing\Reminders;

use App\Mail\InvoicePaymentReminderMail;
use App\Models\ClientCompany;
use App\Models\InvoiceReminderLog;
use App\Models\SquareInvoiceSnapshot;
use App\Services\Billing\RecordReceivedPayment;
use App\Services\Billing\Reminders\PaymentReminderService;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Billing\Reminders\PaymentReminderStatus;
use App\Support\Billing\Reminders\PaymentReminderStep;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\BuildsPaymentReminders;
use Tests\TestCase;

/**
 * The automatic cadence (#438): off until a client is switched on, one
 * reminder per step per invoice, a retry for a failed step, and nothing once
 * the invoice is paid.
 */
final class PaymentReminderCadenceTest extends TestCase
{
    use BuildsPaymentReminders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://svc.example.test']);
        Mail::fake();
    }

    public function test_nothing_is_sent_for_a_client_whose_cadence_is_off(): void
    {
        $this->reminderFixture();
        $this->at('2030-03-15 10:00');

        $this->assertSame(0, app(PaymentReminderService::class)->dispatchAutomaticDue());
        $this->artisan('svc:billing:send-payment-reminders', ['--apply' => true])->assertSuccessful();
        $this->assertSame(0, InvoiceReminderLog::query()->count());
        Mail::assertNothingSent();
    }

    public function test_each_step_is_sent_once_on_its_day_and_the_cadence_stops_once_paid(): void
    {
        [$workspace, , , $invoice] = $this->reminderFixture(cadence: true);
        $service = app(PaymentReminderService::class);

        $this->at('2030-03-11 10:00');
        $this->assertSame(0, $service->dispatchAutomaticDue());

        $this->at('2030-03-12 10:00');
        $this->assertSame(1, $service->dispatchAutomaticDue());
        $this->assertSame(0, $service->dispatchAutomaticDue(), 'A second run sends the same step again');

        $this->at('2030-03-15 10:00');
        $this->artisan('svc:billing:send-payment-reminders --limit=5')->assertSuccessful();
        $this->artisan('svc:billing:send-payment-reminders --limit=5')->assertSuccessful();

        $steps = InvoiceReminderLog::query()->where('workspace_id', $workspace->id)->orderBy('id')->get();
        $this->assertSame(['due_minus_3', 'due'], $steps->map(fn (InvoiceReminderLog $row): ?string => $row->cadence_step?->value)->all());
        $this->assertSame(['sent', 'sent'], $steps->map(fn (InvoiceReminderLog $row): string => $row->status->value)->all());
        $this->assertSame(['scheduler', 'scheduler'], $steps->map(fn (InvoiceReminderLog $row): string => $row->triggered_by->value)->all());
        $this->assertNull($steps[0]->triggered_by_user_id);
        Mail::assertSentCount(2);

        app(RecordReceivedPayment::class)->record($workspace, $invoice->refresh(), ReceivedPaymentData::from(['amount' => 12_345, 'currency' => 'USD', 'received_on' => '2030-03-15', 'method' => 'check']));
        $this->at('2030-03-22 10:00');
        $this->assertSame(0, $service->dispatchAutomaticDue());
        Mail::assertSentCount(2);
    }

    public function test_a_failed_step_is_retried_after_its_backoff_and_then_given_up(): void
    {
        [$workspace] = $this->reminderFixture(cadence: true);
        $service = app(PaymentReminderService::class);
        $this->at('2030-03-15 10:00');
        Mail::shouldReceive('to')->times(3)->andThrow(new TransportException('synthetic refusal'));

        $this->assertSame(1, $service->dispatchAutomaticDue());
        $row = InvoiceReminderLog::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(PaymentReminderStatus::Failed, $row->status);
        $this->assertSame('2030-03-15 14:30:00', $row->next_attempt_at?->utc()->toDateTimeString());

        $this->at('2030-03-15 10:29');
        $this->assertSame(0, $service->dispatchAutomaticDue());
        $this->at('2030-03-15 10:30');
        $this->assertSame(1, $service->dispatchAutomaticDue());
        $this->at('2030-03-15 14:30');
        $this->assertSame(1, $service->dispatchAutomaticDue());
        $this->at('2030-03-16 10:00');
        $this->assertSame(0, $service->dispatchAutomaticDue());

        $row = InvoiceReminderLog::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(3, $row->attempt_number);
        $this->assertSame(PaymentReminderStatus::Failed, $row->status);
        $this->assertNull($row->next_attempt_at);
    }

    public function test_a_failure_before_the_mailer_is_a_retryable_failure_not_a_stuck_claim(): void
    {
        [$workspace] = $this->reminderFixture(cadence: true);
        $this->at('2030-03-15 10:00');
        // The claim commits; re-reading the facts then fails before anything is sent.
        InvoiceReminderLog::saved(function (InvoiceReminderLog $reminder): void {
            if ($reminder->status === PaymentReminderStatus::Sending && $reminder->wasRecentlyCreated) {
                ClientCompany::retrieved(static fn () => throw new \RuntimeException('synthetic outage'));
            }
        });

        $this->assertSame(1, app(PaymentReminderService::class)->dispatchAutomaticDue());

        $row = InvoiceReminderLog::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(PaymentReminderStatus::Failed, $row->status);
        $this->assertSame('Not sent: the reminder could not be checked before sending (RuntimeException).', $row->error_summary);
        $this->assertSame('2030-03-15 14:30:00', $row->next_attempt_at?->utc()->toDateTimeString());
        Mail::assertNothingSent();
    }

    public function test_a_final_step_missed_while_the_scheduler_was_down_is_still_sent_once(): void
    {
        [$workspace, , , $invoice] = $this->reminderFixture(cadence: true);
        $invoice->forceFill(['issue_date' => '2030-01-01', 'due_date' => '2030-01-15'])->save();
        // Seven weeks overdue, with no step ever sent.
        $this->at('2030-03-05 10:00');

        $this->assertSame(1, app(PaymentReminderService::class)->dispatchAutomaticDue());
        $this->assertSame(0, app(PaymentReminderService::class)->dispatchAutomaticDue());

        $this->assertSame(PaymentReminderStep::TwoWeeksOverdue, InvoiceReminderLog::query()->where('workspace_id', $workspace->id)->sole()->cadence_step);
        Mail::assertSentCount(1);
    }

    public function test_a_step_already_claimed_elsewhere_is_never_sent_twice(): void
    {
        [$workspace, , , $invoice] = $this->reminderFixture(cadence: true);
        $this->at('2030-03-15 10:00');
        // Another run's claim: the unique (invoice, step) index is the backstop
        // under the lock, so a second claim cannot be inserted at all.
        $claim = [
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'origin' => 'automatic', 'cadence_step' => 'due',
            'channel' => 'email', 'pay_link' => 'none', 'recipients' => ['billing@reminders.example.test'], 'subject' => 'Synthetic',
            'currency' => 'USD', 'amount_due' => 1, 'status' => 'sending', 'triggered_by' => 'scheduler', 'queued_at' => now()->subHours(2), 'claimed_at' => now()->subHours(2),
        ];
        InvoiceReminderLog::query()->create($claim);

        $this->assertNull(app(PaymentReminderService::class)->sendAutomatic($invoice));
        $this->assertThrows(fn () => InvoiceReminderLog::query()->create($claim), UniqueConstraintViolationException::class);
        Mail::assertNothingSent();
    }

    public function test_enabling_the_cadence_does_not_send_steps_that_fell_due_before_it(): void
    {
        [$workspace, $owner, $company] = $this->reminderFixture();
        $this->at('2030-03-23 10:00');
        app(PaymentReminderService::class)->setCadence($workspace, $company, true, $owner);

        $this->assertSame(0, app(PaymentReminderService::class)->dispatchAutomaticDue());
        $this->at('2030-03-29 10:00');
        $this->assertSame(1, app(PaymentReminderService::class)->dispatchAutomaticDue());
        $this->assertSame(PaymentReminderStep::TwoWeeksOverdue, InvoiceReminderLog::query()->sole()->cadence_step);
    }

    public function test_square_collected_invoices_on_autopay_or_with_squares_own_reminders_are_left_to_square(): void
    {
        [$workspace, $owner, , $invoice] = $this->reminderFixture(cadence: true);
        $this->linkReminderInvoiceToSquare($workspace, $owner, $invoice, ['autopay' => true]);
        $this->at('2030-03-15 10:00');

        $this->assertSame(0, app(PaymentReminderService::class)->dispatchAutomaticDue());

        SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->update(['autopay' => false]);
        $this->assertSame(1, app(PaymentReminderService::class)->dispatchAutomaticDue());
        Mail::assertSent(InvoicePaymentReminderMail::class, fn (InvoicePaymentReminderMail $mail): bool => $mail->payUrl === self::SQUARE_PAGE);

        SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->update(['payment_requests' => json_encode([['request_type' => 'BALANCE', 'reminders' => [['relative_scheduled_days' => 1, 'status' => 'PENDING']]]])]);
        $this->at('2030-03-22 10:00');
        $this->assertSame(0, app(PaymentReminderService::class)->dispatchAutomaticDue());
        Mail::assertSentCount(1);
    }

    public function test_one_workspace_running_the_cadence_never_touches_another_clients_invoices(): void
    {
        [$enabled] = $this->reminderFixture('synthetic-cadence-on', cadence: true);
        [$off] = $this->reminderFixture('synthetic-cadence-off');
        $this->at('2030-03-15 10:00');

        $this->assertSame(1, app(PaymentReminderService::class)->dispatchAutomaticDue());
        $this->assertSame(1, InvoiceReminderLog::query()->where('workspace_id', $enabled->id)->count());
        $this->assertSame(0, InvoiceReminderLog::query()->where('workspace_id', $off->id)->count());
    }

    public function test_the_scheduler_runs_the_cadence_in_process_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event): bool => str_contains((string) $event->command, 'svc:billing:send-payment-reminders'));

        $this->assertCount(1, $events);
        $event = $events->sole();
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--limit=50', (string) $event->command);
    }

    /** A wall-clock time in the fixture workspace's zone. */
    private function at(string $newYork): void
    {
        Date::setTestNow(CarbonImmutable::parse($newYork, 'America/New_York')->utc());
    }
}
