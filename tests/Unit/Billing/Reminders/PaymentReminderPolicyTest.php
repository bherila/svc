<?php

namespace Tests\Unit\Billing\Reminders;

use App\Support\Billing\Reminders\PaymentReminderClaim;
use App\Support\Billing\Reminders\PaymentReminderDecision;
use App\Support\Billing\Reminders\PaymentReminderFacts;
use App\Support\Billing\Reminders\PaymentReminderPayLink;
use App\Support\Billing\Reminders\PaymentReminderPolicy;
use App\Support\Billing\Reminders\PaymentReminderRefusal;
use App\Support\Billing\Reminders\PaymentReminderStatus;
use App\Support\Billing\Reminders\PaymentReminderStep;
use App\Support\Billing\Reminders\SquareReminderFacts;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reminder policy, decided from facts alone (#438). No database: every
 * case here is a value the service would have loaded.
 */
final class PaymentReminderPolicyTest extends TestCase
{
    private const string PAGE = 'https://squareupsandbox.com/pay-invoice/inv:0-fixture1';

    private const string PORTAL = 'https://svc.example.test/portal/c/invoices/i';

    public function test_an_open_invoice_svc_collects_links_to_the_portal_or_carries_the_pdf(): void
    {
        $policy = new PaymentReminderPolicy;

        $this->assertSends($policy->manual($this->facts()), PaymentReminderPayLink::SvcPortal, self::PORTAL);
        $this->assertSends($policy->manual($this->facts(portalUrl: null)), PaymentReminderPayLink::None, null);
        $this->assertSends($policy->manual($this->facts(status: 'partially_paid')), PaymentReminderPayLink::SvcPortal, self::PORTAL);
    }

    /** @return iterable<string, array{array<string, mixed>, PaymentReminderRefusal}> */
    public static function manualRefusals(): iterable
    {
        yield 'draft' => [['status' => 'draft'], PaymentReminderRefusal::NotOpen];
        yield 'paid' => [['status' => 'paid'], PaymentReminderRefusal::NotOpen];
        yield 'void' => [['status' => 'void'], PaymentReminderRefusal::NotOpen];
        yield 'an unknown status' => [['status' => 'mystery'], PaymentReminderRefusal::NotOpen];
        yield 'no balance' => [['balance' => 0], PaymentReminderRefusal::NothingDue];
        yield 'a credit balance' => [['balance' => -1], PaymentReminderRefusal::NothingDue];
        yield 'nobody to send to' => [['recipients' => []], PaymentReminderRefusal::NoRecipients];
        yield 'another in flight' => [['inFlight' => true], PaymentReminderRefusal::InFlight];
        yield 'billed in Square but not linked' => [['square' => self::square(linked: false)], PaymentReminderRefusal::SquareNotLinked];
        yield 'not in the mirror' => [['square' => self::square(status: null, url: null)], PaymentReminderRefusal::SquarePageMissing];
        yield 'mirrored without a page' => [['square' => self::square(url: null)], PaymentReminderRefusal::SquarePageMissing];
        yield 'paid in Square' => [['square' => self::square(status: 'PAID')], PaymentReminderRefusal::SquareNotOpen];
        yield 'scheduled in Square' => [['square' => self::square(status: 'SCHEDULED')], PaymentReminderRefusal::SquareNotOpen];
        yield 'paid outside Square' => [['square' => self::square(paidOutside: true)], PaymentReminderRefusal::PaidOutsideSquare];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('manualRefusals')]
    public function test_a_manual_reminder_is_refused_when(array $overrides, PaymentReminderRefusal $refusal): void
    {
        $decision = (new PaymentReminderPolicy)->manual($this->facts(...$overrides));

        $this->assertSame($refusal, $decision->refusal);
        $this->assertFalse($decision->sends());
        $this->assertNull($decision->payUrl);
        $this->assertFalse($decision->retry);
    }

    public function test_the_invoice_itself_is_asked_about_before_a_reminder_in_flight(): void
    {
        $this->assertSame(PaymentReminderRefusal::NotOpen, (new PaymentReminderPolicy)->manual($this->facts(status: 'paid', inFlight: true))->refusal);
    }

    public function test_a_page_without_a_mirrored_square_status_is_not_trusted(): void
    {
        $this->assertSame(PaymentReminderRefusal::SquarePageMissing, (new PaymentReminderPolicy)->manual($this->facts(square: self::square(status: null)))->refusal);
    }

    public function test_an_invoice_square_collects_carries_only_squares_page(): void
    {
        $decision = (new PaymentReminderPolicy)->manual($this->facts(square: self::square(), portalUrl: self::PORTAL));
        $this->assertSends($decision, PaymentReminderPayLink::Square, self::PAGE);
        $this->assertSame('000001-R-0001', $decision->squareInvoiceNumber);

        $partly = (new PaymentReminderPolicy)->manual($this->facts(square: self::square(status: 'PARTIALLY_PAID')));
        $this->assertSends($partly, PaymentReminderPayLink::Square, self::PAGE);
    }

    public function test_a_person_may_remind_about_autopay_or_square_scheduled_reminders_but_the_cadence_does_not(): void
    {
        $policy = new PaymentReminderPolicy;
        $autopay = $this->facts(square: self::square(autopay: true), today: '2030-03-15 10:00');
        $scheduled = $this->facts(square: self::square(reminders: true), today: '2030-03-15 10:00');

        $this->assertTrue($policy->manual($autopay)->sends());
        $this->assertTrue($policy->manual($scheduled)->sends());
        $this->assertSame(PaymentReminderRefusal::SquareAutopay, $policy->automatic($autopay)->refusal);
        $this->assertSame(PaymentReminderRefusal::SquareSendsReminders, $policy->automatic($scheduled)->refusal);
    }

    /** @return iterable<string, array{string, PaymentReminderStep|null}> */
    public static function cadenceDays(): iterable
    {
        // Due 2030-03-15, issued 2030-03-01.
        yield 'four days before' => ['2030-03-11 10:00', null];
        yield 'three days before' => ['2030-03-12 10:00', PaymentReminderStep::BeforeDue];
        yield 'the day before' => ['2030-03-14 10:00', PaymentReminderStep::BeforeDue];
        yield 'the due date' => ['2030-03-15 10:00', PaymentReminderStep::OnDue];
        yield 'six days overdue' => ['2030-03-21 10:00', PaymentReminderStep::OnDue];
        yield 'seven days overdue' => ['2030-03-22 10:00', PaymentReminderStep::WeekOverdue];
        yield 'fourteen days overdue' => ['2030-03-29 10:00', PaymentReminderStep::TwoWeeksOverdue];
        yield 'long overdue' => ['2030-06-01 10:00', PaymentReminderStep::TwoWeeksOverdue];
    }

    #[DataProvider('cadenceDays')]
    public function test_the_cadence_sends_the_latest_step_that_has_fallen_due(string $now, ?PaymentReminderStep $step): void
    {
        $decision = (new PaymentReminderPolicy)->automatic($this->facts(today: $now));

        if ($step === null) {
            $this->assertSame(PaymentReminderRefusal::NotYetDue, $decision->refusal);

            return;
        }
        $this->assertNull($decision->refusal);
        $this->assertSame($step, $decision->step);
        $this->assertFalse($decision->retry);
        $this->assertSame(PaymentReminderPayLink::SvcPortal, $decision->payLink);
    }

    public function test_each_step_is_sent_once_and_a_passed_step_is_not_sent_late(): void
    {
        $policy = new PaymentReminderPolicy;
        $claimed = [new PaymentReminderClaim(PaymentReminderStep::OnDue, PaymentReminderStatus::Sent, 1, null)];

        $this->assertSame(PaymentReminderRefusal::StepHandled, $policy->automatic($this->facts(today: '2030-03-16 10:00', claims: $claimed))->refusal);
        // A later step is claimed: an earlier one never goes after it.
        $later = [new PaymentReminderClaim(PaymentReminderStep::WeekOverdue, PaymentReminderStatus::Sent, 1, null)];
        $this->assertSame(PaymentReminderRefusal::StepHandled, $policy->automatic($this->facts(today: '2030-03-16 10:00', claims: $later))->refusal);
        // The next step is its own claim.
        $this->assertSame(PaymentReminderStep::WeekOverdue, $policy->automatic($this->facts(today: '2030-03-22 10:00', claims: $claimed))->step);
        foreach ([PaymentReminderStatus::Pending, PaymentReminderStatus::Sending, PaymentReminderStatus::Cancelled] as $status) {
            $this->assertSame(PaymentReminderRefusal::StepHandled, $policy->automatic($this->facts(today: '2030-03-16 10:00', claims: [
                new PaymentReminderClaim(PaymentReminderStep::OnDue, $status, 1, CarbonImmutable::parse('2030-03-15 09:00', 'America/New_York')),
            ]))->refusal, $status->value);
        }
    }

    public function test_a_failed_step_is_retried_when_its_time_comes_until_its_attempts_run_out(): void
    {
        $policy = new PaymentReminderPolicy;
        $failed = static fn (int $attempt, ?string $next): array => [new PaymentReminderClaim(
            PaymentReminderStep::OnDue,
            PaymentReminderStatus::Failed,
            $attempt,
            $next === null ? null : CarbonImmutable::parse($next, 'America/New_York'),
        )];

        $retry = $policy->automatic($this->facts(today: '2030-03-15 11:00', claims: $failed(1, '2030-03-15 11:00')));
        $this->assertSame(PaymentReminderStep::OnDue, $retry->step);
        $this->assertTrue($retry->retry);
        $this->assertSame(PaymentReminderRefusal::StepHandled, $policy->automatic($this->facts(today: '2030-03-15 11:00', claims: $failed(1, '2030-03-15 11:01')))->refusal);
        $this->assertSame(PaymentReminderRefusal::StepHandled, $policy->automatic($this->facts(today: '2030-03-15 11:00', claims: $failed(2, null)))->refusal);
        $this->assertSame(PaymentReminderRefusal::StepHandled, $policy->automatic($this->facts(today: '2030-03-15 11:00', claims: $failed(PaymentReminderPolicy::MAX_AUTOMATIC_ATTEMPTS, '2030-03-15 10:00')))->refusal);
        $this->assertTrue($policy->automatic($this->facts(today: '2030-03-15 11:00', claims: $failed(PaymentReminderPolicy::MAX_AUTOMATIC_ATTEMPTS - 1, '2030-03-15 10:00')))->retry);
    }

    public function test_failed_attempts_back_off_and_then_stop(): void
    {
        $policy = new PaymentReminderPolicy;
        $now = CarbonImmutable::parse('2030-03-15 10:00:00', 'UTC');

        $this->assertSame('2030-03-15 10:30:00', $policy->nextAttemptAt(1, $now)?->toDateTimeString());
        $this->assertSame('2030-03-15 14:00:00', $policy->nextAttemptAt(2, $now)?->toDateTimeString());
        $this->assertNull($policy->nextAttemptAt(3, $now));
        $this->assertNull($policy->nextAttemptAt(4, $now));
    }

    /** @return iterable<string, array{array<string, mixed>, PaymentReminderRefusal}> */
    public static function cadenceHolds(): iterable
    {
        yield 'cadence off' => [['enabledAt' => null], PaymentReminderRefusal::CadenceOff];
        yield 'paid' => [['status' => 'paid'], PaymentReminderRefusal::NotOpen];
        yield 'no due date' => [['dueDate' => null], PaymentReminderRefusal::NoDueDate];
        yield 'nobody to send to' => [['recipients' => []], PaymentReminderRefusal::NoRecipients];
        yield 'square page missing' => [['square' => self::square(url: null)], PaymentReminderRefusal::SquarePageMissing];
        yield 'enabled after the step fell due' => [['enabledAt' => '2030-03-16 08:00'], PaymentReminderRefusal::BeforeCadenceEnabled];
        yield 'issued on the due date' => [['issueDate' => '2030-03-15'], PaymentReminderRefusal::BeforeIssue];
        yield 'before working hours' => [['today' => '2030-03-15 08:59'], PaymentReminderRefusal::OutsideSendingHours];
        yield 'after working hours' => [['today' => '2030-03-15 17:00'], PaymentReminderRefusal::OutsideSendingHours];
        yield 'a manual one in flight' => [['inFlight' => true], PaymentReminderRefusal::InFlight];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('cadenceHolds')]
    public function test_the_cadence_holds_when(array $overrides, PaymentReminderRefusal $refusal): void
    {
        $this->assertSame($refusal, (new PaymentReminderPolicy)->automatic($this->facts(...['today' => '2030-03-15 10:00', ...$overrides]))->refusal);
    }

    public function test_a_step_falling_due_on_the_day_the_cadence_was_enabled_is_sent(): void
    {
        $policy = new PaymentReminderPolicy;

        $this->assertSame(PaymentReminderStep::OnDue, $policy->automatic($this->facts(today: '2030-03-15 16:59', enabledAt: '2030-03-15 16:00'))->step);
        $this->assertSame(PaymentReminderStep::OnDue, $policy->automatic($this->facts(today: '2030-03-15 09:00', issueDate: '2030-03-14'))->step);
    }

    public function test_the_hold_names_what_stops_the_cadence_whatever_the_date(): void
    {
        $policy = new PaymentReminderPolicy;

        $this->assertNull($policy->cadenceHold($this->facts()));
        $this->assertSame(PaymentReminderRefusal::CadenceOff, $policy->cadenceHold($this->facts(enabledAt: null)));
        $this->assertSame(PaymentReminderRefusal::SquareAutopay, $policy->cadenceHold($this->facts(square: self::square(autopay: true))));
        $this->assertSame(PaymentReminderRefusal::SquareNotLinked, $policy->cadenceHold($this->facts(square: self::square(linked: false))));
    }

    public function test_the_last_word_ignores_the_claim_itself_and_sees_what_changed(): void
    {
        $policy = new PaymentReminderPolicy;

        // The reminder being sent is in flight by now; that is not a refusal.
        $this->assertTrue($policy->lastWord($this->facts(inFlight: true), null)->sends());
        // A step already claimed - its own claim - is not a refusal either.
        $own = [new PaymentReminderClaim(PaymentReminderStep::OnDue, PaymentReminderStatus::Sending, 1, null)];
        $step = $policy->lastWord($this->facts(claims: $own, today: '2030-03-15 22:00'), PaymentReminderStep::OnDue);
        $this->assertSame(PaymentReminderStep::OnDue, $step->step);
        $this->assertFalse($step->retry);
        // What changed since the claim stops it.
        $this->assertSame(PaymentReminderRefusal::NotOpen, $policy->lastWord($this->facts(status: 'paid'), null)->refusal);
        $this->assertSame(PaymentReminderRefusal::SquareNotLinked, $policy->lastWord($this->facts(square: self::square(linked: false)), null)->refusal);
        $this->assertSame(PaymentReminderRefusal::CadenceOff, $policy->lastWord($this->facts(enabledAt: null), PaymentReminderStep::OnDue)->refusal);
        $this->assertTrue($policy->lastWord($this->facts(enabledAt: null), null)->sends(), 'A person\'s reminder does not depend on the cadence');
    }

    public function test_the_schedule_shows_each_step_and_where_it_stands(): void
    {
        $schedule = (new PaymentReminderPolicy)->schedule($this->facts(today: '2030-03-22 10:00', claims: [
            new PaymentReminderClaim(PaymentReminderStep::OnDue, PaymentReminderStatus::Sent, 1, null),
        ]));

        $this->assertSame([
            ['due_minus_3', '2030-03-12', 'passed'],
            ['due', '2030-03-15', 'sent'],
            ['due_plus_7', '2030-03-22', 'passed'],
            ['due_plus_14', '2030-03-29', 'upcoming'],
        ], array_map(static fn (array $row): array => [$row['step']->value, $row['scheduled_on']?->toDateString(), $row['state']], $schedule));

        $undated = (new PaymentReminderPolicy)->schedule($this->facts(dueDate: null));
        $this->assertSame(['unscheduled'], array_values(array_unique(array_column($undated, 'state'))));
    }

    public function test_the_cadence_is_ordered_and_every_refusal_explains_itself(): void
    {
        $this->assertSame([-3, 0, 7, 14], array_map(static fn (PaymentReminderStep $step): int => $step->offsetDays(), PaymentReminderStep::cadence()));
        $this->assertSame('2030-03-12', PaymentReminderStep::BeforeDue->scheduledOn(CarbonImmutable::parse('2030-03-15 18:00'))->toDateString());
        foreach (PaymentReminderStep::cases() as $step) {
            $this->assertNotSame('', $step->label());
        }
        foreach (PaymentReminderRefusal::cases() as $refusal) {
            $this->assertStringEndsWith('.', $refusal->message());
            $this->assertSame($refusal === PaymentReminderRefusal::InFlight, $refusal->isRetryableConflict());
        }
    }

    private function assertSends(PaymentReminderDecision $decision, PaymentReminderPayLink $payLink, ?string $url): void
    {
        $this->assertNull($decision->refusal);
        $this->assertTrue($decision->sends());
        $this->assertSame($payLink, $decision->payLink);
        $this->assertSame($url, $decision->payUrl);
        $this->assertNull($decision->step);
        $this->assertFalse($decision->retry);
    }

    private static function square(
        bool $linked = true,
        ?string $status = 'UNPAID',
        ?string $url = self::PAGE,
        bool $autopay = false,
        bool $reminders = false,
        bool $paidOutside = false,
    ): SquareReminderFacts {
        return new SquareReminderFacts($linked, $linked ? '000001-R-0001' : null, $status, $url, $autopay, $reminders, $paidOutside);
    }

    /** @param  list<string>  $recipients
     * @param  list<PaymentReminderClaim>  $claims */
    private function facts(
        string $status = 'issued',
        int $balance = 12_345,
        ?string $issueDate = '2030-03-01',
        ?string $dueDate = '2030-03-15',
        string $today = '2030-03-15 10:00',
        array $recipients = ['billing@synthetic.example.test'],
        ?SquareReminderFacts $square = null,
        ?string $portalUrl = self::PORTAL,
        ?string $enabledAt = '2030-01-01 12:00',
        array $claims = [],
        bool $inFlight = false,
    ): PaymentReminderFacts {
        $zone = 'America/New_York';
        $date = static fn (?string $value): ?CarbonImmutable => $value === null ? null : CarbonImmutable::parse($value, $zone);

        return new PaymentReminderFacts(
            $status,
            $balance,
            $date($issueDate),
            $date($dueDate),
            CarbonImmutable::parse($today, $zone),
            $recipients,
            $square,
            $portalUrl,
            $date($enabledAt),
            $claims,
            $inFlight,
        );
    }
}
