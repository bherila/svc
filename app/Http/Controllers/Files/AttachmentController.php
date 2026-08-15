<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Http\Requests\Files\StoreAttachmentRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentRecordResolver;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function store(
        StoreAttachmentRequest $request,
        Workspace $workspace,
        string $recordType,
        string $recordPublicId,
        AttachmentRecordResolver $recordResolver,
        AttachmentStorageService $storage,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('manage', $workspace);

        $record = $recordResolver->resolve($workspace, $recordType, $recordPublicId);
        $file = $request->file('file');
        $user = $request->user();
        abort_unless($file instanceof UploadedFile, 422);
        abort_unless($user instanceof User, 401);

        $attachment = $storage->store($workspace, $record, $file, $user);

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $attachment->public_id,
                'record_type' => $attachment->record_type,
                'record_id' => $attachment->record_public_id,
                'media_type' => $attachment->media_type,
                'bytes' => $attachment->bytes,
                'sha256' => $attachment->sha256,
                'status' => $attachment->lifecycle_state,
            ], 201);
        }

        return redirect()->back()->with('attachment_id', $attachment->public_id);
    }

    public function download(
        Request $request,
        Workspace $workspace,
        string $clientAttachment,
        AttachmentStorageService $storage,
        AttachmentRecordResolver $recordResolver,
    ): StreamedResponse {
        $attachment = $storage->findForWorkspace($workspace, $clientAttachment);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(
            Gate::forUser($user)->allows('view', $workspace)
                || $recordResolver->portalUserCanView($user, $workspace, $attachment),
            403,
        );

        return $storage->download($attachment);
    }

    public function destroy(
        Workspace $workspace,
        string $clientAttachment,
        AttachmentStorageService $storage,
        Request $request,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('manage', $workspace);

        $attachment = $storage->findForWorkspace($workspace, $clientAttachment);
        $storage->requestDeletion($attachment);

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $attachment->public_id,
                'status' => 'deleting',
            ], 202);
        }

        return redirect()->back()->with('status', 'Attachment queued for deletion.');
    }
}
