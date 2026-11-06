<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use App\Models\ClientInvoicePayment;
use Carbon\CarbonImmutable;

/**
 * One received payment as the collected basis counts it: what it adds to its
 * invoice net of refunds and of any amount a dispute holds back
 * ({@see ClientInvoicePayment::paidContribution()}), on the day it
 * was received. Tax included, as received; {@see RevenueActualsInputs} takes
 * the tax out by the invoice's own composition.
 */
final readonly class CollectedPayment
{
    public function __construct(
        public int $clientId,
        public int $invoiceId,
        public CarbonImmutable $receivedOn,
        public int $amount,
    ) {}
}
