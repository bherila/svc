<?php

namespace App\Services\Packages;

use App\Models\ClientAutopayConsent;
use App\Models\ClientCompany;
use App\Models\ClientStripePaymentMethod;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Services\Billing\Autopay\AutopayReader;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentAction;
use App\Support\Packages\ClientAutopay;
use App\Support\Packages\PackageTerms;

/**
 * Whether a company's autopay meets a version that requires it (ACC-4).
 *
 * Met when the company has a default saved method, autopay is on at exactly
 * the version's limit in the version's currency, and the consent standing
 * behind it is the client's own, given in the portal with the existing
 * wording ({@see AutopayConsentService::turnOn()} records it). A provider's
 * attested turn-on does not meet it: the package asks for the client's
 * authorization. Nor does a limit in another currency: autopay's limit is in
 * the company's billing currency, and 1,200 dollars is not 1,200 euros.
 * Reads only, every one scoped to the workspace.
 */
final class PackageAutopayRequirement
{
    public function __construct(
        private readonly AutopayConsentService $consents,
        private readonly AutopayReader $reader,
    ) {}

    public function satisfied(Workspace $workspace, ClientCompany $company, PackageTerms $terms): bool
    {
        return ClientAutopay::meets($this->clientAutopay($workspace, [$company->id])[$company->id] ?? null, $terms);
    }

    /**
     * Whether the company's autopay is in the version's currency at all: when
     * it is not, no consent the client can give here meets the version.
     */
    public function currencyMatches(Workspace $workspace, ClientCompany $company, PackageTerms $terms): bool
    {
        return $this->reader->limitCurrency($workspace, $company) === strtoupper($terms->currency);
    }

    /**
     * For each company whose autopay stands on the client's own consent with a
     * default saved method, the limit it is on at and the currency the client
     * authorized - only while that is still the company's billing currency.
     * Three bounded reads for any number of companies, so a page of
     * enrollments asks once.
     *
     * @param  list<int>  $companyIds
     * @return array<int, ClientAutopay> company id => where its autopay stands
     */
    public function clientAutopay(Workspace $workspace, array $companyIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (int $id): int => $id, $companyIds)));
        if ($ids === []) {
            return [];
        }
        /** @var array<int, ClientCompany> $on */
        $on = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $ids)
            ->whereNotNull('autopay_enabled_at')
            ->whereNotNull('autopay_limit_amount')
            ->get(['id', 'workspace_id', 'currency', 'autopay_limit_amount'])
            ->keyBy('id')
            ->all();
        if ($on === []) {
            return [];
        }
        $withDefault = ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', array_keys($on))
            ->where('is_default', true)
            ->pluck('client_company_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $standing = ClientAutopayConsent::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', ClientAutopayConsent::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('client_company_id', array_keys($on))
                ->groupBy('client_company_id')
                ->selectRaw('max(id)'))
            ->get();

        $autopay = [];
        foreach ($standing as $consent) {
            $companyId = (int) $consent->client_company_id;
            $company = $on[$companyId] ?? null;
            if (! $company instanceof ClientCompany) {
                continue;
            }
            $limit = (int) $company->autopay_limit_amount;
            $currency = strtoupper((string) $consent->currency);
            if ($consent->actor_role === AutopayActorRole::Client
                && $consent->action !== AutopayConsentAction::Disabled
                && in_array($companyId, $withDefault, true)
                && (int) $consent->limit_amount === $limit
                && $currency === $this->reader->limitCurrency($workspace, $company)) {
                $autopay[$companyId] = new ClientAutopay($limit, $currency);
            }
        }

        return $autopay;
    }

    /** The exact sentence the client authorizes, with the version's limit; null until there is a default method to name. */
    public function wording(Workspace $workspace, ClientCompany $company, int $limit): ?string
    {
        return $this->consents->authorizationWording($workspace, $company, $limit);
    }
}
