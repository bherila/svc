<?php

namespace App\Support\Billing;

use App\Services\Billing\UndatedCollectibleInvoiceAuditor;

/**
 * How many collectible invoices no overdue figure can include, and how much
 * they carry.
 *
 * Counts and balances only - never a row, an id, an invoice number, a company or
 * a workspace. That is a property of this type rather than of the code that
 * renders it, so the console command and any later operator screen are both safe
 * against a database of real client billing records without each having to be
 * careful in its own way. Balances are keyed by currency code, which names a
 * denomination rather than a record.
 *
 * See {@see UndatedCollectibleInvoiceAuditor} for what each figure narrows and
 * why.
 */
final readonly class UndatedCollectibleInvoiceCounts
{
    /**
     * @param  array<string, int>  $undatedBalances
     * @param  array<string, int>  $wouldBecomeOverdueBalances
     */
    public function __construct(
        public int $invoices,
        public int $collectible,
        public int $undated,
        public int $withAnIssueDate,
        public int $withoutAnIssueDate,
        public int $wouldBecomeOverdueIfBackfilled,
        public array $undatedBalances,
        public array $wouldBecomeOverdueBalances,
    ) {}

    /**
     * Whether the two reported figures currently disagree.
     *
     * Any undated collectible invoice is one counted in `collectible_balances`
     * and absent from every overdue figure, so this is the whole condition -
     * there is no narrower subset where the disagreement is real, unlike the
     * audits whose defect needs several things to coincide.
     */
    public function isLive(): bool
    {
        return $this->undated > 0;
    }

    /**
     * The machine-readable shape, stable for the `--format=json` contract.
     *
     * @return array{
     *     invoices: int,
     *     collectible: int,
     *     undated: int,
     *     with_an_issue_date: int,
     *     without_an_issue_date: int,
     *     would_become_overdue_if_backfilled: int,
     *     undated_balances: array<string, int>,
     *     would_become_overdue_balances: array<string, int>,
     * }
     */
    public function toArray(): array
    {
        return [
            'invoices' => $this->invoices,
            'collectible' => $this->collectible,
            'undated' => $this->undated,
            'with_an_issue_date' => $this->withAnIssueDate,
            'without_an_issue_date' => $this->withoutAnIssueDate,
            'would_become_overdue_if_backfilled' => $this->wouldBecomeOverdueIfBackfilled,
            'undated_balances' => $this->undatedBalances,
            'would_become_overdue_balances' => $this->wouldBecomeOverdueBalances,
        ];
    }
}
