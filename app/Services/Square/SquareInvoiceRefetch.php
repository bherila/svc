<?php

namespace App\Services\Square;

use App\Models\InvoiceSquareLink;
use App\Support\Square\SquarePaymentFacts;

/**
 * What {@see SquarePaymentReconciler::refetch()} read back from Square for one
 * invoice: its completed payments, checked against the order, and the active
 * link new payments are recorded against (null once unlinked, when only
 * refunds of payments already recorded apply).
 */
final readonly class SquareInvoiceRefetch
{
    /** @param  list<SquarePaymentFacts>  $payments */
    public function __construct(
        public ?InvoiceSquareLink $activeLink,
        public array $payments,
    ) {}
}
