<?php

namespace App\Support\Billing\Reminders;

/**
 * Why a payment reminder is not sent (#438), as a stable code an agent can
 * branch on and a sentence an operator can act on.
 */
enum PaymentReminderRefusal: string
{
    // Any reminder.
    case NotOpen = 'not_open';
    case NothingDue = 'nothing_due';
    case NoRecipients = 'no_recipients';
    case InFlight = 'in_flight';
    case SquareNotLinked = 'square_not_linked';
    case SquarePageMissing = 'square_page_missing';
    case SquareNotOpen = 'square_not_open';
    case PaidOutsideSquare = 'paid_outside_square';

    // The automatic cadence only.
    case CadenceOff = 'cadence_off';
    case NoDueDate = 'no_due_date';
    case SquareAutopay = 'square_autopay';
    case SquareSendsReminders = 'square_sends_reminders';
    case NotYetDue = 'not_yet_due';
    case StepHandled = 'step_handled';
    case BeforeCadenceEnabled = 'before_cadence_enabled';
    case BeforeIssue = 'before_issue';
    case OutsideSendingHours = 'outside_sending_hours';

    public function message(): string
    {
        return match ($this) {
            self::NotOpen => 'Only an issued or partially paid invoice can be reminded about.',
            self::NothingDue => 'The invoice has no balance left to pay.',
            self::NoRecipients => 'The client has no valid billing email or portal user to send a reminder to.',
            self::InFlight => 'Another reminder for this invoice is being sent. Reload and try again.',
            self::SquareNotLinked => 'Square collects this invoice through its agreement, but the invoice is not linked to a Square invoice, so there is no Square payment page to send. Link it to its Square invoice first.',
            self::SquarePageMissing => 'The linked Square invoice has no Square payment page in SVC\'s Square mirror. Sync Square and try again; SVC never sends its own payment link for an invoice Square collects.',
            self::SquareNotOpen => 'Square does not show the linked invoice as unpaid or partially paid, so there is nothing to pay on its Square page.',
            self::PaidOutsideSquare => 'A payment recorded outside Square is not reflected in Square\'s balance, so its payment page would ask for too much. Close or adjust the invoice in Square first.',
            self::CadenceOff => 'Automatic payment reminders are off for this client.',
            self::NoDueDate => 'The invoice has no due date, so no reminder step falls due.',
            self::SquareAutopay => 'Square charges the card on file for this invoice automatically, so SVC does not remind about it on a schedule.',
            self::SquareSendsReminders => 'Square already sends its own reminders for this invoice, so SVC does not duplicate them on a schedule.',
            self::NotYetDue => 'No reminder step has fallen due yet.',
            self::StepHandled => 'The reminder for the current step has already been sent or attempted.',
            self::BeforeCadenceEnabled => 'The current step fell due before automatic reminders were turned on for this client.',
            self::BeforeIssue => 'The current step fell due before the invoice was issued.',
            self::OutsideSendingHours => 'Automatic reminders are sent between 09:00 and 17:00 in the workspace\'s time zone.',
        };
    }

    /** State moved under the request: the same request may succeed when retried. */
    public function isRetryableConflict(): bool
    {
        return match ($this) {
            self::InFlight => true,
            self::NotOpen, self::NothingDue, self::NoRecipients, self::SquareNotLinked, self::SquarePageMissing,
            self::SquareNotOpen, self::PaidOutsideSquare, self::CadenceOff, self::NoDueDate, self::SquareAutopay,
            self::SquareSendsReminders, self::NotYetDue, self::StepHandled, self::BeforeCadenceEnabled,
            self::BeforeIssue, self::OutsideSendingHours => false,
        };
    }
}
