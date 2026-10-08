<?php

namespace App\Support\AgentApi;

final readonly class StaleAndMissingInvoiceAudit
{
    /** @param array<string, int> $balances
     * @param list<string> $draftIds
     * @param list<array{agreement_id: string, period_start: string, period_end: string}> $missingPeriods */
    public function __construct(
        public string $asOf,
        public int $staleCount,
        public array $balances,
        public array $draftIds,
        public int $missingCount,
        public array $missingPeriods,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['as_of' => $this->asOf, 'stale_draft_count' => $this->staleCount, 'stale_draft_balances' => array_map(fn (string $currency): array => ['currency' => $currency, 'balance_amount' => $this->balances[$currency]], array_keys($this->balances)),
            'stale_draft_ids' => $this->draftIds, 'stale_draft_ids_truncated' => $this->staleCount > count($this->draftIds),
            'missing_period_count' => $this->missingCount, 'missing_periods' => $this->missingPeriods,
            'missing_periods_truncated' => $this->missingCount > count($this->missingPeriods)];
    }
}
