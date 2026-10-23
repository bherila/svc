<?php

namespace App\Support\AgentApi;

use App\Services\Square\Audit\SquareBillingAudit;

final readonly class StaleAndMissingInvoiceAudit
{
    /** @param array<string, int> $balances
     * @param array{count: int, invoices: list<array{invoice_id: string, successor_id: string, successor_starts_on: string, coverage_start: string, coverage_end: string}>, truncated: bool} $agreementHandovers
     * @param list<string> $draftIds
     * @param list<array{agreement_id: string, period_start: string, period_end: string}> $missingPeriods
     * @param array{count: int, invoices: list<array<string, string>>, truncated: bool} $squarePaymentMismatches
     * @param array<string, mixed>|null $squareBillingAudit {@see SquareBillingAudit::report()}; null is the empty report */
    public function __construct(
        public string $asOf,
        public int $staleCount,
        public array $balances,
        public array $draftIds,
        public int $missingCount,
        public array $missingPeriods,
        public array $squarePaymentMismatches = ['count' => 0, 'invoices' => [], 'truncated' => false],
        public ?array $squareBillingAudit = null,
        public array $agreementHandovers = ['count' => 0, 'invoices' => [], 'truncated' => false],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['as_of' => $this->asOf, 'stale_draft_count' => $this->staleCount, 'stale_draft_balances' => array_map(fn (string $currency): array => ['currency' => $currency, 'balance_amount' => $this->balances[$currency]], array_keys($this->balances)),
            'stale_draft_ids' => $this->draftIds, 'stale_draft_ids_truncated' => $this->staleCount > count($this->draftIds),
            'missing_period_count' => $this->missingCount, 'missing_periods' => $this->missingPeriods,
            'missing_periods_truncated' => $this->missingCount > count($this->missingPeriods),
            'agreement_handovers' => $this->agreementHandovers,
            'square_payment_mismatches' => $this->squarePaymentMismatches,
            'square_billing_audit' => $this->squareBillingAudit ?? SquareBillingAudit::empty()];
    }
}
