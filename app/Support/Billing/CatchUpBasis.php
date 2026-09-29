<?php

namespace App\Support\Billing;

use App\Services\Billing\DraftCatchUpDependencies;

/**
 * The earlier catch-up a monthly invoice was sized against.
 *
 * The capacity ledger books each month's work from time entries and settles it
 * against catch-up hours that earlier invoices charge: issued ones from the
 * billed-overage ledger, drafts from the overlay. Every one of those charges is
 * recorded here, by invoice, as the generator counted it, together with the
 * date the ledger was measured through.
 *
 * Stored on the invoice at generation, so that issuing it can measure the same
 * window again and refuse when any earlier charge has moved - an earlier draft
 * regenerated to a different figure, created since, or voided - and voiding an
 * issued invoice can see the later invoices sized against it. See
 * {@see DraftCatchUpDependencies}.
 *
 * Hours are rounded to the ledger's four places, and an invoice charging none
 * is left out: it moved nothing, so appearing or disappearing at zero is not a
 * change.
 */
final readonly class CatchUpBasis
{
    /** Bumped when the stored shape changes. */
    public const int VERSION = 1;

    /**
     * @param  array<int, array{number: string, hours: float}>  $charges  keyed by invoice id, in id order
     */
    private function __construct(
        /** The last day the ledger was measured through, as `Y-m-d`. */
        public string $through,
        public array $charges,
    ) {}

    /**
     * @param  iterable<array{id: int, number: string, hours: float}>  $charges
     */
    public static function of(string $through, iterable $charges): self
    {
        $byId = [];
        foreach ($charges as $charge) {
            $hours = round($charge['hours'], 4);
            if ($hours === 0.0) {
                continue;
            }
            $byId[$charge['id']] = ['number' => $charge['number'], 'hours' => $hours];
        }
        ksort($byId);

        return new self($through, $byId);
    }

    /**
     * Read a stored basis back. Null when nothing is stored or the shape is
     * not one this version wrote; the caller decides what unknown means.
     *
     * @param  array<array-key, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null
            || ($stored['version'] ?? null) !== self::VERSION
            || ! is_string($stored['through'] ?? null)
            || ! is_array($stored['invoices'] ?? null)) {
            return null;
        }

        $charges = [];
        foreach ($stored['invoices'] as $invoice) {
            if (! is_array($invoice)
                || ! is_int($invoice['id'] ?? null)
                || ! is_string($invoice['number'] ?? null)
                || ! (is_int($invoice['hours'] ?? null) || is_float($invoice['hours'] ?? null))) {
                return null;
            }
            $charges[] = ['id' => $invoice['id'], 'number' => $invoice['number'], 'hours' => (float) $invoice['hours']];
        }

        return self::of($stored['through'], $charges);
    }

    /**
     * @return array{version: int, through: string, invoices: list<array{id: int, number: string, hours: float}>}
     */
    public function toArray(): array
    {
        $invoices = [];
        foreach ($this->charges as $id => $charge) {
            $invoices[] = ['id' => $id, 'number' => $charge['number'], 'hours' => $charge['hours']];
        }

        return ['version' => self::VERSION, 'through' => $this->through, 'invoices' => $invoices];
    }

    /** The hours an invoice was recorded as charging, zero when it was not counted. */
    public function hoursFrom(int $invoiceId): float
    {
        return $this->charges[$invoiceId]['hours'] ?? 0.0;
    }

    /**
     * The first earlier charge, in invoice id order, that differs between
     * this basis and one measured since; null when every charge is the same.
     *
     * @return array{number: string, recorded: float, current: float}|null
     */
    public function firstChangeIn(self $current): ?array
    {
        $ids = array_unique([...array_keys($this->charges), ...array_keys($current->charges)]);
        sort($ids);

        foreach ($ids as $id) {
            $recorded = $this->hoursFrom($id);
            $now = $current->hoursFrom($id);
            if ($recorded !== $now) {
                return [
                    'number' => $current->charges[$id]['number'] ?? $this->charges[$id]['number'],
                    'recorded' => $recorded,
                    'current' => $now,
                ];
            }
        }

        return null;
    }
}
