<?php

namespace App\Support\Billing;

use App\Models\ClientInvoicePayment;

/**
 * What a hand-recorded payment change found and did, measured on the locked
 * row - so a caller that reports it (the console command, a dry run) never
 * describes it from a read taken before the lock.
 */
final readonly class ManualPaymentOutcome
{
    public function __construct(
        public ClientInvoicePayment $payment,
        public string $previousStatus,
        public int $previousRefundedAmount,
        public string $lockedVersion,
        public bool $changed,
    ) {}
}
