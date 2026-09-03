<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientAgreement;

/** The public, derived agreement terms shared by SVC's bounded read surfaces. */
final class AgreementReadPresenter
{
    /** @return array<string, mixed> */
    public function present(ClientAgreement $agreement, ?string $projectName): array
    {
        $isRecurring = $agreement->billsOnARecurringCadence();
        $grantsRetainer = $isRecurring
            && ($agreement->retainer_minutes !== null || $agreement->period_retainer_minutes !== null);

        return [
            'id' => $agreement->public_id,
            'title' => $agreement->title,
            'status' => $agreement->status,
            'currency' => $agreement->currency,
            'billing_cadence' => $agreement->billing_cadence,
            'effective_billing_cadence' => $isRecurring
                ? $agreement->effectiveBillingCadence()->value
                : null,
            'effective_first_cycle_proration' => $isRecurring
                ? $agreement->effectiveFirstCycleProration()->value
                : null,
            'is_recurring' => $isRecurring,
            'starts_on' => $agreement->starts_on->toDateString(),
            'ends_on' => $agreement->ends_on?->toDateString(),
            'signed_at' => $agreement->signed_at?->toISOString(),
            'retainer_minutes_per_period' => $grantsRetainer
                ? (int) round($agreement->periodRetainerHours() * 60)
                : null,
            'retainer_minutes_per_month' => $grantsRetainer
                ? (int) round($agreement->retainerHoursPerMonth() * 60)
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

    public function grantsRecurringRetainer(ClientAgreement $agreement): bool
    {
        return $agreement->billsOnARecurringCadence()
            && ($agreement->retainer_minutes !== null || $agreement->period_retainer_minutes !== null);
    }
}
