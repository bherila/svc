<?php

namespace App\Support\Packages;

use App\Models\ClientInvoice;

/**
 * What one automatic invoicing attempt for one agreement and billing period
 * came to (INV-1). An outcome is what the enrollment records; a skip records
 * nothing, because nothing happened that an owner needs to know about.
 */
final readonly class AutoInvoiceResult
{
    /** The period was invoiced before this attempt reached its lock - by an earlier or concurrent run, or an owner. */
    public const string ALREADY_INVOICED = 'already_invoiced';

    /** The enrollment or agreement no longer qualifies (no longer active, no longer automatic, or the period is no longer one it bills). */
    public const string NOT_DUE = 'not_due';

    /**
     * A move to a new version is due on or before the period's start and has
     * not applied yet (MIG-3): the period is the new terms' to bill, after
     * the move.
     */
    public const string WAITS_FOR_MOVE = 'waits_for_move';

    private function __construct(
        public ?AutoInvoiceOutcome $outcome,
        public ?string $skipped,
        public ?AutoInvoiceRefusal $refusal = null,
        public ?string $detail = null,
        public ?ClientInvoice $invoice = null,
    ) {}

    public static function issued(ClientInvoice $invoice): self
    {
        return new self(AutoInvoiceOutcome::Issued, null, invoice: $invoice);
    }

    public static function nothingToBill(): self
    {
        return new self(AutoInvoiceOutcome::NothingToBill, null);
    }

    public static function covered(): self
    {
        return new self(AutoInvoiceOutcome::Covered, null);
    }

    public static function refused(AutoInvoiceRefusal $refusal, ?string $detail = null): self
    {
        return new self(AutoInvoiceOutcome::Refused, null, $refusal, $detail === null ? null : mb_substr($detail, 0, 500));
    }

    /** @param self::ALREADY_INVOICED|self::NOT_DUE|self::WAITS_FOR_MOVE $why */
    public static function skipped(string $why): self
    {
        return new self(null, $why);
    }

    /** The name the command reports it by. */
    public function label(): string
    {
        return $this->outcome->value ?? (string) $this->skipped;
    }
}
