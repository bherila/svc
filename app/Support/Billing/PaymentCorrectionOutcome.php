<?php

namespace App\Support\Billing;

use App\Models\ClientInvoicePayment;

/**
 * What a payment correction found and did, measured on the locked row.
 *
 * A caller that read the payment before calling cannot report from that read:
 * another write can land between it and the lock. The before/after and the
 * version the row had when it was locked are therefore taken inside the
 * correction, where nothing else can move them.
 */
final readonly class PaymentCorrectionOutcome
{
    /**
     * @param  array<string, array{old: string|null, new: string|null}>  $changes  only the fields that changed
     * @param  string  $lockedVersion  the row's opaque version as locked, before this correction wrote
     */
    public function __construct(
        public ClientInvoicePayment $payment,
        public array $changes,
        public string $lockedVersion,
    ) {}
}
