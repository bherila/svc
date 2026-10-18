<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Http\Requests\Files\StoreAttachmentRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentAction;
use App\Services\Files\AttachmentRecordResolver;
use App\Services\Files\AttachmentStorageService;
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
        AttachmentAction $action,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);

        $file = $request->file('file');
        $user = $request->user();
        abort_unless($file instanceof UploadedFile, 422);
        abort_unless($user instanceof User, 401);

        $attachment = $action->store($workspace, $recordType, $recordPublicId, $file, $user);

        // No JSON shape of its own (#454): a program uses attachments.upload.
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
        if ($attachment->record_type === 'expense') {
            Gate::authorize('manage', $workspace);
            $recordResolver->resolve($workspace, 'expense', $attachment->record_public_id);

            return $storage->download($attachment);
        }

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
        AttachmentAction $action,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);

        $action->delete($workspace, $clientAttachment);

        return redirect()->back()->with('status', 'Attachment queued for deletion.');
    }
}
