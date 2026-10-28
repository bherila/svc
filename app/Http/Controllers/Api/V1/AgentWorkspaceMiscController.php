<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentAttachmentService;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentWorkspaceCreationAction;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\AgentTokenScopes;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentUploadPolicy;
use App\Services\Search\WorkspaceSearch;
use App\Support\AgentApi\FirstPartySession;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchResultKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AgentWorkspaceMiscController extends Controller
{
    public function __construct(private readonly AgentMutationContextFactory $contexts, private readonly AgentAttachmentService $files) {}

    public function create(Request $request, AgentWorkspaceCreationAction $action): JsonResponse
    {
        $context = $this->contexts->from($request);

        return response()->json($action->run($context->user, $context->oauthClientId, $context->idempotencyKey, $request->all()), 201);
    }

    public function search(Request $request, Workspace $workspace, WorkspaceSearch $search, AgentAccess $access, AgentTokenScopes $scopes): JsonResponse
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);
        abort_unless($access->canViewWorkspace($principal, $workspace), 404);
        $data = $request->validate(['q' => ['required', 'string', 'max:200']]);
        $kinds = array_values(array_filter(SearchResultKind::cases(), fn (SearchResultKind $kind): bool => match ($kind) {
            SearchResultKind::Client => $scopes->allows($request, 'clients:read'),
            SearchResultKind::Project => $scopes->allows($request, 'projects:read'),
            SearchResultKind::Invoice => $scopes->allows($request, 'billing:read'),
            SearchResultKind::Task => $scopes->allows($request, 'tasks:read'),
            SearchResultKind::Note, SearchResultKind::Discussion, SearchResultKind::Comment => $scopes->allows($request, 'collaboration:read'),
            SearchResultKind::History => false,
        }));
        $results = $search->forWorkspace(User::query()->findOrFail($principal->id), $workspace, $data['q'], $kinds, $access->isWorkspaceClient($principal, $workspace) ? CollaborationAudience::Portal : CollaborationAudience::Internal);

        return response()->json(['data' => array_map(fn (SearchResult $result): array => $result->toArray(), $results)]);
    }

    public function index(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): JsonResponse
    {
        return response()->json($this->files->listing($this->principal($request), $workspace, $recordType, $recordPublicId, AttachmentPageQuery::fromInput($request->query()), $this->origin($request)));
    }

    public function show(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        return response()->json($this->files->metadata($this->principal($request), $workspace, $attachment, $this->origin($request)));
    }

    public function downloadUrl(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        return response()->json($this->files->downloadUrl($this->principal($request), $workspace, $attachment, $this->origin($request)));
    }

    public function download(Request $request, Workspace $workspace, string $attachment): StreamedResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 403);

        return $this->files->download($this->principal($request), $workspace, $attachment, $this->origin($request));
    }

    public function uploadUrl(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): JsonResponse
    {
        AttachmentUploadPolicy::validateLegacyPayload($request->all());

        return response()->json($this->files->uploadUrl($this->principal($request), $workspace, $recordType, $recordPublicId, $this->origin($request)));
    }

    public function upload(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): JsonResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 403);
        $context = $this->contexts->from($request);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json($this->files->upload($context->user, $workspace, $context->oauthClientId,
            $context->idempotencyKey, $recordType, $recordPublicId, $file, $request->all(), $this->origin($request)), 201);
    }

    public function delete(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        $context = $this->contexts->from($request);

        return response()->json($this->files->delete($context->user, $workspace, $context->oauthClientId,
            $context->idempotencyKey, $attachment, $request->all(), $this->origin($request)), 202);
    }

    private function origin(Request $request): AttachmentMutationOrigin
    {
        return FirstPartySession::is($request) ? AttachmentMutationOrigin::Native : AttachmentMutationOrigin::Agent;
    }

    private function principal(Request $request): User|AgentPrincipal
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);

        return $principal;
    }
}
