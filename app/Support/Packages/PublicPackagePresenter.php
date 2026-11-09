<?php

namespace App\Support\Packages;

use App\Models\ServicePackage;
use App\Models\Workspace;

/**
 * What a visitor sees of a published package: the offer, never the internals.
 *
 * No ids, statuses, versions or operator fields - only the words, the prices
 * and the terms someone deciding whether to sign up needs. Amounts stay in
 * minor units with their currency; the page formats them.
 */
final class PublicPackagePresenter
{
    /** @return array<string, mixed> */
    public static function summary(Workspace $workspace, ServicePackage $package, PackageTerms $terms): array
    {
        return [
            'name' => $terms->name,
            'summary' => $terms->summary,
            'currency' => $terms->currency,
            'billing_cadence' => $terms->billingCadence->value,
            'cycle_amount' => self::cycleAmount($terms),
            'setup_fee_amount' => $terms->setupFeeTotal() > 0 ? $terms->setupFeeTotal() : null,
            'minimum_term_months' => $terms->minimumTermMonths,
            'url' => PackagePublicUrls::package($workspace, $package),
        ];
    }

    /** @return array<string, mixed> */
    public static function details(Workspace $workspace, ServicePackage $package, PackageTerms $terms): array
    {
        return [
            ...self::summary($workspace, $package, $terms),
            'body_markdown' => $terms->bodyMarkdown,
            'components' => array_map(static fn (PackageComponentTerms $component): array => [
                'description' => $component->description,
                'kind' => $component->kind->value,
                'cadence' => $component->cadence->value,
                'amount' => $component->total(),
            ], $terms->components),
            'hourly_rate_amount' => $terms->hourlyRateAmount,
            'retainer_amount' => $terms->retainerAmount,
            'retainer_minutes' => $terms->retainerMinutes,
            'rollover_months' => $terms->rolloverMonths,
            'signup' => [
                'setup_fee_amount' => $terms->chargeSetupFeeAtSignup && $terms->setupFeeTotal() > 0 ? $terms->setupFeeTotal() : null,
                'charges_first_period' => $terms->chargeFirstPeriod,
                'requires_autopay' => $terms->requireAutopay,
                'autopay_limit_amount' => $terms->requireAutopay ? $terms->autopayLimitAmount : null,
            ],
            'notice_days' => $terms->noticeDays,
            'cancellation_mode' => $terms->cancellationMode->value,
        ];
    }

    /**
     * What one billing cycle costs at the headline: the components that recur
     * on the package's own cadence, plus the retainer. Usage is extra and is
     * shown separately as the hourly rate.
     */
    public static function cycleAmount(PackageTerms $terms): int
    {
        $total = $terms->retainerAmount ?? 0;
        foreach ($terms->components as $component) {
            if ($component->kind === PackageComponentKind::Recurring && $component->cadence === $terms->billingCadence) {
                $total += $component->total();
            }
        }

        return $total;
    }
}
