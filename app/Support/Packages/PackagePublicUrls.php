<?php

namespace App\Support\Packages;

use App\Models\ServicePackage;
use App\Models\Workspace;

/**
 * Where a workspace's packages live on the public web (PUB-2).
 *
 * The address carries the workspace's catalog token and the package's link
 * name, never a database id or UUID. Null until the workspace has a token,
 * which it gets when its first package is published.
 */
final class PackagePublicUrls
{
    public static function catalog(Workspace $workspace): ?string
    {
        $token = $workspace->package_catalog_token;

        return $token === null ? null : self::base().'/s/'.$token;
    }

    public static function package(Workspace $workspace, ServicePackage $package): ?string
    {
        $catalog = self::catalog($workspace);

        return $catalog === null ? null : $catalog.'/'.$package->link_name;
    }

    private static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
