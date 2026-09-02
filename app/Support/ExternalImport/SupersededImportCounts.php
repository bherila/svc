<?php

namespace App\Support\ExternalImport;

use App\Services\ExternalImport\SupersededImportRepairer;

/**
 * What the superseded-import repair found, and what it retired.
 *
 * Counts only - never an invoice number, a company, an amount or an id - for
 * the same reason as the billing audit counts beside it: the property belongs
 * to the type, so every renderer is safe to paste into a public issue without
 * having to be careful on its own account.
 *
 * See {@see SupersededImportRepairer} for what is retired and why.
 */
final readonly class SupersededImportCounts
{
    public function __construct(
        /** Destination invoices whose source row the predecessor had deleted. */
        public int $eligibleInvoices,
        /** Destination invoice lines whose source row the predecessor had deleted. */
        public int $eligibleLines,
        public int $retiredInvoices,
        public int $retiredLines,
        /**
         * Eligible invoices left alone because money is attached.
         *
         * A superseded invoice carrying a payment is not a bookkeeping artefact
         * this repair may quietly remove: either the payment is real and the
         * invoice is not superseded, or the import went wrong in a second way.
         * Either reading needs a person, so these are counted and skipped.
         */
        public int $skippedWithAPayment,
        /**
         * Surviving invoices whose remaining lines do not sum to their own
         * subtotal.
         *
         * The number this repair exists to drive to zero. Non-zero after an
         * apply means the superseded set was not the whole story.
         */
        public int $survivorsNotReconciling,
        public bool $applied,
    ) {}

    /** Nothing to do, so callers can stay quiet rather than print an empty report. */
    public function isClean(): bool
    {
        return $this->eligibleInvoices === 0
            && $this->eligibleLines === 0
            && $this->survivorsNotReconciling === 0;
    }

    /**
     * Whether the repair left the books consistent.
     *
     * Only meaningful once applied. False means rows were retired and some
     * invoice still does not add up, which is the signal to stop rather than
     * run it again somewhere else.
     */
    public function reconciled(): bool
    {
        return $this->survivorsNotReconciling === 0;
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'eligible_invoices' => $this->eligibleInvoices,
            'eligible_lines' => $this->eligibleLines,
            'retired_invoices' => $this->retiredInvoices,
            'retired_lines' => $this->retiredLines,
            'skipped_with_a_payment' => $this->skippedWithAPayment,
            'survivors_not_reconciling' => $this->survivorsNotReconciling,
            'applied' => $this->applied,
        ];
    }
}
