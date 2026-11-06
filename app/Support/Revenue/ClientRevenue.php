<?php

declare(strict_types=1);

namespace App\Support\Revenue;

/**
 * One row of the client roster (docs/ux-overhaul.md sections 3 and 4): MRR,
 * year to date, trailing twelve months with its monthly series, what is
 * open and overdue, and the health of each agreement in force.
 */
final readonly class ClientRevenue
{
    /**
     * @param  list<array{month: string, amount: int}>  $ttmSeries  the twelve full months before the current one
     * @param  list<AgreementHealth>  $agreements
     */
    public function __construct(
        public RevenueClient $client,
        public int $mrr,
        public int $ytd,
        public int $ttm,
        public int $monthToDate,
        public array $ttmSeries,
        public int $openAmount,
        public int $overdueAmount,
        public int $overdueCount,
        public array $agreements,
    ) {}

    private function endingSoon(): bool
    {
        foreach ($this->agreements as $health) {
            if ($health->endsWithin60Days) {
                return true;
            }
        }

        return false;
    }

    private function overRetainer(): bool
    {
        foreach ($this->agreements as $health) {
            if ($health->overRetainer === true) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client_id' => $this->client->publicId,
            'name' => $this->client->name,
            'is_active' => $this->client->isActive,
            'mrr' => $this->mrr,
            'ytd' => $this->ytd,
            'ttm' => [
                'amount' => $this->ttm,
                'month_to_date' => $this->monthToDate,
                'series' => $this->ttmSeries,
            ],
            'open_amount' => $this->openAmount,
            'overdue_amount' => $this->overdueAmount,
            'overdue_count' => $this->overdueCount,
            'ending_soon' => $this->endingSoon(),
            'over_retainer' => $this->overRetainer(),
            'agreements' => array_map(static fn (AgreementHealth $health): array => $health->toArray(), $this->agreements),
        ];
    }
}
