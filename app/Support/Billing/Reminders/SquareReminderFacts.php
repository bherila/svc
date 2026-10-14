<?php

namespace App\Support\Billing\Reminders;

/**
 * What a reminder needs to know about an invoice Square collects (#438):
 * present only when the invoice is linked to a Square invoice or its agreement
 * is billed in Square.
 */
final readonly class SquareReminderFacts
{
    /**
     * @param  bool  $linked  an active link names the Square invoice
     * @param  string|null  $squareStatus  the mirror's Square status, null when the mirror has not seen it
     * @param  string|null  $payUrl  Square's own view-and-pay page, already checked to be HTTPS on a Square host
     * @param  bool  $autopay  Square charges a card on file for it
     * @param  bool  $squareSendsReminders  Square's own payment request carries a reminder schedule
     * @param  bool  $paidOutsideSquare  SVC holds money for it that did not come through Square
     */
    public function __construct(
        public bool $linked,
        public ?string $squareInvoiceNumber,
        public ?string $squareStatus,
        public ?string $payUrl,
        public bool $autopay,
        public bool $squareSendsReminders,
        public bool $paidOutsideSquare,
    ) {}
}
