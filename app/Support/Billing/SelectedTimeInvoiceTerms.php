<?php

namespace App\Support\Billing;

use App\Models\ClientTimeEntry;
use App\Services\Billing\MoneyService;
use InvalidArgumentException;

/** Database-free pricing shared by selected-time creation and its preview. */
final readonly class SelectedTimeInvoiceTerms
{
    private function __construct(
        public string $type,
        public int $unitAmount,
        public string $currency,
        public int $totalAmount,
    ) {}

    public static function forEntry(ClientTimeEntry $entry): ?self
    {
        if ($entry->status !== 'approved' || ! $entry->is_billable || $entry->is_deferred) {
            return null;
        }

        $rawMode = $entry->getRawOriginal('subcontractor_billing_mode');
        if ($rawMode !== null && ! is_string($rawMode)) {
            return null;
        }
        $mode = $rawMode === null ? null : SubcontractorBillingMode::tryFrom($rawMode);
        if ($rawMode !== null && ! $mode instanceof SubcontractorBillingMode) {
            return null;
        }
        if ($mode === null && $entry->subcontractor_cost_amount !== null) {
            return null;
        }

        $source = match ($mode) {
            SubcontractorBillingMode::Direct => null,
            SubcontractorBillingMode::FlatHourly => [InvoiceLineType::Subcontractor->value, $entry->subcontractor_cost_amount, $entry->subcontractor_cost_currency],
            SubcontractorBillingMode::Retainer, null => ['time', $entry->billing_rate_amount, $entry->currency],
        };
        if ($source === null) {
            return null;
        }
        [$type, $unitAmount, $currency] = $source;
        if ($unitAmount === null || $currency === null) {
            return null;
        }

        try {
            return new self(
                $type,
                $unitAmount,
                MoneyService::currency($currency),
                MoneyService::hourlyAmount($entry->minutes, $unitAmount),
            );
        } catch (InvalidArgumentException) {
            // Invalid legacy rates, durations and currencies are not a quote.
            return null;
        }
    }
}
