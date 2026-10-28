<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentRecordResolver;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function download(
        Request $request,
        Workspace $workspace,
        string $clientAttachment,
        AttachmentStorageService $storage,
        AttachmentRecordResolver $recordResolver,
        AttachmentReader $reader,
    ): StreamedResponse {
        $attachment = $storage->findForWorkspace($workspace, $clientAttachment);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $recordResolver->resolve($workspace, $attachment->record_type, $attachment->record_public_id);
        if ($attachment->record_type === 'expense') {
            Gate::authorize('manage', $workspace);
        }

        abort_unless(
            $reader->canView($user, $workspace, $attachment, AttachmentAudience::Native),
            403,
        );

        return $storage->download($attachment);
    }
}
