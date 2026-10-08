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
use App\Services\Search\WorkspaceSearch;
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
        $kinds = array_values(array_filter(SearchResultKind::cases(), fn (SearchResultKind $kind): bool => $scopes->allows($request, match ($kind) {
            SearchResultKind::Client => 'clients:read', SearchResultKind::Project => 'projects:read',
            SearchResultKind::Invoice => 'billing:read', SearchResultKind::Task => 'tasks:read',
        })));
        $results = $search->forWorkspace(User::query()->findOrFail($principal->id), $workspace, $data['q'], $kinds);

        return response()->json(['data' => array_map(fn (SearchResult $result): array => $result->toArray(), $results)]);
    }

    public function index(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): JsonResponse
    {
        return response()->json($this->files->listing($this->principal($request), $workspace, $recordType, $recordPublicId));
    }

    public function show(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        return response()->json($this->files->metadata($this->principal($request), $workspace, $attachment));
    }

    public function downloadUrl(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        return response()->json($this->files->downloadUrl($this->principal($request), $workspace, $attachment));
    }

    public function download(Request $request, Workspace $workspace, string $attachment): StreamedResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 403);

        return $this->files->download($this->principal($request), $workspace, $attachment);
    }

    public function uploadUrl(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): JsonResponse
    {
        return response()->json($this->files->uploadUrl($this->principal($request), $workspace, $recordType, $recordPublicId));
    }

    public function upload(Request $request, Workspace $workspace, string $recordType, string $recordPublicId): JsonResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 403);
        $context = $this->contexts->from($request);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json($this->files->upload($context->user, $workspace, $context->oauthClientId,
            $context->idempotencyKey, $recordType, $recordPublicId, $file, $request->all()), 201);
    }

    public function delete(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        $context = $this->contexts->from($request);

        return response()->json($this->files->delete($context->user, $workspace, $context->oauthClientId,
            $context->idempotencyKey, $attachment, $request->all()), 202);
    }

    private function principal(Request $request): User|AgentPrincipal
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);

        return $principal;
    }
}
