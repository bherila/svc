<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentSharingNativeView;
use App\Services\Files\AttachmentSharingTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentSharingController extends Controller
{
    public function __construct(private readonly AttachmentSharingTransport $files, private readonly AttachmentSharingNativeView $view,
        private readonly AttachmentReader $reader) {}

    public function legacyParentPage(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): Response
    {
        $panel = $this->legacyPanel($request, $workspace, $recordType, $recordPublicId);

        return Inertia::render('files/parent', ['attachment_panel' => $panel]);
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

    public function portalLegacyPage(Request $request, ClientCompany $clientCompany, string $recordType, string $recordPublicId): Response
    {
        return Inertia::render('files/parent', ['attachment_panel' => $this->view->portalLegacy($this->actor($request), $clientCompany, $recordType, $recordPublicId)]);
    }

    public function portalLegacyIndex(Request $request, ClientCompany $clientCompany, string $recordType, string $recordPublicId): JsonResponse
    {
        return response()->json($this->view->portalLegacy($this->actor($request), $clientCompany, $recordType, $recordPublicId,
            AttachmentPageQuery::fromInput($request->query())));
    }

    public function portalLegacyDownload(Request $request, ClientCompany $clientCompany, string $recordType, string $recordPublicId, string $attachment): StreamedResponse
    {
        $actor = $this->actor($request);
        $this->view->portalParent($actor, $clientCompany, $recordType, $recordPublicId);

        return $this->files->download($actor, $clientCompany->workspace, new AttachmentParentReference($recordType, $recordPublicId), $attachment,
            $this->reader->nativePortalAudience($actor, $clientCompany->workspace));
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

    private function reference(ClientCompany $clientCompany, string $type, string $id): AttachmentParentReference
    {
        abort_unless(in_array($type, ['topic', 'comment'], true), 404);

        return new AttachmentParentReference($type, $id, $clientCompany->public_id);
    }
}
