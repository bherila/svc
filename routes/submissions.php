<?php

use App\Http\Controllers\Submission\ApprovedTimesheetController;
use App\Http\Controllers\Submission\SubmissionPageController;
use App\Http\Controllers\Submission\WorkspaceEditionController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

// Included by web.php. Submission and the approved timesheet (docs/ux-overhaul.md
// sections 7b and 7e). The pages read; submitting and withdrawing post to the agent
// API (`submissions.*`), so there is no web mutation to name beside them.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/workspaces/{workspace}/submissions', SubmissionPageController::class)
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('workspaces.submissions');
    Route::get('/workspaces/{workspace}/timesheet', [ApprovedTimesheetController::class, 'show'])
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('workspaces.timesheet');
    // The file, in the browser: the same rows as the page, as CSV or PDF.
    Route::get('/workspaces/{workspace}/timesheet/export', [ApprovedTimesheetController::class, 'export'])
        ->name('workspaces.timesheet.export');
    // A light workspace's owner turning on the rest of SVC. One way, in the browser.
    Route::post('/workspaces/{workspace}/edition/full', [WorkspaceEditionController::class, 'full'])
        ->name('workspaces.edition.full');
});
