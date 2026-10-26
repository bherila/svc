<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentSharingNativeView;
use App\Services\Files\AttachmentSharingTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

final class AttachmentSharingController extends Controller
{
    public function __construct(private readonly AttachmentSharingTransport $files, private readonly AttachmentSharingNativeView $view) {}

    public function legacyVisibilityUpdate(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        $actor = $this->actor($request);
        $reference = $this->files->legacyReference($actor, $workspace, $attachment, AttachmentAudience::Internal);

        return response()->json($this->files->updateVisibility($actor, $workspace, $reference, $attachment,
            $request->all(), 'svc-native:attachments', $this->key($request), AttachmentMutationOrigin::Native));
    }

    public function collaborationPage(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId): Response
    {
        return Inertia::render('files/parent', ['workspace' => $workspace,
            'attachment_panel' => $this->view->collaboration($this->actor($request), $workspace, $this->reference($client, $recordType, $recordPublicId),
                AttachmentAudience::Internal, AttachmentPageQuery::fromInput($request->query()))]);
    }

    public function collaborationIndex(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId): JsonResponse
    {
        return response()->json($this->view->collaboration($this->actor($request), $workspace, $this->reference($client, $recordType, $recordPublicId),
            AttachmentAudience::Internal, AttachmentPageQuery::fromInput($request->query())));
    }

    public function collaborationUpload(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId): JsonResponse
    {
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json($this->files->upload($this->actor($request), $workspace, $this->reference($client, $recordType, $recordPublicId),
            $file, $request->all(), 'svc-native:attachments', $this->key($request), AttachmentMutationOrigin::Native));
    }

    public function collaborationVisibilityUpdate(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId, string $attachment): JsonResponse
    {
        return response()->json($this->files->updateVisibility($this->actor($request), $workspace, $this->reference($client, $recordType, $recordPublicId),
            $attachment, $request->all(), 'svc-native:attachments', $this->key($request), AttachmentMutationOrigin::Native));
    }

    public function collaborationDelete(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId, string $attachment): JsonResponse
    {
        return response()->json($this->files->delete($this->actor($request), $workspace, $this->reference($client, $recordType, $recordPublicId),
            $attachment, $request->all(), 'svc-native:attachments', $this->key($request), AttachmentMutationOrigin::Native));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function key(Request $request): string
    {
        return $request->header('Idempotency-Key') ?? '';
    }

    private function reference(string $client, string $type, string $id): AttachmentParentReference
    {
        abort_unless(in_array($type, ['topic', 'comment'], true), 404);

        return new AttachmentParentReference($type, $id, $client);
    }
}
