<?php

use App\Http\Controllers\SharedProjects\SharedProjectController;
use App\Http\Controllers\SharedProjects\SharedProjectInvitationController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

// Included by web.php; this file supplies authentication and workspace chrome.
//
// The project ids in these paths are plain strings, not route-model bindings: a
// shared project is not in the viewer's workspace, so the tenant binding cannot
// find it. SharedProjectAccess resolves every one, and answers 404 for any
// workspace that is neither the host nor an active participant.
Route::middleware('auth')->group(function (): void {
    Route::middleware(ResolveWorkspaceNavigation::class)->group(function (): void {
        Route::get('/workspaces/{workspace}/shared-projects', [SharedProjectController::class, 'index'])->name('shared-projects.index');
        Route::get('/workspaces/{workspace}/shared-projects/{sharedProject}', [SharedProjectController::class, 'show'])->whereUuid('sharedProject')->name('shared-projects.show');
    });

    // Who takes part is a person's decision: browser-only, never REST or MCP.
    Route::prefix('/workspaces/{workspace}/shared-projects/{sharedProject}')->whereUuid('sharedProject')->group(function (): void {
        Route::post('/invitations', [SharedProjectController::class, 'invite'])->middleware('throttle:invitations')->name('shared-projects.invitations.store');
        Route::delete('/invitations/{invitation}', [SharedProjectController::class, 'revokeInvitation'])->whereUuid('invitation')->name('shared-projects.invitations.destroy');
        Route::delete('/participants/{participation}', [SharedProjectController::class, 'revokeParticipant'])->whereUuid('participation')->name('shared-projects.participants.destroy');
        Route::post('/leave', [SharedProjectController::class, 'leave'])->name('shared-projects.leave');
        Route::put('/local-project', [SharedProjectController::class, 'link'])->name('shared-projects.local-project.update');
    });

    // An invitation is addressed to a person, not a workspace: no workspace in the path.
    Route::get('/shared-project-invitations/{invitation}', [SharedProjectInvitationController::class, 'show'])->whereUuid('invitation')->name('shared-project-invitations.show');
    Route::post('/shared-project-invitations/{invitation}/accept', [SharedProjectInvitationController::class, 'accept'])->whereUuid('invitation')->middleware('throttle:invitations')->name('shared-project-invitations.accept');
    Route::post('/shared-project-invitations/{invitation}/decline', [SharedProjectInvitationController::class, 'decline'])->whereUuid('invitation')->middleware('throttle:invitations')->name('shared-project-invitations.decline');
});
