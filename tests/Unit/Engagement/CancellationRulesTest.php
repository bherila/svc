<?php

namespace Tests\Unit\Engagement;

use App\Services\Engagement\OperatorCancellationPanel;
use App\Support\Engagement\Cancellation\CancellationRefusal;
use App\Support\Engagement\Cancellation\CancellationRequestStatus;
use App\Support\Engagement\Cancellation\CancellationRules;
use App\Support\Engagement\Cancellation\ScheduledTerminationSource;
use App\Support\Engagement\Cancellation\ScheduledTerminationStatus;
use App\Support\Packages\CancellationMode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * The cancellation decisions (CAN-2 to CAN-5, EDGE-9): who may cancel, decide
 * and undo, when an end is due, and how an owner's date options are ordered.
 * They are pure functions of enums and dates, so this is plain PHPUnit: no
 * application boot, which every mutation-lane process would otherwise pay
 * once per mutant.
 */
final class CancellationRulesTest extends TestCase
{
    // ── Who may cancel ──────────────────────────────────────────────────────

    /** @return iterable<string, array{CancellationMode, string, bool, bool, bool, CancellationRefusal|null}> */
    public static function clientCases(): iterable
    {
        yield 'self-serve, active, nothing open' => [CancellationMode::SelfServe, 'active', true, false, false, null];
        yield 'request, active, nothing open' => [CancellationMode::Request, 'active', true, false, false, null];
        yield 'none offers nothing' => [CancellationMode::None, 'active', true, false, false, CancellationRefusal::NotOffered];
        yield 'a draft is not cancelled' => [CancellationMode::SelfServe, 'draft', true, false, false, CancellationRefusal::AgreementNotActive];
        yield 'a paused agreement is not cancelled' => [CancellationMode::Request, 'paused', true, false, false, CancellationRefusal::AgreementNotActive];
        yield 'a terminated agreement, in none mode, is not active first' => [CancellationMode::None, 'terminated', true, false, false, CancellationRefusal::AgreementNotActive];
        yield 'one-time' => [CancellationMode::SelfServe, 'active', false, false, false, CancellationRefusal::NotRecurring];
        yield 'already scheduled' => [CancellationMode::SelfServe, 'active', true, true, false, CancellationRefusal::AlreadyScheduled];
        yield 'already requested' => [CancellationMode::Request, 'active', true, false, true, CancellationRefusal::RequestPending];
        yield 'a pending request is named before the cadence' => [CancellationMode::Request, 'active', false, false, true, CancellationRefusal::RequestPending];
    }

    #[DataProvider('clientCases')]
    #[Requirement('CAN-3')]
    public function test_who_may_cancel_from_the_portal(CancellationMode $mode, string $status, bool $recurring, bool $scheduled, bool $pending, ?CancellationRefusal $expected): void
    {
        $this->assertSame($expected, CancellationRules::clientRefusal($mode, $status, $recurring, $scheduled, $pending));
    }

    /** @return iterable<string, array{string, bool, bool, CancellationRefusal|null}> */
    public static function scheduleCases(): iterable
    {
        yield 'active, recurring, nothing scheduled' => ['active', true, false, null];
        yield 'not active' => ['draft', true, false, CancellationRefusal::AgreementNotActive];
        yield 'not recurring' => ['active', false, false, CancellationRefusal::NotRecurring];
        yield 'already scheduled' => ['active', true, true, CancellationRefusal::AlreadyScheduled];
    }

    #[DataProvider('scheduleCases')]
    #[Requirement('CAN-4')]
    public function test_when_an_owner_may_schedule_an_end(string $status, bool $recurring, bool $scheduled, ?CancellationRefusal $expected): void
    {
        $this->assertSame($expected, CancellationRules::scheduleRefusal($status, $recurring, $scheduled));
    }

    // ── EDGE-9: undoing and reaching a scheduled end ────────────────────────

    /** @return iterable<string, array{ScheduledTerminationStatus|null, ScheduledTerminationSource|null, CancellationMode, bool, string, string|null, CancellationRefusal|null}> */
    public static function undoCases(): iterable
    {
        $scheduled = ScheduledTerminationStatus::Scheduled;
        yield 'the contact undoes their self-serve end' => [$scheduled, ScheduledTerminationSource::SelfServe, CancellationMode::SelfServe, false, '2026-12-01', '2026-12-31', null];
        yield 'on the end date itself' => [$scheduled, ScheduledTerminationSource::SelfServe, CancellationMode::SelfServe, false, '2026-12-31', '2026-12-31', null];
        yield 'the day after it' => [$scheduled, ScheduledTerminationSource::SelfServe, CancellationMode::SelfServe, false, '2027-01-01', '2026-12-31', CancellationRefusal::EndReached];
        yield 'a contact, an approved request' => [$scheduled, ScheduledTerminationSource::Request, CancellationMode::Request, false, '2026-12-01', '2026-12-31', CancellationRefusal::UndoNotSelfServe];
        yield 'a contact, an approved request in a mode since made self-serve' => [$scheduled, ScheduledTerminationSource::Request, CancellationMode::SelfServe, false, '2026-12-01', '2026-12-31', CancellationRefusal::UndoNotSelfServe];
        yield 'a contact, an owner-scheduled end' => [$scheduled, ScheduledTerminationSource::Operator, CancellationMode::SelfServe, false, '2026-12-01', '2026-12-31', CancellationRefusal::UndoNotSelfServe];
        yield 'a contact, their own end, the mode since moved to request' => [$scheduled, ScheduledTerminationSource::SelfServe, CancellationMode::Request, false, '2026-12-01', '2026-12-31', CancellationRefusal::UndoNotSelfServe];
        yield 'an owner, an approved request' => [$scheduled, ScheduledTerminationSource::Request, CancellationMode::Request, true, '2026-12-01', '2026-12-31', null];
        yield 'an owner, none mode' => [$scheduled, ScheduledTerminationSource::Operator, CancellationMode::None, true, '2026-12-31', '2026-12-31', null];
        yield 'an owner, after the date' => [$scheduled, ScheduledTerminationSource::Operator, CancellationMode::None, true, '2027-01-01', '2026-12-31', CancellationRefusal::EndReached];
        yield 'nothing scheduled' => [null, null, CancellationMode::SelfServe, true, '2026-12-01', null, CancellationRefusal::NotScheduled];
        yield 'already undone' => [ScheduledTerminationStatus::Undone, ScheduledTerminationSource::SelfServe, CancellationMode::SelfServe, false, '2026-12-01', '2026-12-31', CancellationRefusal::NotScheduled];
        yield 'already terminated' => [ScheduledTerminationStatus::Terminated, ScheduledTerminationSource::SelfServe, CancellationMode::SelfServe, true, '2026-12-01', '2026-12-31', CancellationRefusal::NotScheduled];
    }

    #[DataProvider('undoCases')]
    #[Requirement('EDGE-9')]
    public function test_who_may_undo_a_scheduled_end_and_until_when(?ScheduledTerminationStatus $status, ?ScheduledTerminationSource $source, CancellationMode $mode, bool $byManager, string $today, ?string $endsOn, ?CancellationRefusal $expected): void
    {
        $this->assertSame($expected, CancellationRules::undoRefusal($status, $source, $mode, $byManager, CarbonImmutable::parse($today), $endsOn === null ? null : CarbonImmutable::parse($endsOn)));
    }

    /** @return iterable<string, array{ScheduledTerminationStatus, string, bool}> */
    public static function dueCases(): iterable
    {
        yield 'the day before' => [ScheduledTerminationStatus::Scheduled, '2026-12-30', false];
        yield 'on the date' => [ScheduledTerminationStatus::Scheduled, '2026-12-31', true];
        yield 'missed and caught up' => [ScheduledTerminationStatus::Scheduled, '2027-01-02', true];
        yield 'undone' => [ScheduledTerminationStatus::Undone, '2026-12-31', false];
        yield 'already terminated' => [ScheduledTerminationStatus::Terminated, '2027-01-02', false];
    }

    #[DataProvider('dueCases')]
    #[Requirement('CAN-5', 'EDGE-9')]
    public function test_a_scheduled_end_is_due_on_its_date_and_after(ScheduledTerminationStatus $status, string $today, bool $due): void
    {
        $this->assertSame($due, CancellationRules::isDue($status, CarbonImmutable::parse('2026-12-31'), CarbonImmutable::parse($today)));
    }

    #[Requirement('CAN-4')]
    public function test_only_a_pending_request_is_open(): void
    {
        $this->assertSame([true, false, false, false], array_map(static fn (CancellationRequestStatus $status): bool => $status->isOpen(), [
            CancellationRequestStatus::Pending, CancellationRequestStatus::Approved, CancellationRequestStatus::Declined, CancellationRequestStatus::Withdrawn,
        ]));
    }

    #[Requirement('CAN-2')]
    public function test_every_refusal_says_what_to_do(): void
    {
        foreach (CancellationRefusal::cases() as $refusal) {
            $this->assertStringStartsWith('cancellation_', $refusal->value);
            $this->assertNotSame('', $refusal->message());
        }
        $this->assertSame('That end date is not one of the dates offered. Choose one of the offered dates.', CancellationRefusal::DateNotOffered->message());
    }

    #[Requirement('CAN-4')]
    public function test_an_owner_is_offered_the_notice_dates_and_the_earlier_ones_waiving_allows_in_order(): void
    {
        $this->assertSame([
            ['value' => '2026-11-30', 'waives_notice' => true],
            ['value' => '2026-12-31', 'waives_notice' => false],
            ['value' => '2027-01-31', 'waives_notice' => false],
            ['value' => '2027-02-28', 'waives_notice' => false],
        ], OperatorCancellationPanel::options(['2026-12-31', '2027-01-31', '2027-02-28'], ['2026-11-30', '2026-12-31', '2027-01-31']));
        $this->assertSame([], OperatorCancellationPanel::options([], []));
    }
}
