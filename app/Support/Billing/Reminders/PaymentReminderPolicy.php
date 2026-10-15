<?php

namespace App\Support\Billing\Reminders;

use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;

/**
 * Who may be reminded about which invoice, with which pay link (#438).
 *
 * Database-free: everything is decided from {@see PaymentReminderFacts}, which
 * the service loads under the invoice lock and again just before a message
 * leaves. The rules, in the order they are asked:
 *
 * 1. Only an issued or partially paid invoice with a balance is reminded
 *    about, and only to the client's billing address and portal users. A paid
 *    invoice stops every cadence.
 * 2. An invoice Square collects - linked, or under an agreement billed in
 *    Square - carries Square's own payment page and nothing of SVC's. Without a
 *    usable page (not linked, not in the mirror, not open in Square, or money
 *    taken outside Square that Square's balance does not reflect) there is no
 *    reminder: SVC never falls back to its own collection for it.
 * 3. Any other invoice links to the client portal where the client can sign in
 *    to read it, when they could, and otherwise carries the PDF.
 * 4. The automatic cadence additionally needs the client switched on, a due
 *    date, a step that has fallen due since both the switch and the issue
 *    date, no earlier claim on that step, working hours, and no autopay or
 *    reminder schedule of Square's own that it would duplicate. Only the latest
 *    step that has fallen due is sent; earlier ones it passed are not.
 */
final class PaymentReminderPolicy
{
    /** Attempts an automatic step gets before its failure is final. */
    public const int MAX_AUTOMATIC_ATTEMPTS = 3;

    /** Local hours automatic reminders are sent in: from 09:00 until 17:00. */
    public const int SENDING_STARTS_AT_HOUR = 9;

    public const int SENDING_ENDS_AT_HOUR = 17;

    /** Square statuses with money still to pay on Square's page. */
    public const array OPEN_SQUARE_STATUSES = ['UNPAID', 'PARTIALLY_PAID'];

    /** A person asked: everything but the cadence's own rules. */
    public function manual(PaymentReminderFacts $facts): PaymentReminderDecision
    {
        $refusal = $this->invoiceRefusal($facts) ?? ($facts->inFlight ? PaymentReminderRefusal::InFlight : null);

        return $refusal !== null ? PaymentReminderDecision::refuse($refusal) : $this->payLink($facts);
    }

    /** The scheduler asked: the step to send now, or why nothing is sent. */
    public function automatic(PaymentReminderFacts $facts): PaymentReminderDecision
    {
        $hold = $this->cadenceHold($facts);
        if ($hold !== null) {
            return PaymentReminderDecision::refuse($hold);
        }
        /** @var CarbonImmutable $dueDate cadenceHold() refuses an invoice without one */
        $dueDate = $facts->dueDate;

        $current = null;
        foreach (PaymentReminderStep::cadence() as $step) {
            if ($step->scheduledOn($dueDate)->toDateString() <= $facts->today()->toDateString()) {
                $current = $step;
            }
        }
        if ($current === null) {
            return PaymentReminderDecision::refuse(PaymentReminderRefusal::NotYetDue);
        }

        $retry = false;
        $claim = $facts->claimFor($current);
        if ($claim !== null) {
            if (! $this->retryable($claim, $facts->now)) {
                return PaymentReminderDecision::refuse(PaymentReminderRefusal::StepHandled);
            }
            $retry = true;
        } elseif ($this->laterStepClaimed($facts, $current)) {
            return PaymentReminderDecision::refuse(PaymentReminderRefusal::StepHandled);
        }

        $scheduledOn = $current->scheduledOn($dueDate)->toDateString();
        /** @var CarbonImmutable $enabledAt cadenceHold() refuses a client without one */
        $enabledAt = $facts->cadenceEnabledAt;
        if ($scheduledOn < $enabledAt->toDateString()) {
            return PaymentReminderDecision::refuse(PaymentReminderRefusal::BeforeCadenceEnabled);
        }
        if ($facts->issueDate !== null && $scheduledOn <= $facts->issueDate->toDateString()) {
            return PaymentReminderDecision::refuse(PaymentReminderRefusal::BeforeIssue);
        }
        if (! $this->withinSendingHours($facts->now)) {
            return PaymentReminderDecision::refuse(PaymentReminderRefusal::OutsideSendingHours);
        }
        if ($facts->inFlight) {
            return PaymentReminderDecision::refuse(PaymentReminderRefusal::InFlight);
        }

        return $this->payLink($facts)->atStep($current, $retry);
    }

    /**
     * Asked again right before a claimed reminder leaves, outside every lock:
     * whatever changed since the claim - a payment, a void, a new or removed
     * Square link - is seen here. The claim itself is in flight by now, so
     * neither in-flight nor the step's own claim counts against it.
     */
    public function lastWord(PaymentReminderFacts $facts, ?PaymentReminderStep $step): PaymentReminderDecision
    {
        $refusal = $step === null ? $this->invoiceRefusal($facts) : $this->cadenceHold($facts);
        if ($refusal !== null) {
            return PaymentReminderDecision::refuse($refusal);
        }
        $decision = $this->payLink($facts);

        return $step === null ? $decision : $decision->atStep($step, false);
    }

    /**
     * Why the cadence will not send for this invoice at all, whatever the
     * date: null when a step will be sent once it falls due.
     */
    public function cadenceHold(PaymentReminderFacts $facts): ?PaymentReminderRefusal
    {
        if ($facts->cadenceEnabledAt === null) {
            return PaymentReminderRefusal::CadenceOff;
        }
        $refusal = $this->invoiceRefusal($facts);
        if ($refusal !== null) {
            return $refusal;
        }
        if ($facts->dueDate === null) {
            return PaymentReminderRefusal::NoDueDate;
        }
        if ($facts->square !== null && $facts->square->autopay) {
            return PaymentReminderRefusal::SquareAutopay;
        }
        if ($facts->square !== null && $facts->square->squareSendsReminders) {
            return PaymentReminderRefusal::SquareSendsReminders;
        }

        return $this->payLink($facts)->refusal;
    }

    /**
     * Each cadence step, the day it falls due and where it stands.
     *
     * @return list<array{step: PaymentReminderStep, scheduled_on: CarbonImmutable|null, state: string}>
     */
    public function schedule(PaymentReminderFacts $facts): array
    {
        $rows = [];
        foreach (PaymentReminderStep::cadence() as $step) {
            $scheduledOn = $facts->dueDate === null ? null : $step->scheduledOn($facts->dueDate);
            $claim = $facts->claimFor($step);
            $state = match (true) {
                $claim !== null => $claim->status->value,
                $scheduledOn === null => 'unscheduled',
                $scheduledOn->toDateString() > $facts->today()->toDateString() => 'upcoming',
                default => 'passed',
            };
            $rows[] = ['step' => $step, 'scheduled_on' => $scheduledOn, 'state' => $state];
        }

        return $rows;
    }

    /** When a failed automatic attempt is next tried, or null when it is final. */
    public function nextAttemptAt(int $attemptNumber, CarbonImmutable $now): ?CarbonImmutable
    {
        return match (true) {
            $attemptNumber >= self::MAX_AUTOMATIC_ATTEMPTS => null,
            $attemptNumber <= 1 => $now->addMinutes(30),
            default => $now->addHours(4),
        };
    }

    private function invoiceRefusal(PaymentReminderFacts $facts): ?PaymentReminderRefusal
    {
        $open = match (InvoiceStatus::tryFrom($facts->invoiceStatus)) {
            InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid => true,
            InvoiceStatus::Draft, InvoiceStatus::Paid, InvoiceStatus::Void, null => false,
        };

        return match (true) {
            ! $open => PaymentReminderRefusal::NotOpen,
            $facts->balanceAmount <= 0 => PaymentReminderRefusal::NothingDue,
            $facts->recipients === [] => PaymentReminderRefusal::NoRecipients,
            default => null,
        };
    }

    private function payLink(PaymentReminderFacts $facts): PaymentReminderDecision
    {
        $square = $facts->square;
        if ($square === null) {
            return $facts->portalUrl === null
                ? PaymentReminderDecision::send(PaymentReminderPayLink::None, null, null)
                : PaymentReminderDecision::send(PaymentReminderPayLink::SvcPortal, $facts->portalUrl, null);
        }

        $refusal = match (true) {
            ! $square->linked => PaymentReminderRefusal::SquareNotLinked,
            $square->paidOutsideSquare => PaymentReminderRefusal::PaidOutsideSquare,
            $square->squareStatus === null, $square->payUrl === null => PaymentReminderRefusal::SquarePageMissing,
            ! in_array($square->squareStatus, self::OPEN_SQUARE_STATUSES, true) => PaymentReminderRefusal::SquareNotOpen,
            default => null,
        };

        return $refusal !== null
            ? PaymentReminderDecision::refuse($refusal)
            : PaymentReminderDecision::send(PaymentReminderPayLink::Square, $square->payUrl, $square->squareInvoiceNumber);
    }

    private function retryable(PaymentReminderClaim $claim, CarbonImmutable $now): bool
    {
        return $claim->status === PaymentReminderStatus::Failed
            && $claim->attemptNumber < self::MAX_AUTOMATIC_ATTEMPTS
            && $claim->nextAttemptAt !== null
            && $claim->nextAttemptAt->lessThanOrEqualTo($now);
    }

    private function laterStepClaimed(PaymentReminderFacts $facts, PaymentReminderStep $current): bool
    {
        foreach ($facts->claims as $claim) {
            /** @infection-ignore-all Asked only once the current step has no claim, so `>=` and `>` cannot differ here. */
            if ($claim->step->offsetDays() > $current->offsetDays()) {
                return true;
            }
        }

        return false;
    }

    private function withinSendingHours(CarbonImmutable $now): bool
    {
        return $now->hour >= self::SENDING_STARTS_AT_HOUR && $now->hour < self::SENDING_ENDS_AT_HOUR;
    }
}
