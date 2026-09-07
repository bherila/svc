<?php

namespace App\Support\Billing;

use App\Models\ClientInvoice;
use App\Services\Billing\BillingScheduleService;

/**
 * One due period that {@see BillingScheduleService::generateDue()} has decided
 * it can go through with, and what it will do about it.
 *
 * The reason this type exists is ordering. `generateDue()` used to classify a
 * period and act on it in the same step, so a refusal on the third period of a
 * run arrived after invoices for the first two had already been created and
 * issued. The transaction rolled them back - correctly, and that is still what
 * happens - but they were writes that were guaranteed from the moment the third
 * period was read to be thrown away, and the operator got a refusal with a
 * rollback of real invoice, activity and time-entry mutations behind it.
 *
 * So classification now finishes before creation starts, and the answers have
 * to survive the gap between the two passes. A plan entry is what survives: the
 * period, and either the invoice that already covers it or nothing, meaning
 * bill it. It deliberately does *not* carry {@see PeriodClaim}. A claim can
 * still be `Refused` or `PendingDraft`, and a plan entry that could be either
 * would put the halt decision back into the second pass - which is precisely
 * the arrangement this replaces. The two verdicts that stop a run are consumed
 * in the first pass, and only the two that let it continue can be expressed
 * here at all.
 *
 * Classifying every period before creating any of them is sound because
 * consecutive periods do not overlap - {@see BillingPeriod} makes each end the
 * day before the next begins - and `bill()` writes an invoice covering exactly
 * the period it was given. Nothing the second pass creates is a candidate for
 * a period the first pass classified, so the answers cannot go stale between
 * the passes. The schedule row is locked for both.
 */
final readonly class PlannedBillingPeriod
{
    private function __construct(
        public BillingPeriod $period,
        private ?ClientInvoice $existing,
    ) {}

    /**
     * Nothing covers this period: the schedule creates and issues its invoice.
     */
    public static function toBill(BillingPeriod $period): self
    {
        return new self($period, null);
    }

    /**
     * An invoice already covers this period, so the run reports it and moves on.
     */
    public static function alreadyBilled(BillingPeriod $period, ClientInvoice $invoice): self
    {
        return new self($period, $invoice);
    }

    /**
     * The invoice that already covers the period, or null when there is none.
     */
    public function existingInvoice(): ?ClientInvoice
    {
        return $this->existing;
    }
}
