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
    /** The provider settled the client's dispute by writing the row off; it is never billed. */
    case WrittenOff = 'written_off';

    /**
     * @param  string|null  $source  `portal`, `reviewer` or `payment`; anything else is unread
     * @param  bool  $invoicePaid  whether an invoice carrying the row is paid in full now
     * @param  bool  $writtenOff  the provider settled a dispute on it by writing it off
     */
    public static function judge(?string $source, bool $approved, bool $disputed, bool $invoicePaid, bool $writtenOff): self
    {
        // Settled for good: nothing is left for the client to decide, and
        // nothing will be billed (docs/ux-overhaul.md 7b″).
        if ($writtenOff) {
            return self::WrittenOff;
        }
        // A dispute is a person's statement and outranks any approval left
        // beside it; the writers clear the other side, this is the backstop.
        if ($disputed) {
            return self::Disputed;
        }
        if (! $approved) {
            return self::Pending;
        }

        return match ($source) {
            ClientApprovalSource::Portal->value, ClientApprovalSource::Reviewer->value => self::Approved,
            ClientApprovalSource::Payment->value => $invoicePaid ? self::ApprovedByPayment : self::Pending,
            default => self::Pending,
        };
    }

    /** Is the client's approval in force? */
    public function approves(): bool
    {
        return match ($this) {
            self::Approved, self::ApprovedByPayment => true,
            self::Pending, self::Disputed, self::WrittenOff => false,
        };
    }

    /** Does the client still owe a decision on it? */
    public function awaitsClient(): bool
    {
        return match ($this) {
            self::Pending => true,
            self::Approved, self::ApprovedByPayment, self::Disputed, self::WrittenOff => false,
        };
    }
}
