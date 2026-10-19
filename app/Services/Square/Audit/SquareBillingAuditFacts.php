<?php

namespace App\Services\Square\Audit;

/**
 * Everything the Square billing audit classifies, loaded at once (#438) by
 * {@see SquareBillingAuditRepository}. Database-free.
 *
 * `invoices` holds every mirrored invoice within the audit's reach - a member
 * of a series an agreement is billed in, an invoice of a confirmed customer,
 * an actively linked invoice, or an open one - up to the repository's bound.
 * `complete` is false when a bound was reached, and the counts are then a
 * floor. `seriesComplete` says every member of every billed-in-Square series
 * was loaded - members load first, under their own bound - so each series is
 * whole and one with none loaded is truly absent from the mirror.
 * `reachComplete` says every other invoice in reach was loaded, so a
 * customer's open invoices are all there. `mirrorComplete` says the last
 * completed sync listed every Square invoice; without it nothing missing
 * from the mirror is missing from Square.
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
        public bool $seriesComplete = true,
        public bool $reachComplete = true,
        public bool $mirrorComplete = true,
    ) {}
}
