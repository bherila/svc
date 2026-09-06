<?php

namespace App\Support\Billing;

use App\Models\ClientInvoice;
use App\Services\Billing\BillingPeriodCollisionResolver;
use LogicException;

/**
 * The answer {@see BillingPeriodCollisionResolver} gives about one period.
 *
 * A closed type rather than a nullable invoice, because "no invoice" used to
 * mean two very different things at the call site - nothing covers this period,
 * and something covers it that I could not read - and the caller had no way to
 * tell them apart. Naming them separately is what lets the refusal exist at
 * all.
 */
final readonly class PeriodClaim
{
    private function __construct(
        public PeriodClaimVerdict $verdict,
        private ?ClientInvoice $invoice,
        private ?string $refusal,
        public ?PeriodRefusalReason $reason,
    ) {}

    public static function clear(): self
    {
        return new self(PeriodClaimVerdict::Clear, null, null, null);
    }

    public static function alreadyBilled(ClientInvoice $invoice): self
    {
        return new self(PeriodClaimVerdict::AlreadyBilled, $invoice, null, null);
    }

    /**
     * A draft covers exactly this period and has charged nobody yet.
     *
     * Carries the invoice for the same reason `alreadyBilled` does - the caller
     * has to name it - but it is a different answer, and the type keeps the
     * caller from treating it as the same one. See
     * {@see PeriodClaimVerdict::PendingDraft}.
     */
    public static function pendingDraft(ClientInvoice $invoice): self
    {
        return new self(PeriodClaimVerdict::PendingDraft, $invoice, null, null);
    }

    /**
     * @param  string  $refusal  Shown to an operator, so it must say what to fix.
     * @param  PeriodRefusalReason  $reason  The same fact without the identifiers, for anything that counts.
     */
    public static function refused(string $refusal, PeriodRefusalReason $reason): self
    {
        return new self(PeriodClaimVerdict::Refused, null, $refusal, $reason);
    }

    /**
     * The invoice that already covers the period.
     *
     * @throws LogicException unless the verdict was {@see PeriodClaimVerdict::AlreadyBilled}
     *                        or {@see PeriodClaimVerdict::PendingDraft}.
     */
    public function invoice(): ClientInvoice
    {
        if (! $this->invoice instanceof ClientInvoice) {
            throw new LogicException('Only an already-billed or pending-draft claim carries an invoice.');
        }

        return $this->invoice;
    }

    /**
     * Why the period could not be decided.
     *
     * @throws LogicException if the verdict was not {@see PeriodClaimVerdict::Refused}.
     */
    public function refusal(): string
    {
        if ($this->refusal === null) {
            throw new LogicException('Only a refused claim carries a reason.');
        }

        return $this->refusal;
    }

    /**
     * Why the period could not be decided, as a value rather than as prose.
     *
     * @throws LogicException if the verdict was not {@see PeriodClaimVerdict::Refused}.
     */
    public function reason(): PeriodRefusalReason
    {
        if (! $this->reason instanceof PeriodRefusalReason) {
            throw new LogicException('Only a refused claim carries a reason.');
        }

        return $this->reason;
    }
}
