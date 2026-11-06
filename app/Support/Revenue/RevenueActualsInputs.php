<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use App\Services\Revenue\RevenueActualsRepository;
use Carbon\CarbonImmutable;

/**
 * The money already invoiced and collected, read once by
 * {@see RevenueActualsRepository} and detached from
 * persistence, so every summary over it is database-free.
 */
final readonly class RevenueActualsInputs
{
    /** Months before the current one the actuals cover: the trailing year and the year before it. */
    public const int MONTHS_BACK = 24;

    /**
     * @param  array<int, RevenueClient>  $clients  by client id
     * @param  list<ActualAmount>  $invoiced
     * @param  list<ActualAmount>  $collected
     * @param  list<OpenInvoice>  $openInvoices
     */
    public function __construct(
        public CarbonImmutable $asOf,
        public array $clients,
        public array $invoiced,
        public array $collected,
        public array $openInvoices,
    ) {}

    /** First day of the earliest month any figure reads. */
    public static function windowStart(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->startOfMonth()->subMonthsNoOverflow(self::MONTHS_BACK)->startOfDay();
    }

    /** Last day of the current month. */
    public static function windowEnd(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->endOfMonth()->startOfDay();
    }

    /** @return list<ActualAmount> */
    public function amounts(RevenueBasis $basis): array
    {
        return match ($basis) {
            RevenueBasis::Invoiced => $this->invoiced,
            RevenueBasis::Collected => $this->collected,
        };
    }

    public function forClient(int $clientId): self
    {
        $mine = static fn (ActualAmount|OpenInvoice $row): bool => $row->clientId === $clientId;

        return new self(
            $this->asOf,
            array_intersect_key($this->clients, [$clientId => true]),
            array_values(array_filter($this->invoiced, $mine)),
            array_values(array_filter($this->collected, $mine)),
            array_values(array_filter($this->openInvoices, $mine)),
        );
    }

    /**
     * Collected money in bands and before tax.
     *
     * A payment settles its invoice as a whole, so each payment is spread
     * over the bands of the invoice it paid in proportion to that invoice's
     * pre-tax lines, and the tax share comes off the same way: a payment of
     * half an invoice collected half of each band and half of its tax. The
     * rounding remainder goes to the band with the most money so the bands
     * add up to the payment's pre-tax share exactly.
     *
     * @param  list<CollectedPayment>  $payments
     * @param  array<int, array{gross: int, bands: array<string, int>}>  $compositions  by invoice id: line totals with tax, and pre-tax money by band value
     * @return list<ActualAmount>
     */
    public static function collectedAmounts(array $payments, array $compositions): array
    {
        $amounts = [];
        foreach ($payments as $payment) {
            if ($payment->amount === 0) {
                continue;
            }
            $composition = $compositions[$payment->invoiceId] ?? null;
            $bands = array_filter($composition['bands'] ?? []);
            if ($composition === null || $bands === [] || $composition['gross'] <= 0) {
                // Nothing to apportion by: an invoice with no priced lines.
                $amounts[] = new ActualAmount($payment->clientId, $payment->receivedOn, RevenueBand::OverageAndOther, $payment->amount);

                continue;
            }

            $target = (int) round($payment->amount * array_sum($bands) / $composition['gross']);
            $shares = [];
            foreach ($bands as $band => $amount) {
                $shares[$band] = (int) round($payment->amount * $amount / $composition['gross']);
            }
            arsort($bands);
            // @infection-ignore-all Equivalent: the keys are band values, which are never numeric, so PHP keeps them as strings with or without the cast.
            $largest = (string) array_key_first($bands);
            $shares[$largest] += $target - array_sum($shares);

            foreach ($shares as $band => $share) {
                if ($share !== 0) {
                    // @infection-ignore-all Equivalent: as above, a band key is always a string; the cast states it for static analysis.
                    $amounts[] = new ActualAmount($payment->clientId, $payment->receivedOn, RevenueBand::from((string) $band), $share);
                }
            }
        }

        return $amounts;
    }
}
