<?php

namespace App\Services\Packages;

use App\Models\ClientAutopayConsent;
use App\Models\ClientCompany;
use App\Models\ClientStripePaymentMethod;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentAction;

/**
 * Whether a company's autopay meets a version that requires it (ACC-4).
 *
 * Met when the company has a default saved method, autopay is on at exactly
 * the version's limit, and the consent standing behind it is the client's
 * own, given in the portal with the existing wording
 * ({@see AutopayConsentService::turnOn()} records it). A provider's attested
 * turn-on does not meet it: the package asks for the client's authorization.
 * Reads only, every one scoped to the workspace.
 */
final class PackageAutopayRequirement
{
    public function __construct(
        private readonly AutopayConsentService $consents,
    ) {}

    public function satisfied(Workspace $workspace, ClientCompany $company, int $limit): bool
    {
        return ($this->clientLimits($workspace, [$company->id])[$company->id] ?? null) === $limit;
    }

    /**
     * For each company whose autopay stands on the client's own consent with a
     * default saved method, the limit it is on at. Three bounded reads for
     * any number of companies, so a page of enrollments asks once.
     *
     * @param  list<int>  $companyIds
     * @return array<int, int> company id => limit, in minor units
     */
    public function clientLimits(Workspace $workspace, array $companyIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (int $id): int => $id, $companyIds)));
        if ($ids === []) {
            return [];
        }
        /** @var array<int, int> $on */
        $on = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $ids)
            ->whereNotNull('autopay_enabled_at')
            ->whereNotNull('autopay_limit_amount')
            ->pluck('autopay_limit_amount', 'id')
            ->map(static fn (mixed $limit): int => (int) $limit)
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

        $limits = [];
        foreach ($standing as $consent) {
            $companyId = (int) $consent->client_company_id;
            if ($consent->actor_role === AutopayActorRole::Client
                && $consent->action !== AutopayConsentAction::Disabled
                && in_array($companyId, $withDefault, true)
                && isset($on[$companyId])
                && (int) $consent->limit_amount === $on[$companyId]) {
                $limits[$companyId] = $on[$companyId];
            }
        }

        return $limits;
    }

    /** The exact sentence the client authorizes, with the version's limit; null until there is a default method to name. */
    public function wording(Workspace $workspace, ClientCompany $company, int $limit): ?string
    {
        return $this->consents->authorizationWording($workspace, $company, $limit);
    }
}
