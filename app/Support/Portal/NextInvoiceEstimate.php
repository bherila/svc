<?php

declare(strict_types=1);

namespace App\Support\Portal;

/**
 * "Next invoice (estimate)" as the client portal prints it
 * (docs/ux-overhaul.md section 6): the parts the next invoice is expected to
 * carry and their sum. Pure and database-free.
 *
 * Every part is before tax and excludes expenses, so the sum is a floor and
 * says so (the page prints {@see self::EXCLUDES}). A part whose price is not
 * known - overage hours when the agreement states no hourly rate - carries a
 * null amount and adds nothing, and the hours are still printed.
 */
final readonly class NextInvoiceEstimate
{
    /** What the estimate leaves out, in the words the page prints. */
    public const string EXCLUDES = 'tax and expenses';

    /**
     * @param  list<array{kind: string, description: string, amount: int|null, date: string|null, hours: float|null}>  $parts
     */
    public function __construct(
        public string $periodStart,
        public string $periodEnd,
        public ?string $currency,
        public array $parts,
    ) {}

    /** The sum of the priced parts, in minor units. */
    public function total(): int
    {
        $total = 0;
        foreach ($this->parts as $part) {
            $total += $part['amount'] ?? 0;
        }

        return $total;
    }

    /**
     * @return array{period_start: string, period_end: string, currency: string|null, total: int, parts: list<array{kind: string, description: string, amount: int|null, date: string|null, hours: float|null}>, excludes: string}
     */
    public function toArray(): array
    {
        return [
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'currency' => $this->currency,
            'total' => $this->total(),
            'parts' => $this->parts,
            'excludes' => self::EXCLUDES,
        ];
    }
}
