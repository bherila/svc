<?php

namespace App\Support\Billing;

use App\Services\Billing\ClientInvoicingService;

/**
 * The hours a cadence invoice reconciles, as the generator measured them.
 *
 * A retainer invoice charges money for hours, and the client's question about
 * it is always about the hours: what the pool held, what the month used, what
 * was billed on top and why, and what carries into the next period. Every
 * figure here is taken, by {@see ClientInvoicingService},
 * from the same allocation plan and the same capacity ledger that wrote the
 * invoice's lines - the ledger re-run inside the generating transaction after
 * every line is composed, with the draft's own charge overlaid exactly as the
 * catch-up path already does - so the statement cannot disagree with the
 * charges it explains.
 *
 * Stored on the invoice rather than recomputed when the document is rendered.
 * A later change to the ledger - an edit to an earlier month, a correction, an
 * agreement change - would otherwise silently rewrite the statement on an
 * invoice the client already holds. The generator refuses to touch an issued
 * invoice, so the snapshot freezes at issue with the lines it describes.
 *
 * All quantities are hours, rounded to the ledger's four places.
 */
final readonly class InvoiceHoursStatement
{
    /** Bumped when the stored shape changes; an unknown shape renders nothing. */
    public const int VERSION = 1;

    public function __construct(
        /** The agreement cadence the period was reconciled under, e.g. `monthly`. */
        public string $cadence,
        public string $workStart,
        public string $workEnd,
        public ?string $retainerStart,
        public ?string $retainerEnd,
        /** The work period's own retainer pool. */
        public float $openingRetainerHours,
        /** Unused hours from earlier periods still spendable in this one. */
        public float $openingRolloverHours,
        /** Hours owed from earlier periods, taken from this pool first. */
        public float $openingDeficitHours,
        /** Unused hours that expired at the start of the work period. */
        public float $openingExpiredHours,
        /**
         * Unused hours that expired inside a multi-month work period, month by
         * month under the rollover rule; always zero for a monthly one.
         */
        public float $expiredWithinPeriodHours,
        /** Ordinary (non-deferred) work this invoice reconciles, in total. */
        public float $ordinaryHours,
        /** ...of which drawn on the work period's pool. */
        public float $ordinaryAppliedToWorkPool,
        /** ...of which drawn in advance on the retainer this invoice sells. */
        public float $ordinaryAppliedToNextRetainer,
        /** ...of which billed at the hourly rate. */
        public float $ordinaryBilledAtRate,
        /**
         * Flat-hourly subcontractor time on this invoice, billed on its own
         * lines at its own rate. Never drawn on the retainer pool.
         */
        public float $subcontractorHours,
        /** Deferred work this invoice applied to the period's free capacity. */
        public float $deferredAppliedHours,
        public int $deferredAppliedEntries,
        /** Deferred work applied earlier beyond free capacity, settled now. */
        public float $recarriedSettledHours,
        /** Deferred work, entries and re-carried, billed at rate on termination. */
        public float $deferredBilledOnTerminationHours,
        /** Everything this invoice bills at the hourly rate as catch-up. */
        public float $catchUpBilledHours,
        /** ...of which restores the minimum availability, rather than covering work. */
        public float $minimumAvailabilityHours,
        /** The agreement's minimum availability, in hours. */
        public float $minimumAvailabilityThresholdHours,
        /** Overage already billed inside the cycle by interim invoices. */
        public float $interimBilledHours,
        /** Unused hours that roll into the period after this one. */
        public float $rolledForwardHours,
        /** Unused hours that expire at the start of the period after this one. */
        public float $expiringHours,
        /** Hours still owed at the close of the work period, after this invoice's charge. */
        public float $deficitCarriedForwardHours,
        /** Deferred entries still waiting for free capacity. */
        public float $deferredBacklogHours,
        public int $deferredBacklogEntries,
        /** Deferred work applied earlier beyond free capacity, still to settle. */
        public float $recarriedRemainingHours,
        /** The retainer this invoice sells for the next period. */
        public float $nextRetainerHours,
    ) {}

    /** Where the work period's pool stood before any of this invoice's work. */
    public function openingNetHours(): float
    {
        return round($this->openingRetainerHours + $this->openingRolloverHours - $this->openingDeficitHours, 4);
    }

    /** Where the next period's pool starts, before any of its own work. */
    public function closingNetHours(): float
    {
        return round($this->nextRetainerHours + $this->rolledForwardHours - $this->deficitCarriedForwardHours, 4);
    }

    /** @return array<string, int|float|string|null> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'cadence' => $this->cadence,
            'workStart' => $this->workStart,
            'workEnd' => $this->workEnd,
            'retainerStart' => $this->retainerStart,
            'retainerEnd' => $this->retainerEnd,
            'openingRetainerHours' => $this->openingRetainerHours,
            'openingRolloverHours' => $this->openingRolloverHours,
            'openingDeficitHours' => $this->openingDeficitHours,
            'openingExpiredHours' => $this->openingExpiredHours,
            'expiredWithinPeriodHours' => $this->expiredWithinPeriodHours,
            'ordinaryHours' => $this->ordinaryHours,
            'ordinaryAppliedToWorkPool' => $this->ordinaryAppliedToWorkPool,
            'ordinaryAppliedToNextRetainer' => $this->ordinaryAppliedToNextRetainer,
            'ordinaryBilledAtRate' => $this->ordinaryBilledAtRate,
            'subcontractorHours' => $this->subcontractorHours,
            'deferredAppliedHours' => $this->deferredAppliedHours,
            'deferredAppliedEntries' => $this->deferredAppliedEntries,
            'recarriedSettledHours' => $this->recarriedSettledHours,
            'deferredBilledOnTerminationHours' => $this->deferredBilledOnTerminationHours,
            'catchUpBilledHours' => $this->catchUpBilledHours,
            'minimumAvailabilityHours' => $this->minimumAvailabilityHours,
            'minimumAvailabilityThresholdHours' => $this->minimumAvailabilityThresholdHours,
            'interimBilledHours' => $this->interimBilledHours,
            'rolledForwardHours' => $this->rolledForwardHours,
            'expiringHours' => $this->expiringHours,
            'deficitCarriedForwardHours' => $this->deficitCarriedForwardHours,
            'deferredBacklogHours' => $this->deferredBacklogHours,
            'deferredBacklogEntries' => $this->deferredBacklogEntries,
            'recarriedRemainingHours' => $this->recarriedRemainingHours,
            'nextRetainerHours' => $this->nextRetainerHours,
        ];
    }

    /**
     * Read a stored statement, or nothing when it is absent or of another shape.
     *
     * Nothing rather than an exception: the statement explains an invoice, and
     * an invoice whose explanation cannot be read is still an invoice to print.
     *
     * @param  array<array-key, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null || ($stored['version'] ?? null) !== self::VERSION) {
            return null;
        }

        $text = static fn (string $key): ?string => is_string($stored[$key] ?? null) ? $stored[$key] : null;
        $hours = static function (string $key) use ($stored): float {
            $value = $stored[$key] ?? null;

            return is_int($value) || is_float($value) ? round($value, 4) : 0.0;
        };
        $count = static fn (string $key): int => is_int($stored[$key] ?? null) ? $stored[$key] : 0;

        $cadence = $text('cadence');
        $workStart = $text('workStart');
        $workEnd = $text('workEnd');
        if ($cadence === null || $workStart === null || $workEnd === null) {
            return null;
        }

        return new self(
            cadence: $cadence,
            workStart: $workStart,
            workEnd: $workEnd,
            retainerStart: $text('retainerStart'),
            retainerEnd: $text('retainerEnd'),
            openingRetainerHours: $hours('openingRetainerHours'),
            openingRolloverHours: $hours('openingRolloverHours'),
            openingDeficitHours: $hours('openingDeficitHours'),
            openingExpiredHours: $hours('openingExpiredHours'),
            expiredWithinPeriodHours: $hours('expiredWithinPeriodHours'),
            ordinaryHours: $hours('ordinaryHours'),
            ordinaryAppliedToWorkPool: $hours('ordinaryAppliedToWorkPool'),
            ordinaryAppliedToNextRetainer: $hours('ordinaryAppliedToNextRetainer'),
            ordinaryBilledAtRate: $hours('ordinaryBilledAtRate'),
            subcontractorHours: $hours('subcontractorHours'),
            deferredAppliedHours: $hours('deferredAppliedHours'),
            deferredAppliedEntries: $count('deferredAppliedEntries'),
            recarriedSettledHours: $hours('recarriedSettledHours'),
            deferredBilledOnTerminationHours: $hours('deferredBilledOnTerminationHours'),
            catchUpBilledHours: $hours('catchUpBilledHours'),
            minimumAvailabilityHours: $hours('minimumAvailabilityHours'),
            minimumAvailabilityThresholdHours: $hours('minimumAvailabilityThresholdHours'),
            interimBilledHours: $hours('interimBilledHours'),
            rolledForwardHours: $hours('rolledForwardHours'),
            expiringHours: $hours('expiringHours'),
            deficitCarriedForwardHours: $hours('deficitCarriedForwardHours'),
            deferredBacklogHours: $hours('deferredBacklogHours'),
            deferredBacklogEntries: $count('deferredBacklogEntries'),
            recarriedRemainingHours: $hours('recarriedRemainingHours'),
            nextRetainerHours: $hours('nextRetainerHours'),
        );
    }
}
