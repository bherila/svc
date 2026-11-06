<?php

use App\Http\Controllers\Review\CrossWorkspaceApprovalController;
use App\Http\Controllers\Review\ReviewerGrantController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

// Included by web.php. Reviewer grants (docs/ux-overhaul.md section 7d) and the
// one "To approve" that spans every provider that lets a person decide.
Route::middleware(['web', 'auth'])->group(function (): void {
    // The provider's side: give a grant, see them, take one back. Owners and admins only,
    // checked again by the controller and the service.
    Route::get('/workspaces/{workspace}/reviewers', [ReviewerGrantController::class, 'show'])
        ->middleware(ResolveWorkspaceNavigation::class)
        ->name('workspaces.reviewers');
    Route::post('/workspaces/{workspace}/reviewers', [ReviewerGrantController::class, 'store'])->name('workspaces.reviewers.store');
    Route::delete('/workspaces/{workspace}/reviewers/{grant}', [ReviewerGrantController::class, 'destroy'])
        ->whereUuid('grant')->name('workspaces.reviewers.destroy');

    // The reviewer's side. Reached outside any workspace: a reviewer may belong to none, and the
    // page is built from the signed-in person's own memberships and grants.
    Route::get('/approvals', [CrossWorkspaceApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/{source}/{id}/approve', [CrossWorkspaceApprovalController::class, 'approve'])
        ->whereIn('source', ['company', 'grant'])->whereUuid('id')->name('approvals.approve');
    Route::post('/approvals/{source}/{id}/dispute', [CrossWorkspaceApprovalController::class, 'dispute'])
        ->whereIn('source', ['company', 'grant'])->whereUuid('id')->name('approvals.dispute');
});
