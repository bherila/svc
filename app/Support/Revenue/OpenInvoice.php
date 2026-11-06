<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use Carbon\CarbonImmutable;

/**
 * An issued invoice with money still owed on it. A balance is what the client
 * owes, tax included: unlike revenue, receivables are not reported before tax.
 */
final readonly class OpenInvoice
{
    public function __construct(
        public int $clientId,
        public int $balance,
        public ?CarbonImmutable $dueDate,
    ) {}

    /** Overdue the day after it falls due; an invoice with no due date never is. */
    public function isOverdueOn(CarbonImmutable $date): bool
    {
        return $this->dueDate !== null && $this->dueDate->startOfDay()->lt($date->startOfDay());
    }
}
