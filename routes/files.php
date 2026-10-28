<?php

use App\Http\Controllers\Files\AttachmentController;
use App\Http\Controllers\Files\AttachmentSharingController;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post(
        '/workspaces/{workspace}/attachments/{recordType}/{recordPublicId}',
        [AttachmentController::class, 'store'],
    )->whereUuid('recordPublicId')->name('svc.files.store');

    Route::get(
        '/workspaces/{workspace}/attachments/{clientAttachment}',
        [AttachmentController::class, 'download'],
    )->whereUuid('clientAttachment')->name('svc.files.download');

    Route::delete(
        '/workspaces/{workspace}/attachments/{clientAttachment}',
        [AttachmentController::class, 'destroy'],
    )->whereUuid('clientAttachment')->name('svc.files.destroy');
});

// New pages receive workspace chrome; JSON continuations share their current reader.
Route::middleware(['web', 'auth', ResolveWorkspaceNavigation::class])->group(function (): void {
    Route::get('/workspaces/{workspace}/attachment-records/{recordType}/{recordPublicId}/files', [AttachmentSharingController::class, 'legacyParentPage'])
        ->whereIn('recordType', ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'])->whereUuid('recordPublicId')->name('svc.files.parent.show');
    Route::get('/workspaces/{workspace}/attachment-records/{recordType}/{recordPublicId}/files/data', [AttachmentSharingController::class, 'legacyParentIndex'])
        ->whereIn('recordType', ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'])->whereUuid('recordPublicId')->name('svc.files.parent.index');
    Route::patch('/workspaces/{workspace}/attachments/{attachment}/visibility', [AttachmentSharingController::class, 'legacyVisibilityUpdate'])
        ->whereUuid('attachment')->name('svc.files.visibility.update');
    Route::prefix('/workspaces/{workspace}/clients/{clientCompany}/collaboration/attachments/{recordType}/{recordPublicId}')
        ->whereIn('recordType', ['topic', 'comment'])->whereUuid(['clientCompany', 'recordPublicId'])->group(function (): void {
            Route::get('/', [AttachmentSharingController::class, 'collaborationPage'])->name('svc.files.collaboration.show');
            Route::get('/data', [AttachmentSharingController::class, 'collaborationIndex'])->name('svc.files.collaboration.index');
            Route::post('/upload', [AttachmentSharingController::class, 'collaborationUpload'])->name('svc.files.collaboration.upload');
            Route::patch('/{attachment}/visibility', [AttachmentSharingController::class, 'collaborationVisibilityUpdate'])->whereUuid('attachment')->name('svc.files.collaboration.visibility.update');
            Route::delete('/{attachment}', [AttachmentSharingController::class, 'collaborationDelete'])->whereUuid('attachment')->name('svc.files.collaboration.destroy');
        });
    Route::prefix('/portal/{clientCompany}/attachments/{recordType}/{recordPublicId}')
        ->whereIn('recordType', ['proposal', 'invoice', 'project', 'task'])->whereUuid('recordPublicId')->group(function (): void {
            Route::get('/files', [AttachmentSharingController::class, 'portalLegacyPage'])->name('portal.files.parent.show');
            Route::get('/', [AttachmentSharingController::class, 'portalLegacyIndex'])->name('portal.files.parent.index');
            Route::get('/{attachment}/content', [AttachmentSharingController::class, 'portalLegacyDownload'])->whereUuid('attachment')->name('portal.files.parent.download');
        });
    Route::prefix('/portal/{clientCompany}/collaboration/attachments/{recordType}/{recordPublicId}')
        ->whereIn('recordType', ['topic', 'comment'])->whereUuid('recordPublicId')->group(function (): void {
            Route::get('/', [AttachmentSharingController::class, 'portalCollaborationPage'])->name('portal.files.collaboration.show');
            Route::get('/data', [AttachmentSharingController::class, 'portalCollaborationIndex'])->name('portal.files.collaboration.index');
            Route::get('/{attachment}/content', [AttachmentSharingController::class, 'portalCollaborationDownload'])->whereUuid('attachment')->name('portal.files.collaboration.download');
        });
});
