<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\Workspace;
use App\Support\Billing\ReplayCadenceAgreement;
use Carbon\CarbonImmutable;

/** Loads cadence proof inputs through one tenant-scoped persistence boundary. */
final class ReplayCadenceAgreementRepository
{
    /**
     * @param  list<int>  $companyIds
     * @return array<int, list<ReplayCadenceAgreement>>
     */
    public function forWorkspaceCompanies(Workspace $workspace, array $companyIds): array
    {
        $companyIds = array_values(array_unique(array_filter(
            array_map('intval', $companyIds),
            static fn (int $companyId): bool => $companyId > 0,
        )));
        if ($companyIds === []) {
            return [];
        }

        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', $companyIds)
            ->whereIn('status', ['active', 'paused', 'terminated', 'expired'])
            ->whereNotNull('starts_on')
            ->get();

        $byCompany = [];
        foreach ($agreements as $agreement) {
            if (! $agreement->billsOnARecurringCadence() || $agreement->starts_on === null) {
                continue;
            }

            $byCompany[(int) $agreement->client_company_id][] = new ReplayCadenceAgreement(
                companyId: (int) $agreement->client_company_id,
                agreementId: (int) $agreement->id,
                currency: (string) $agreement->currency,
                startsOn: CarbonImmutable::instance($agreement->starts_on)->startOfDay(),
                endsOn: $agreement->ends_on === null
                    ? null
                    : CarbonImmutable::instance($agreement->ends_on)->startOfDay(),
                cadence: $agreement->effectiveBillingCadence(),
                firstCycleProration: $agreement->effectiveFirstCycleProration(),
                monthlyHours: $agreement->retainerMonthlyHours(),
                monthlyFee: $agreement->retainerMonthlyFee(),
                periodHoursOverride: $agreement->periodRetainerHoursOverride(),
                periodFeeOverride: $agreement->periodRetainerFeeOverride(),
                hourlyRateAmount: (int) ($agreement->hourly_rate_amount ?? 0),
            );
        }

        return $byCompany;
    }
}
