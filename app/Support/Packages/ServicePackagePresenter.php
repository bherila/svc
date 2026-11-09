<?php

namespace App\Support\Packages;

use App\Models\ServicePackage;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;

/**
 * The one shape of a service package, for the agent API and the website.
 *
 * Reads only what the caller loaded (versions with their components), so it
 * issues no queries and is tested without a database.
 */
final class ServicePackagePresenter
{
    /**
     * @param  ServicePackageVersion|null  $published  the package's published version, components loaded
     * @param  ServicePackageVersion|null  $draft  its draft, components loaded
     * @return array<string, mixed>
     */
    public static function package(Workspace $workspace, ServicePackage $package, ?ServicePackageVersion $published, ?ServicePackageVersion $draft, bool $stripeConnected): array
    {
        $status = $package->packageStatus();
        $draftTerms = $draft === null ? null : PackageTerms::fromVersion($draft);

        return [
            'id' => $package->public_id,
            'link_name' => $package->link_name,
            'status' => $status->value,
            'is_listed' => $package->is_listed,
            'portal_addable' => $package->portal_addable,
            'public_url' => $status === PackageStatus::Published ? PackagePublicUrls::package($workspace, $package) : null,
            'published' => $published === null ? null : self::version($published),
            'draft' => $draft === null ? null : self::version($draft),
            'publish_refusals' => $draftTerms === null ? [] : array_map(
                static fn (PackageRefusal $refusal): array => ['code' => $refusal->value, 'message' => $refusal->message()],
                PackagePublishRules::refusals($draftTerms, $stripeConnected),
            ),
            'version' => AgentApiVersion::for($package),
            'created_at' => $package->created_at?->toIso8601String(),
            'updated_at' => $package->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function version(ServicePackageVersion $version): array
    {
        $terms = PackageTerms::fromVersion($version);

        return [
            'id' => $version->public_id,
            'number' => $version->number,
            'published_at' => $version->published_at?->toIso8601String(),
            'terms' => $terms->toArray(),
            'setup_fee_total' => $terms->setupFeeTotal(),
        ];
    }
}
