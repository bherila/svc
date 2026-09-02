<?php

namespace App\Support\Engagement;

use App\Models\ClientAgreement;

/**
 * One agreement's commercial terms, as both audiences read them.
 *
 * Extracted because the client now reads the same screen the operator does, and
 * two hand-built copies of these figures is exactly the drift that matters
 * here: a retainer or a rate shown one way internally and another way to the
 * client is an argument about what was agreed.
 *
 * The derived accessors are used rather than the raw columns on purpose. A
 * one-time arrangement can carry retainer columns describing something bought
 * once, and reporting those beside a cadence reads as capacity granted again
 * every period.
 */
final class AgreementTermsPayload
{
    /**
     * @param  string|null  $projectName  named only where the reader may see it
     * @return array<string, mixed>
     */
    public static function for(ClientAgreement $agreement, ?string $projectName): array
    {
        $grantsRetainer = $agreement->billsOnARecurringCadence()
            && ($agreement->retainer_minutes !== null || $agreement->period_retainer_minutes !== null);

        return [
            'id' => $agreement->public_id,
            'title' => $agreement->title,
            'status' => $agreement->status,
            'currency' => $agreement->currency,
            'billing_cadence' => $agreement->billing_cadence,
            'is_recurring' => $agreement->billsOnARecurringCadence(),
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
}
