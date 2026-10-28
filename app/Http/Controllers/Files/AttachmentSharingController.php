<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentSharingNativeView;
use App\Services\Files\AttachmentSharingTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentSharingController extends Controller
{
    public function __construct(private readonly AttachmentSharingTransport $files, private readonly AttachmentSharingNativeView $view,
        private readonly AttachmentReader $reader) {}

    public function legacyVisibilityUpdate(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        $actor = $this->actor($request);
        $reference = $this->files->legacyReference($actor, $workspace, $attachment, AttachmentAudience::Internal);

        return response()->json($this->files->updateVisibility($actor, $workspace, $reference, $attachment,
            $request->all(), 'svc-native:attachments', $this->key($request), AttachmentMutationOrigin::Native));
    }

    public function legacyParentPage(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): Response
    {
        $panel = $this->legacyPanel($request, $workspace, $recordType, $recordPublicId);

        return Inertia::render('files/parent', ['attachment_panel' => $panel, 'legacy_upload_href' => $panel['legacy_upload_href']]);
    }

    public function legacyParentIndex(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): JsonResponse
    {
        return response()->json($this->legacyPanel($request, $workspace, $recordType, $recordPublicId));
    }

    public function collaborationPage(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $recordType, string $recordPublicId): Response
    {
        return Inertia::render('files/parent', ['attachment_panel' => $this->view->collaboration($this->actor($request), $workspace, $this->reference($clientCompany, $recordType, $recordPublicId),
            AttachmentAudience::Internal, AttachmentPageQuery::fromInput($request->query()))]);
    }

    public function collaborationIndex(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $recordType, string $recordPublicId): JsonResponse
    {
        return response()->json($this->view->collaboration($this->actor($request), $workspace, $this->reference($clientCompany, $recordType, $recordPublicId),
            AttachmentAudience::Internal, AttachmentPageQuery::fromInput($request->query())));
    }

    public function collaborationUpload(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $recordType, string $recordPublicId): JsonResponse
    {
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json($this->files->upload($this->actor($request), $workspace, $this->reference($clientCompany, $recordType, $recordPublicId),
            $file, $request->all(), 'svc-native:attachments', $this->key($request), AttachmentMutationOrigin::Native));
    }

    public function collaborationVisibilityUpdate(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $recordType, string $recordPublicId, string $attachment): JsonResponse
    {
        return response()->json($this->files->updateVisibility($this->actor($request), $workspace, $this->reference($clientCompany, $recordType, $recordPublicId),
            $attachment, $request->all(), 'svc-native:attachments', $this->key($request), AttachmentMutationOrigin::Native));
    }

    public function collaborationDelete(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $recordType, string $recordPublicId, string $attachment): JsonResponse
    {
        return response()->json($this->files->delete($this->actor($request), $workspace, $this->reference($clientCompany, $recordType, $recordPublicId),
            $attachment, $request->all(), 'svc-native:attachments', $this->key($request), AttachmentMutationOrigin::Native));
    }

    public function portalCollaborationPage(Request $request, ClientCompany $clientCompany, string $recordType, string $recordPublicId): Response
    {
        $actor = $this->actor($request);
        $workspace = $clientCompany->workspace;

        return Inertia::render('files/parent', ['attachment_panel' => $this->view->collaboration($actor, $workspace,
            $this->reference($clientCompany, $recordType, $recordPublicId), $this->reader->nativePortalAudience($actor, $workspace),
            AttachmentPageQuery::fromInput($request->query()))]);
    }

    public function portalCollaborationIndex(Request $request, ClientCompany $clientCompany, string $recordType, string $recordPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        $workspace = $clientCompany->workspace;

        return response()->json($this->view->collaboration($actor, $workspace,
            $this->reference($clientCompany, $recordType, $recordPublicId), $this->reader->nativePortalAudience($actor, $workspace),
            AttachmentPageQuery::fromInput($request->query())));
    }

    public function portalCollaborationDownload(Request $request, ClientCompany $clientCompany, string $recordType, string $recordPublicId, string $attachment): StreamedResponse
    {
        $actor = $this->actor($request);
        $workspace = $clientCompany->workspace;

        return $this->files->download($actor, $workspace, $this->reference($clientCompany, $recordType, $recordPublicId),
            $attachment, $this->reader->nativePortalAudience($actor, $workspace));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @return array<string,mixed> */
    private function legacyPanel(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): array
    {
        abort_unless(in_array($recordType, ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'], true), 404);
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->view->legacy($this->actor($request), $workspace, new AttachmentParentReference($recordType, $recordPublicId), (int) ($input['page'] ?? 1));
    }

    private function key(Request $request): string
    {
        return $request->header('Idempotency-Key') ?? '';
    }

    private function reference(ClientCompany $clientCompany, string $type, string $id): AttachmentParentReference
    {
        abort_unless(in_array($type, ['topic', 'comment'], true), 404);

        return new AttachmentParentReference($type, $id, $clientCompany->public_id);
    }
}
