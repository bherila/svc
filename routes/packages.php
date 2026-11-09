<?php

use App\Http\Controllers\Packages\PackageAdminController;
use App\Http\Controllers\Packages\PublicPackageController;
use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

// Included by web.php. Service packages (docs/service-packages.md). Every route
// here answers 404 while the feature flag is off (X-1).

// The public pages: no sign-in, addressed by the workspace's catalog token and
// the package's link name, never an id (PUB-2). Throttled per address.
Route::middleware(['web', EnsureServicePackagesEnabled::class, 'throttle:public-packages'])->group(function (): void {
    Route::get('/s/{token}', [PublicPackageController::class, 'catalog'])
        ->where('token', '[a-z0-9]{1,64}')
        ->name('packages.public.catalog');
    Route::get('/s/{token}/{linkName}', [PublicPackageController::class, 'show'])
        ->where('token', '[a-z0-9]{1,64}')
        ->where('linkName', '[a-z0-9-]{1,64}')
        ->name('packages.public.show');
});

// The request form. Its own guards answer every refusal alike (PUB-5), so it
// takes no throttle middleware whose 429 would say which guard fired.
Route::middleware(['web', EnsureServicePackagesEnabled::class])->group(function (): void {
    Route::post('/s/{token}/{linkName}/requests', [PublicPackageController::class, 'submit'])
        ->where('token', '[a-z0-9]{1,64}')
        ->where('linkName', '[a-z0-9-]{1,64}')
        ->name('packages.public.request');
});

// The owner and admin screens. Pages only: changes go to the agent REST
// endpoints the pages are given, which run the same workflow agents use.
Route::middleware(['web', 'auth', EnsureServicePackagesEnabled::class, ResolveWorkspaceNavigation::class])->group(function (): void {
    Route::get('/workspaces/{workspace}/packages', [PackageAdminController::class, 'index'])->name('packages.index');
    Route::get('/workspaces/{workspace}/packages/new', [PackageAdminController::class, 'create'])->name('packages.create');
    Route::get('/workspaces/{workspace}/packages/requests', [PackageAdminController::class, 'requests'])->name('packages.requests.index');
    Route::get('/workspaces/{workspace}/packages/{servicePackage}', [PackageAdminController::class, 'edit'])
        ->whereUuid('servicePackage')
        ->name('packages.edit');
});
