<?php

namespace App\Services\Square\Audit;

/**
 * Everything the Square billing audit classifies, loaded at once (#438) by
 * {@see SquareBillingAuditRepository}. Database-free.
 *
 * `invoices` holds every mirrored invoice within the audit's reach - a member
 * of a series an agreement is billed in, an invoice of a confirmed customer,
 * an actively linked invoice, or an open one - up to the repository's bound.
 * `complete` is false when that bound was reached, and the counts are then a
 * floor.
 */
final readonly class SquareBillingAuditFacts
{
    /**
     * @param  list<SquareAuditAgreement>  $agreements
     * @param  list<SquareAuditInvoice>  $invoices
     * @param  array<string, true>  $mappedCustomerIds  customers confirmed for a client company
     * @param  array<string, true>  $retiredCustomerIds  customers Square no longer lists
     * @param  array<string, true>  $recordedPaymentIds  those of the invoices' payment ids svc has recorded
     */
    public function __construct(
        public string $today,
        public array $agreements,
        public array $invoices,
        public array $mappedCustomerIds,
        public array $retiredCustomerIds,
        public array $recordedPaymentIds,
        public bool $complete,
    ) {}
}
