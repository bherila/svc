<?php

namespace App\Support\Billing;

use App\Services\Billing\MissingBilledOverageAuditor;

/**
 * How many charged invoices carry no billed-overage figure at all.
 *
 * Counts only - never a row, an id, an invoice number, a company or a
 * workspace - as a property of this type rather than of the code rendering it,
 * so every consumer is safe against a database of real client billing records
 * without each having to be careful separately.
 *
 * @see MissingBilledOverageAuditor for what each stage
 *      removes and why.
 */
final readonly class MissingBilledOverageCounts
{
    public function __construct(
        public int $invoices,
        public int $withoutABilledOverage,
        public int $chargedOfThose,
        public int $onAnAgreementOfThose,
        public int $agreementsAffected,
    ) {}

    /**
     * @return array{
     *     invoices: int,
     *     without_a_billed_overage: int,
     *     charged_of_those: int,
     *     on_an_agreement_of_those: int,
     *     agreements_affected: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'invoices' => $this->invoices,
            'without_a_billed_overage' => $this->withoutABilledOverage,
            'charged_of_those' => $this->chargedOfThose,
            'on_an_agreement_of_those' => $this->onAnAgreementOfThose,
            'agreements_affected' => $this->agreementsAffected,
        ];
    }
}
