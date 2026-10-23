<?php

namespace App\Support\Billing;

/**
 * One row of a hand-recorded payment's refund history, as the reversal rule
 * reads it (#472): read under the payment lock, so {@see ManualPaymentChange}
 * decides without a database.
 *
 * `reversedBy` is the public id of the reversal answering this refund, or null
 * while nothing has; a reversal is never itself answered.
 */
final readonly class RecordedRefund
{
    public function __construct(
        public string $publicId,
        public PaymentRefundKind $kind,
        public int $amount,
        public ?string $reversedBy,
    ) {}
}
