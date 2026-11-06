<?php

declare(strict_types=1);

namespace App\Support\Portal;

use Carbon\CarbonImmutable;

/**
 * The portal's Balance card (docs/ux-overhaul.md section 6): what the client
 * owes across the invoices they may see, whether any of it is late, and the one
 * invoice to deal with first. Pure and database-free; the rows it is handed are
 * already the client's own collectible invoices.
 *
 * - **Amount due** is the sum of the open balances. Across more than one
 *   currency there is no honest sum, so it is withheld (null) rather than
 *   added up in mixed units.
 * - **Overdue** means a due date before today. An invoice due today is not
 *   late.
 * - **The invoice to deal with first** is the earliest due date; an invoice
 *   with no due date waits behind every dated one, and ties break on the
 *   invoice number so the choice is stable.
 */
final readonly class PortalBalance
{
    /**
     * @param  list<array{id: string, number: string, balance: int, currency: string, due_date: string|null}>  $open
     */
    public function __construct(private array $open, private string $today) {}

    /**
     * The invoice to deal with first, or null when nothing is owed.
     *
     * @return array{id: string, number: string, balance: int, currency: string, due_date: string|null}|null
     */
    public function first(): ?array
    {
        $open = $this->open;
        usort($open, static fn (array $a, array $b): int => [$a['due_date'] === null, $a['due_date'] ?? '', $a['number']]
            <=> [$b['due_date'] === null, $b['due_date'] ?? '', $b['number']]);

        return $open[0] ?? null;
    }

    /**
     * @return array{amount_due: int|null, currency: string|null, invoice_count: int, overdue: bool, overdue_amount: int|null, first: array{id: string, number: string, balance: int, currency: string, due_date: string|null, days_until_due: int|null}|null}
     */
    public function toArray(): array
    {
        $currencies = array_values(array_unique(array_column($this->open, 'currency')));
        $single = count($currencies) <= 1;
        $overdue = array_values(array_filter($this->open, fn (array $invoice): bool => $invoice['due_date'] !== null && $invoice['due_date'] < $this->today));
        $first = $this->first();

        return [
            'amount_due' => $single ? array_sum(array_column($this->open, 'balance')) : null,
            'currency' => $single ? ($currencies[0] ?? null) : null,
            'invoice_count' => count($this->open),
            'overdue' => $overdue !== [],
            'overdue_amount' => $single ? array_sum(array_column($overdue, 'balance')) : null,
            'first' => $first === null ? null : [...$first, 'days_until_due' => $this->daysUntil($first['due_date'])],
        ];
    }

    /** Whole days from today to the due date; negative once late, null with no due date. */
    private function daysUntil(?string $dueDate): ?int
    {
        if ($dueDate === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($this->today)->startOfDay()->diffInDays(CarbonImmutable::parse($dueDate)->startOfDay(), false);
    }
}
