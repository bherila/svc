<?php

use App\Http\Controllers\Engagement\AgreementController;
use App\Http\Controllers\Engagement\ProposalController;
use App\Http\Controllers\Engagement\TimeEntryController;
use App\Http\Controllers\Engagement\TimeSheetController;
use App\Http\Controllers\WorkspaceModuleRedirectController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    // The sheet is a tab of one client, and only that. The company is not a
    // filter the page chooses, it is where the operator already is - so it is
    // bound by route, and the page carries no picker of its own.
    Route::get('/workspaces/{workspace}/clients/{clientCompany}/time', TimeSheetController::class)
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('clients.time');

    // The workspace-wide sheet's URL, kept working.
    Route::get('/workspaces/{workspace}/time', WorkspaceModuleRedirectController::class)
        ->defaults('module', 'time')
        ->name('svc.engagement.time-entries.index');
    Route::post('/workspaces/{workspace}/projects/{clientProject}/time-entries', [TimeEntryController::class, 'store'])
        ->name('svc.engagement.time-entries.store');
    Route::patch('/workspaces/{workspace}/time-entries/{timeEntry}', [TimeEntryController::class, 'update'])
        ->name('svc.engagement.time-entries.update');
    Route::delete('/workspaces/{workspace}/time-entries/{timeEntry}', [TimeEntryController::class, 'destroy'])
        ->name('svc.engagement.time-entries.destroy');
    Route::post('/workspaces/{workspace}/time-entries/approve', [TimeEntryController::class, 'approve'])
        ->name('svc.engagement.time-entries.approve');

    Route::post('/workspaces/{workspace}/clients/{clientCompany}/proposals', [ProposalController::class, 'store'])
        ->name('svc.engagement.proposals.store');
    Route::post('/workspaces/{workspace}/proposals/{clientProposal}/send', [ProposalController::class, 'send'])
        ->name('svc.engagement.proposals.send');
    Route::post('/portal/{clientCompany}/proposals/{clientProposal}/accept', [ProposalController::class, 'accept'])
        ->name('svc.engagement.proposals.accept');

    Route::post('/workspaces/{workspace}/clients/{clientCompany}/agreements', [AgreementController::class, 'store'])
        ->name('svc.engagement.agreements.store');
    // Correcting the record, as opposed to moving it through its lifecycle.
    // The imported agreements that arrived titled "Legacy Agreement" are what
    // this is for, and the same endpoint takes the whole terms form.
    Route::patch('/workspaces/{workspace}/agreements/{clientAgreement}', [AgreementController::class, 'update'])
        ->name('svc.engagement.agreements.update');
    Route::post('/workspaces/{workspace}/agreements/{clientAgreement}/activate', [AgreementController::class, 'activate'])
        ->name('svc.engagement.agreements.activate');
    Route::post('/workspaces/{workspace}/agreements/{clientAgreement}/sign', [AgreementController::class, 'sign'])
        ->name('svc.engagement.agreements.sign');
});
