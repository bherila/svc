<?php

namespace App\Queries\Packages;

use App\Models\ServicePackage;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Support\Packages\PackageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the public may read of a workspace's packages, by catalog token (PUB-1..3).
 *
 * The token is the only key: it is matched exactly, and a workspace without
 * one has no public catalog. Only published packages are visible; the catalog
 * lists those marked listed, while any published package is reachable by its
 * own link name. Everything else - an unknown token, a draft, a retired
 * package, a wrong link name - is the same null, which the controller turns
 * into the same 404, so the public cannot tell which.
 */
final class PublicPackageCatalog
{
    public function workspace(string $token): ?Workspace
    {
        if (! preg_match('/^[a-z0-9]{24}$/', $token)) {
            return null;
        }

        return Workspace::query()->where('package_catalog_token', $token)->first();
    }

    /** @return Collection<int, ServicePackage> listed, published packages with their published version and components */
    public function listed(Workspace $workspace): Collection
    {
        return $this->published($workspace)->where('is_listed', true)->orderBy('id')->get();
    }

    public function package(Workspace $workspace, string $linkName): ?ServicePackage
    {
        return $this->published($workspace)->where('link_name', $linkName)->first();
    }

    /** @return Builder<ServicePackage> */
    private function published(Workspace $workspace): Builder
    {
        return ServicePackage::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', PackageStatus::Published->value)
            ->whereNotNull('published_version_id')
            ->with(['publishedVersion' => fn ($version) => $version
                ->where('workspace_id', $workspace->id)
                ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])]);
    }

    /** The published version of a package this query returned. */
    public static function versionOf(ServicePackage $package): ServicePackageVersion
    {
        $version = $package->publishedVersion;
        if (! $version instanceof ServicePackageVersion) {
            throw new \LogicException('A published package was read without its published version.');
        }

        return $version;
    }
}
