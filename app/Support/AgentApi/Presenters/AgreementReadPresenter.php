<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;

/** The public, derived agreement terms shared by SVC's bounded read surfaces. */
final class AgreementReadPresenter
{
    /** @return array{id: string, title: string, status: string, billing_cadence: ?string} */
    public static function summary(ClientAgreement $agreement): array
    {
        return ['id' => $agreement->public_id, 'title' => $agreement->title,
            'status' => $agreement->status, 'billing_cadence' => $agreement->billing_cadence];
    }

    /** @return array<string, mixed> */
    public function present(ClientAgreement $agreement, ?string $projectName): array
    {
        $isRecurring = $agreement->billsOnARecurringCadence();

        return self::commercialTerms($agreement, $projectName) + [
            'effective_billing_cadence' => $isRecurring ? $agreement->effectiveBillingCadence()->value : null,
            'effective_first_cycle_proration' => $isRecurring ? $agreement->effectiveFirstCycleProration()->value : null,
            'retainer_minutes_per_month' => $this->grantsRecurringRetainer($agreement)
                ? (int) round($agreement->retainerHoursPerMonth() * 60) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function commercialTerms(ClientAgreement $agreement, ?string $projectName): array
    {
        $isRecurring = $agreement->billsOnARecurringCadence();
        $grantsRetainer = $isRecurring
            && ($agreement->retainer_minutes !== null || $agreement->period_retainer_minutes !== null);

        return [
            ...self::summary($agreement),
            'currency' => $agreement->currency,
            'is_recurring' => $isRecurring,
            'starts_on' => $agreement->starts_on->toDateString(),
            'ends_on' => $agreement->ends_on?->toDateString(),
            'signed_at' => $agreement->signed_at?->toISOString(),
            'retainer_minutes_per_period' => $grantsRetainer
                ? (int) round($agreement->periodRetainerHours() * 60)
                : null,
            'retainer_amount_per_period' => $grantsRetainer
                ? (int) round($agreement->periodRetainerFee() * 100)
                : null,
            'hourly_rate_amount' => $agreement->hourly_rate_amount === null
                ? null
                : (int) $agreement->hourly_rate_amount,
            'rollover_months' => $agreement->rollover_months === null
                ? null
                : (int) $agreement->rollover_months,
            'project' => $projectName,
        ];
    }

    /** @return array<string,mixed> */
    public static function recurringItem(ClientAgreementRecurringItem $item): array
    {
        return ['id' => $item->public_id, 'description' => $item->description, 'cadence' => $item->cadence,
            'quantity' => $item->quantity === null ? null : (float) $item->quantity,
            'amount' => $item->amount === null ? null : (int) $item->amount, 'currency' => $item->currency,
            'effective_on' => $item->effective_on?->toDateString(), 'expires_on' => $item->expires_on?->toDateString(),
            'is_active' => (bool) $item->is_active];
    }

    public function grantsRecurringRetainer(ClientAgreement $agreement): bool
    {
        return $agreement->billsOnARecurringCadence()
            && ($agreement->retainer_minutes !== null || $agreement->period_retainer_minutes !== null);
    }
}
