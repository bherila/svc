<?php

use App\Http\Controllers\Files\AttachmentController;
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
