<?php

declare(strict_types=1);

namespace App\Support\Approval;

/**
 * Where a row stands with the client who pays for it.
 *
 * Read from the stored facts every time, never cached: a payment approval is
 * only as good as the invoice's payment, so a refund or a reopen undoes it
 * without anything writing (UX overhaul section 7b).
 */
enum ClientApprovalVerdict: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case ApprovedByPayment = 'approved_by_payment';
    case Disputed = 'disputed';

    /**
     * @param  string|null  $source  `portal` or `payment`; anything else is unread
     * @param  bool  $invoicePaid  whether an invoice carrying the row is paid in full now
     */
    public static function judge(?string $source, bool $approved, bool $disputed, bool $invoicePaid): self
    {
        // A dispute is a person's statement and outranks any approval left
        // beside it; the writers clear the other side, this is the backstop.
        if ($disputed) {
            return self::Disputed;
        }
        if (! $approved) {
            return self::Pending;
        }

        return match ($source) {
            ClientApprovalSource::Portal->value => self::Approved,
            ClientApprovalSource::Payment->value => $invoicePaid ? self::ApprovedByPayment : self::Pending,
            default => self::Pending,
        };
    }

    /** Is the client's approval in force? */
    public function approves(): bool
    {
        return match ($this) {
            self::Approved, self::ApprovedByPayment => true,
            self::Pending, self::Disputed => false,
        };
    }

    /** Does the client still owe a decision on it? */
    public function awaitsClient(): bool
    {
        return match ($this) {
            self::Pending => true,
            self::Approved, self::ApprovedByPayment, self::Disputed => false,
        };
    }
}
