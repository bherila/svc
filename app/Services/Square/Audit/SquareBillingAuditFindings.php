<?php

namespace App\Services\Square\Audit;

/**
 * What {@see SquareBillingAuditPolicy} found, every finding in order (#438).
 * `complete` is false when the facts were bounded, so every list is a floor.
 */
final readonly class SquareBillingAuditFindings
{
    /**
     * @param  list<array{agreement: SquareAuditAgreement, invoice: SquareAuditInvoice}>  $unmirroredPeriods
     * @param  list<array{invoice: SquareAuditInvoice, payment_id: string, reason: string}>  $unmatchedPayments
     * @param  list<array{customer_id: string, recipient_name: string|null, open_invoice_count: int, currency: string|null, open_amount: int|null}>  $unlinkedCustomerBalances
     * @param  list<array{agreement: SquareAuditAgreement, codes: non-empty-list<string>, series_cadence: string|null, series_currency: string|null, series_amount: int|null}>  $seriesDrift
     */
    public function __construct(
        public array $unmirroredPeriods,
        public array $unmatchedPayments,
        public array $unlinkedCustomerBalances,
        public array $seriesDrift,
        public bool $complete,
    ) {}
}
