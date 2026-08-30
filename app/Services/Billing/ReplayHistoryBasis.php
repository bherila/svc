<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Replay-only overrides for where an agreement's retainer ledger begins.
 *
 * This never changes which cycles invoice generation may sell. It only gives
 * the ledger historical capacity that predates a stored agreement start, so a
 * replay can compare later invoices from the same opening state without
 * rewriting the agreement itself.
 */
final class ReplayHistoryBasis
{
    /** @var array<int, Carbon> */
    private array $starts = [];

    public function reset(): void
    {
        $this->starts = [];
    }

    public function seed(ClientAgreement $agreement, CarbonInterface $start): void
    {
        $this->starts[(int) $agreement->id] = Carbon::instance($start)->startOfDay();
    }

    public function startFor(ClientAgreement $agreement, CarbonInterface $storedStart): Carbon
    {
        return ($this->starts[(int) $agreement->id] ?? Carbon::instance($storedStart))->copy()->startOfDay();
    }
}
