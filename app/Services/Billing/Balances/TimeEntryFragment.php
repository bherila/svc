<?php

namespace App\Services\Billing\Balances;

/**
 * Represents a fragment of a split time entry.
 *
 * When time entries are split across different allocation types (e.g., retainer vs catch-up),
 * this DTO tracks the fragment's origin, allocation, and metadata.
 */
readonly class TimeEntryFragment
{
    /**
     * Create a new time entry fragment.
     *
     * @param  int  $originalTimeEntryId  The ID of the original ClientTimeEntry
     * @param  int  $minutes  The number of minutes for this fragment
     * @param  string  $dateWorked  The date the work was performed (Y-m-d format)
     * @param  string  $description  The description of the work
     * @param  int  $userId  The ID of the user who performed the work
     * @param  int|null  $clientInvoiceLineId  The invoice line this fragment is linked to (null if unlinked)
     * @param  string  $allocationType  The type of allocation: 'prior_month_retainer', 'current_month_retainer', 'catch_up', 'billable_catchup', or 'unallocated'
     */
    public function __construct(
        public int $originalTimeEntryId,
        public int $minutes,
        public string $dateWorked,
        public string $description,
        public int $userId,
        public ?int $clientInvoiceLineId = null,
        public string $allocationType = 'unallocated'
    ) {}

    /**
     * Get the hours for this fragment.
     */
    public function getHours(): float
    {
        return $this->minutes / 60;
    }

    /**
     * Check if this fragment is linked to an invoice line.
     */
    public function isLinked(): bool
    {
        return $this->clientInvoiceLineId !== null;
    }
}
