<?php

use App\Http\Controllers\Collaboration\CollaborationController;
use App\Http\Controllers\Collaboration\CommentController;
use App\Http\Controllers\Collaboration\PortalCollaborationController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

// Included by web.php; this file supplies authentication and workspace chrome.
Route::middleware(['auth', ResolveWorkspaceNavigation::class])->group(function (): void {
    Route::prefix('/workspaces/{workspace}/clients/{clientCompany}')->group(function (): void {
        Route::get('/collaboration', [CollaborationController::class, 'index'])->name('collaboration.index');
        Route::post('/collaboration', [CollaborationController::class, 'store'])->name('collaboration.store');
        Route::get('/collaboration/{topic}', [CollaborationController::class, 'show'])->whereUuid('topic')->name('collaboration.show');
        Route::patch('/collaboration/{topic}', [CollaborationController::class, 'update'])->whereUuid('topic')->name('collaboration.update');
        Route::delete('/collaboration/{topic}', [CollaborationController::class, 'destroy'])->whereUuid('topic')->name('collaboration.destroy');
        Route::get('/comments/{parentType}/{parentPublicId}', [CommentController::class, 'index'])->whereIn('parentType', ['task', 'topic'])->whereUuid('parentPublicId')->name('collaboration.comments.index');
        Route::post('/comments/{parentType}/{parentPublicId}', [CommentController::class, 'store'])->whereIn('parentType', ['task', 'topic'])->whereUuid('parentPublicId')->name('collaboration.comments.store');
        Route::patch('/comments/{comment}', [CommentController::class, 'update'])->whereUuid('comment')->name('collaboration.comments.update');
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->whereUuid('comment')->name('collaboration.comments.destroy');
    });
    Route::prefix('/portal/{clientCompany}/collaboration')->group(function (): void {
        Route::get('/', [PortalCollaborationController::class, 'index'])->name('portal.collaboration.index');
        Route::get('/comments/{parentType}/{parentPublicId}', [PortalCollaborationController::class, 'comments'])->whereIn('parentType', ['task', 'topic'])->whereUuid('parentPublicId')->name('portal.collaboration.comments.index');
        Route::get('/{topic}', [PortalCollaborationController::class, 'show'])->whereUuid('topic')->name('portal.collaboration.show');
    });
});
