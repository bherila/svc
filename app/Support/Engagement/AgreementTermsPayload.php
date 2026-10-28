<?php

namespace App\Support\Engagement;

use App\Models\ClientAgreement;
use App\Support\AgentApi\Presenters\AgreementReadPresenter;

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
        $facts = AgreementReadPresenter::commercialTerms($agreement, $projectName);

        return [
            'id' => $facts['id'],
            'title' => $facts['title'],
            'status' => $facts['status'],
            'currency' => $facts['currency'],
            'billing_cadence' => $facts['billing_cadence'],
            'effective_billing_cadence' => null,
            'effective_first_cycle_proration' => null,
            'is_recurring' => $facts['is_recurring'],
            'starts_on' => $facts['starts_on'],
            'ends_on' => $facts['ends_on'],
            'signed_at' => $facts['signed_at'],
            'retainer_minutes_per_period' => $facts['retainer_minutes_per_period'],
            'retainer_minutes_per_month' => null,
            'retainer_amount_per_period' => $facts['retainer_amount_per_period'],
            'hourly_rate_amount' => $facts['hourly_rate_amount'],
            'rollover_months' => $facts['rollover_months'],
            'project' => $facts['project'],
        ];
    }
}
