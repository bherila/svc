<?php

use App\Http\Controllers\Engagement\AgreementController;
use App\Http\Controllers\Engagement\ProposalController;
use App\Http\Controllers\Engagement\TimeEntryController;
use App\Http\Controllers\Engagement\TimeSheetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/workspaces/{workspace}/time', TimeSheetController::class)
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
    Route::post('/workspaces/{workspace}/agreements/{clientAgreement}/activate', [AgreementController::class, 'activate'])
        ->name('svc.engagement.agreements.activate');
    Route::post('/workspaces/{workspace}/agreements/{clientAgreement}/sign', [AgreementController::class, 'sign'])
        ->name('svc.engagement.agreements.sign');
});
