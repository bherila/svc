<?php

namespace App\Services\Packages;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageComponentTerms;
use App\Support\Packages\PackageTerms;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Puts a package version's terms on an agreement (PKG-7).
 *
 * The one place a package's terms become an agreement's: approval writes them
 * onto a new draft, and acceptance writes them again when a newer version was
 * published in between (EDGE-7). Agreement fields go through AgreementWorkflow,
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
            'retainer_amount' => $terms->retainerAmount,
            'retainer_minutes' => $terms->retainerMinutes,
        ]);

        return $this->apply($workspace, $agreement, $version);
    }

    /** Rewrite a draft agreement's terms and recurring items from a version. */
    public function apply(Workspace $workspace, ClientAgreement $agreement, ServicePackageVersion $version): ClientAgreement
    {
        if ($agreement->status !== 'draft') {
            throw new DomainException('Only a draft agreement takes a package version\'s terms.');
        }
        $terms = PackageTerms::fromVersion($version);
        $updated = $this->agreements->update($workspace, $agreement, self::agreementTerms($terms));

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
            'retainer_amount' => $terms->retainerAmount,
            'retainer_minutes' => $terms->retainerMinutes,
            'rollover_months' => $terms->rolloverMonths,
            'first_cycle_proration' => $terms->firstCycleProration?->value,
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
