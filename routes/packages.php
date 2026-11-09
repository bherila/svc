<?php

use App\Http\Controllers\Packages\PackageAdminController;
use App\Http\Controllers\Packages\PortalCancellationController;
use App\Http\Controllers\Packages\PortalServicesController;
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

// The portal's Services tab (ACC-8, ACC-1, ACC-2): a client's services, adding a
// package and signing an offer. In the portal's route family, so web.php gives
// every one the portal ceiling and the writes the lower portal-writes one.
// Adding and accepting are browser-only: neither is an agent operation.
Route::middleware(['web', 'auth', EnsureServicePackagesEnabled::class, ResolveWorkspaceNavigation::class])->group(function (): void {
    Route::get('/portal/{clientCompany}/services', [PortalServicesController::class, 'index'])->name('portal.services');
    Route::post('/portal/{clientCompany}/services', [PortalServicesController::class, 'add'])->name('portal.services.add');
    Route::get('/portal/{clientCompany}/services/{enrollment}', [PortalServicesController::class, 'show'])
        ->whereUuid('enrollment')
        ->name('portal.services.show');
    Route::post('/portal/{clientCompany}/services/{enrollment}/accept', [PortalServicesController::class, 'accept'])
        ->whereUuid('enrollment')
        ->name('portal.services.accept');
    // Cancelling an agreement (CAN-3, CAN-4, EDGE-9): a company contact's own
    // act, browser-only like accepting; agents read the state through
    // client_services.* and owners decide through cancellation_requests.*.
    Route::post('/portal/{clientCompany}/agreements/{clientAgreement}/cancellation', [PortalCancellationController::class, 'store'])
        ->name('portal.agreements.cancellation.store');
    Route::post('/portal/{clientCompany}/agreements/{clientAgreement}/cancellation/withdraw', [PortalCancellationController::class, 'withdraw'])
        ->name('portal.agreements.cancellation.withdraw');
    Route::post('/portal/{clientCompany}/agreements/{clientAgreement}/cancellation/undo', [PortalCancellationController::class, 'undo'])
        ->name('portal.agreements.cancellation.undo');
});
