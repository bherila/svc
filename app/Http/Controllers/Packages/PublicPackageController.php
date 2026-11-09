<?php

namespace App\Http\Controllers\Packages;

use App\Http\Controllers\Controller;
use App\Queries\Packages\PublicPackageCatalog;
use App\Support\Packages\PackagePublicUrls;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PublicPackagePresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public catalog and package pages (docs/service-packages.md, PUB-1..3).
 *
 * No sign-in. Every miss is the same 404 (PUB-1). The pages carry the
 * workspace's name and the package's offer, never an id.
 */
final class PublicPackageController extends Controller
{
    public function __construct(private readonly PublicPackageCatalog $catalog) {}

    public function catalog(string $token): Response
    {
        $workspace = $this->catalog->workspace($token) ?? abort(404);

        return Inertia::render('packages/public/catalog', [
            'workspace' => ['name' => $workspace->name],
            'packages' => $this->catalog->listed($workspace)->map(
                fn ($package): array => PublicPackagePresenter::summary($workspace, $package, PackageTerms::fromVersion(PublicPackageCatalog::versionOf($package))),
            )->values()->all(),
            'sign_in_url' => route('login', absolute: false),
        ]);
    }

    public function show(string $token, string $linkName): Response
    {
        $workspace = $this->catalog->workspace($token) ?? abort(404);
        $package = $this->catalog->package($workspace, $linkName) ?? abort(404);

        return Inertia::render('packages/public/show', [
            'workspace' => ['name' => $workspace->name],
            'package' => PublicPackagePresenter::details($workspace, $package, PackageTerms::fromVersion(PublicPackageCatalog::versionOf($package))),
            'catalog_url' => PackagePublicUrls::catalog($workspace),
            'sign_in_url' => route('login', absolute: false),
            // The request form arrives with sign-up requests (slice 2).
            'request' => null,
        ]);
    }
}
