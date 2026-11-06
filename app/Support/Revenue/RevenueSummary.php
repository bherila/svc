<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use Carbon\CarbonImmutable;

/**
 * The dashboard's key figures (docs/ux-overhaul.md section 2a): year to date,
 * trailing twelve months, the next twelve months, the current month and open
 * receivables, each with the window it was measured over, and the agreements
 * starting and ending in the next twelve months. Minor units; revenue before
 * tax, receivables as owed.
 */
final readonly class RevenueSummary
{
    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, amount: int, prior_from: CarbonImmutable, prior_to: CarbonImmutable, prior_amount: int}  $ytd
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, amount: int, prior_from: CarbonImmutable, prior_to: CarbonImmutable, prior_amount: int}  $ttm
     * @param  array{from: string, to: string, contracted: int, projected_overage: int}  $next12Months
     * @param  array{month: string, actual: int, remaining_contracted: int, projected_overage: int}  $currentMonth
     * @param  array{outstanding: int, invoice_count: int, overdue_amount: int, overdue_count: int}  $openReceivables
     * @param  list<AgreementChange>  $ending
     * @param  list<AgreementChange>  $starting
     */
    public function __construct(
        public RevenueAssumptions $assumptions,
        public array $ytd,
        public array $ttm,
        public array $next12Months,
        public array $currentMonth,
        public array $openReceivables,
        public array $ending,
        public array $starting,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $overage = $this->assumptions->includeProjectedOverage;
        $window = static fn (array $figure): array => [
            'amount' => $figure['amount'],
            'from' => $figure['from']->toDateString(),
            'to' => $figure['to']->toDateString(),
            'prior_amount' => $figure['prior_amount'],
            'prior_from' => $figure['prior_from']->toDateString(),
            'prior_to' => $figure['prior_to']->toDateString(),
        ];

        return [
            'assumptions' => $this->assumptions->toArray(),
            'ytd' => $window($this->ytd),
            'ttm' => $window($this->ttm),
            'next_12_months' => [
                'from' => $this->next12Months['from'],
                'to' => $this->next12Months['to'],
                'contracted' => $this->next12Months['contracted'],
                'projected_overage' => $overage ? $this->next12Months['projected_overage'] : null,
            ],
            'current_month' => [
                'month' => $this->currentMonth['month'],
                'actual' => $this->currentMonth['actual'],
                'remaining_contracted' => $this->currentMonth['remaining_contracted'],
                'projected_overage' => $overage ? $this->currentMonth['projected_overage'] : null,
            ],
            'open_receivables' => $this->openReceivables,
            'changes' => [
                'ending' => array_map(static fn (AgreementChange $change): array => $change->toArray(), $this->ending),
                'starting' => array_map(static fn (AgreementChange $change): array => $change->toArray(), $this->starting),
            ],
        ];
    }
}
