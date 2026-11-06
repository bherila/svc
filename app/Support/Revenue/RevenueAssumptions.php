<?php

namespace App\Support\Revenue;

use Carbon\CarbonImmutable;

/**
 * What a revenue figure was computed under, carried by every revenue
 * response so a stored figure can be explained later (docs/ux-overhaul.md
 * section 13).
 */
final readonly class RevenueAssumptions
{
    /** Bumped whenever a rule below changes what a figure means. */
    public const string METHOD_VERSION = 'revenue-2026-11-v1';

    /** Months of billed overage the projection averages. */
    public const int OVERAGE_WINDOW_MONTHS = 6;

    /** Months in force an agreement needs before its overage is projected. */
    public const int MINIMUM_HISTORY_MONTHS = 3;

    /** Full months the projection runs past the current month. */
    public const int PROJECTION_MONTHS = 12;

    /** Days ahead an agreement's end counts as "ending soon". */
    public const int ENDING_SOON_DAYS = 60;

    public function __construct(
        public CarbonImmutable $asOf,
        public RevenueBasis $basis = RevenueBasis::Invoiced,
        public bool $includeExpenses = true,
        public bool $includeProjectedOverage = false,
        public ?string $currency = null,
    ) {}

    /** @return array{as_of: string, basis: string, projection_basis: string, currency: string|null, tax: string, fiscal_year_start: string, include_expenses: bool, include_projected_overage: bool, overage_window_months: int, minimum_history_months: int, projection_months: int, renewals_assumed: bool, method_version: string} */
    public function toArray(): array
    {
        return [
            'as_of' => $this->asOf->toDateString(),
            'basis' => $this->basis->value,
            'projection_basis' => RevenueBasis::Invoiced->value,
            'currency' => $this->currency,
            'tax' => 'excluded',
            'fiscal_year_start' => '01-01',
            'include_expenses' => $this->includeExpenses,
            'include_projected_overage' => $this->includeProjectedOverage,
            'overage_window_months' => self::OVERAGE_WINDOW_MONTHS,
            'minimum_history_months' => self::MINIMUM_HISTORY_MONTHS,
            'projection_months' => self::PROJECTION_MONTHS,
            'renewals_assumed' => false,
            'method_version' => self::METHOD_VERSION,
        ];
    }
}
