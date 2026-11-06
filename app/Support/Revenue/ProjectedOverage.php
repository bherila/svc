<?php

namespace App\Support\Revenue;

/**
 * How one agreement's overage was projected: the trailing average of billed
 * overage amount over the months it was in force inside the window, carried
 * forward flat. Below the minimum history it projects zero and says so.
 */
final readonly class ProjectedOverage
{
    public const string PROJECTED = 'projected';

    public const string INSUFFICIENT_HISTORY = 'insufficient_history';

    public function __construct(
        public int $historyMonths,
        public int $billedInWindow,
        public int $monthlyAverage,
        public string $status,
    ) {}

    /**
     * @param  int  $historyMonths  months of the window the agreement was in force
     * @param  int  $billedInWindow  billed overage over those months, minor units
     */
    public static function fromHistory(int $historyMonths, int $billedInWindow): self
    {
        if ($historyMonths < RevenueAssumptions::MINIMUM_HISTORY_MONTHS) {
            return new self($historyMonths, $billedInWindow, 0, self::INSUFFICIENT_HISTORY);
        }

        return new self($historyMonths, $billedInWindow, (int) round($billedInWindow / $historyMonths), self::PROJECTED);
    }

    /** @return array{method: string, window_months: int, minimum_history_months: int, history_months: int, billed_in_window: int, monthly_average: int, status: string} */
    public function toArray(): array
    {
        return [
            'method' => 'trailing_average',
            'window_months' => RevenueAssumptions::OVERAGE_WINDOW_MONTHS,
            'minimum_history_months' => RevenueAssumptions::MINIMUM_HISTORY_MONTHS,
            'history_months' => $this->historyMonths,
            'billed_in_window' => $this->billedInWindow,
            'monthly_average' => $this->monthlyAverage,
            'status' => $this->status,
        ];
    }
}
