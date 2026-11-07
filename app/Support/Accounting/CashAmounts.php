<?php

namespace App\Support\Accounting;

/** The signed integer minor-unit facts one cash row contributes to an accounting read. */
final readonly class CashAmounts
{
    public function __construct(
        public string $kind,
        public int $amount,
        public int $feeAmount,
        public int $refundedAmount,
        public int $unattributedRefundAmount,
    ) {}
}
