<?php

namespace App\Services\Packages;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use App\Support\Billing\BillingCadence;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageComponentTerms;
use App\Support\Packages\PackageTerms;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Puts a package version's terms on an agreement (PKG-7).
 *
 * The one place a package's terms become an agreement's: approval writes them
 * onto a new draft, and acceptance writes the accepted version's terms again,
 * whether a newer version was published in between (EDGE-7) or not, so what
 * the client signed is what the sign-up invoice and the agreement bill
 * (ACC-2). Agreement fields go through AgreementWorkflow,
 * so the agreement's own rules and activity apply; recurring items are written
 * here because nothing else writes them. Only a draft is ever rewritten.
 *
 * A setup fee charged at sign-up is billed on the sign-up invoice and is
 * therefore never also a recurring item; one billed with the first cycle is a
 * one-time recurring item (ACC-3).
 */
final class PackageAgreementTerms
{
    public function __construct(private readonly AgreementWorkflow $agreements) {}

    public function createDraft(Workspace $workspace, ClientCompany $company, ClientProject $project, ServicePackageVersion $version, CarbonImmutable $startsOn): ClientAgreement
    {
        $terms = PackageTerms::fromVersion($version);
        $agreement = $this->agreements->create($workspace, $company, $project, null, [
            'title' => $terms->name,
            'starts_on' => $startsOn->toDateString(),
            'agreement_text' => $terms->agreementText,
            'is_visible_to_client' => true,
            'billing_cadence' => $terms->billingCadence->value,
            'currency' => $terms->currency,
            'hourly_rate_amount' => $terms->hourlyRateAmount,
            ...array_intersect_key(self::retainerTerms($terms), array_flip(['retainer_amount', 'retainer_minutes'])),
        ]);

        return $this->apply($workspace, $agreement, $version);
    }

    /**
     * Rewrite a draft agreement's terms and recurring items from a version.
     *
     * `$enrollment` is the enrollment that owns the draft, locked by the
     * caller; null only while the offer that creates both is being made.
     */
    public function apply(Workspace $workspace, ClientAgreement $agreement, ServicePackageVersion $version, ?ServicePackageEnrollment $enrollment = null): ClientAgreement
    {
        if ($agreement->status !== 'draft') {
            throw new DomainException('Only a draft agreement takes a package version\'s terms.');
        }
        $terms = PackageTerms::fromVersion($version);
        $updated = $this->agreements->update($workspace, $agreement, self::agreementTerms($terms), enrollment: $enrollment);

        ClientAgreementRecurringItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_agreement_id', $updated->id)
            ->delete();
        foreach (self::recurringItems($terms) as $index => $item) {
            $updated->recurringItems()->create([
                ...$item,
                'workspace_id' => $workspace->id,
                'currency' => $terms->currency,
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        return $updated;
    }

    /**
     * The agreement fields a version sets, by the agreement's own names.
     *
     * @return array<string, mixed>
     */
    public static function agreementTerms(PackageTerms $terms): array
    {
        return [
            'title' => $terms->name,
            'agreement_text' => $terms->agreementText,
            'billing_cadence' => $terms->billingCadence->value,
            'currency' => $terms->currency,
            'hourly_rate_amount' => $terms->hourlyRateAmount,
            ...self::retainerTerms($terms),
            'rollover_months' => $terms->rolloverMonths,
            'first_cycle_proration' => $terms->firstCycleProration?->value,
        ];
    }

    /**
     * A package's retainer is what one billing cycle includes; an agreement's
     * `retainer_*` columns are per month and are scaled by the cycle length
     * unless the `period_retainer_*` overrides say what one cycle includes.
     *
     * So a monthly package writes the monthly columns as they are. A
     * quarterly, half-yearly or annual one writes the per-cycle overrides with
     * the package's own figures - which the billing engine prefers - and the
     * monthly columns with the matching per-month share, so the two never
     * disagree (Codex P1 on #666: copying the cycle figures into the monthly
     * columns tripled a quarterly package's price and hours).
     *
     * @return array{retainer_amount: int|null, retainer_minutes: int|null, period_retainer_amount: int|null, period_retainer_minutes: int|null}
     */
    public static function retainerTerms(PackageTerms $terms): array
    {
        $cadence = BillingCadence::tryFrom($terms->billingCadence->value);
        $months = $cadence?->monthsInCycle() ?? 1;
        if ($months === 1) {
            return [
                'retainer_amount' => $terms->retainerAmount,
                'retainer_minutes' => $terms->retainerMinutes,
                'period_retainer_amount' => null,
                'period_retainer_minutes' => null,
            ];
        }

        return [
            'retainer_amount' => $terms->retainerAmount === null ? null : (int) round($terms->retainerAmount / $months),
            'retainer_minutes' => $terms->retainerMinutes === null ? null : (int) round($terms->retainerMinutes / $months),
            'period_retainer_amount' => $terms->retainerAmount,
            'period_retainer_minutes' => $terms->retainerMinutes,
        ];
    }

    /**
     * The recurring items a version puts on its agreement.
     *
     * @return list<array{description: string, cadence: string, amount: int, quantity: string, is_taxable: bool}>
     */
    public static function recurringItems(PackageTerms $terms): array
    {
        $items = [];
        foreach ($terms->components as $component) {
            if ($component->kind === PackageComponentKind::SetupFee && $terms->chargeSetupFeeAtSignup) {
                continue;
            }
            $items[] = self::item($component);
        }

        return $items;
    }

    /** @return array{description: string, cadence: string, amount: int, quantity: string, is_taxable: bool} */
    private static function item(PackageComponentTerms $component): array
    {
        return [
            'description' => $component->description,
            'cadence' => $component->cadence->value,
            'amount' => $component->amount,
            'quantity' => $component->quantity,
            'is_taxable' => $component->isTaxable,
        ];
    }
}
