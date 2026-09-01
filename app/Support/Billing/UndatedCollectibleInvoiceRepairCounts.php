<?php

namespace App\Support\Billing;

use App\Services\Billing\UndatedCollectibleInvoiceRepairer;

/**
 * What the due-date repair found, and what it wrote.
 *
 * Counts only - never a row, an id, an invoice number, a company or a
 * workspace - for the same reason as {@see UndatedCollectibleInvoiceCounts}:
 * the property belongs to the type, so every renderer is safe against a
 * database of real client billing records without having to be careful on its
 * own account.
 *
 * See {@see UndatedCollectibleInvoiceRepairer} for what is repaired and why.
 */
final readonly class UndatedCollectibleInvoiceRepairCounts
{
    public function __construct(
        public int $eligible,
        public int $repaired,
        public int $skippedWithoutAnIssueDate,
        public bool $applied,
    ) {}

    /**
     * Whether anything is left undated after this run.
     *
     * Only meaningful once applied. A row with no issue date has no defensible
     * due date, so it is reported rather than guessed at - and if this is ever
     * non-zero, #149's option (2) stops being optional.
     */
    public function leavesAnUndatedRemainder(): bool
    {
        return $this->skippedWithoutAnIssueDate > 0;
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'repaired' => $this->repaired,
            'skipped_without_an_issue_date' => $this->skippedWithoutAnIssueDate,
            'applied' => $this->applied,
        ];
    }
}
