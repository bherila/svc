<?php

declare(strict_types=1);

namespace App\Support\Billing\Autopay;

use App\Support\Billing\InvoicePaymentStatus;

/** A PaymentIntent read back from Stripe and checked against a held autopay attempt (#641). */
final readonly class AutopayVerifiedIntent
{
    public function __construct(
        public string $id,
        public int $amount,
        public string $currency,
        /** What the ledger row starts as: pending while Stripe is still processing, succeeded once it has charged. */
        public InvoicePaymentStatus $status,
    ) {}
}
