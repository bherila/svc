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
    ) {}

    public static function clear(): self
    {
        return new self(PeriodClaimVerdict::Clear, null, null);
    }

    public static function alreadyBilled(ClientInvoice $invoice): self
    {
        return new self(PeriodClaimVerdict::AlreadyBilled, $invoice, null);
    }

    /**
     * @param  string  $reason  Shown to an operator, so it must say what to fix.
     */
    public static function refused(string $reason): self
    {
        return new self(PeriodClaimVerdict::Refused, null, $reason);
    }

    /**
     * The invoice that already covers the period.
     *
     * @throws LogicException if the verdict was not {@see PeriodClaimVerdict::AlreadyBilled}.
     */
    public function invoice(): ClientInvoice
    {
        if (! $this->invoice instanceof ClientInvoice) {
            throw new LogicException('Only an already-billed claim carries an invoice.');
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
}
