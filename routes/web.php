<?php

use App\Http\Controllers\ClientCompanyController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\ClientProjectController;
use App\Http\Controllers\ClientTaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OAuthLoginController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/login', [OAuthLoginController::class, 'redirect'])->name('login');
Route::get('/oauth/redirect', [OAuthLoginController::class, 'redirect'])->name('oauth.redirect');
Route::get('/oauth/callback', [OAuthLoginController::class, 'callback'])->name('oauth.callback');

Route::middleware('auth')->group(function (): void {
    Route::get('/app', DashboardController::class)->name('dashboard');
    Route::post('/logout', [OAuthLoginController::class, 'logout'])->name('logout');

    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('/workspaces/{workspace}/clients', [ClientCompanyController::class, 'store'])->name('clients.store');
    Route::post('/workspaces/{workspace}/clients/{clientCompany}/projects', [ClientProjectController::class, 'store'])
        ->name('projects.store');
    Route::post('/workspaces/{workspace}/projects/{clientProject}/tasks', [ClientTaskController::class, 'store'])
        ->name('tasks.store');
    Route::patch('/workspaces/{workspace}/tasks/{clientTask}', [ClientTaskController::class, 'update'])
        ->name('tasks.update');

    Route::get('/portal/{clientCompany}', [ClientPortalController::class, 'show'])->name('portal.show');
});
