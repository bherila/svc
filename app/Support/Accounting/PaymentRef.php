<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/** The payment, invoice and client a cash entry belongs to, by public id. */
final readonly class PaymentRef
{
    public function __construct(
        public string $paymentId,
        public string $invoiceId,
        public string $invoiceNumber,
        public string $clientId,
        public string $currency,
    ) {}
}
