<?php

namespace App\Support\Finance;

/**
 * Why a finance reconciliation allocation needs the finance system to look at
 * it again.
 *
 * A payment processor's refund or dispute is a fact about money that has
 * already moved, so it is recorded whatever the payment's allocations say. The
 * allocations are kept exactly as the finance system wrote them - a receipt
 * matched to a deposit is still that receipt after part of it is refunded, and
 * with several allocations there is no principled one to shrink - and each
 * active one is marked with the reason instead. A finance write that fits the
 * payment's net amount clears it. Explicit acknowledgement of the exact
 * current payment revision, review reason and instant also clears it without
 * changing the allocation's money or activity.
 */
enum ReconciliationReviewReason: string
{
    case ProcessorRefund = 'processor_refund';
    case ProcessorDispute = 'processor_dispute';
}
