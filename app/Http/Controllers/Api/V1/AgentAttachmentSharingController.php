<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentSharingTransport;
use App\Support\AgentApi\FirstPartySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AgentAttachmentSharingController extends Controller
{
    public function __construct(private readonly AttachmentSharingTransport $files, private readonly AttachmentReader $reader,
        private readonly AgentMutationContextFactory $contexts) {}

    public function legacyVisibilityGet(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        $actor = $this->actor($request);
        $reference = $this->files->legacyReference($actor, $workspace, $attachment, $this->audience($request, $actor, $workspace));

        return response()->json($this->files->visibility($actor, $workspace, $reference, $attachment, $this->audience($request, $actor, $workspace)));
    }

    public function legacyVisibilityUpdate(Request $request, Workspace $workspace, string $attachment): JsonResponse
    {
        $context = $this->contexts->from($request);
        $reference = $this->files->legacyReference($context->user, $workspace, $attachment, $this->audience($request, $context->user, $workspace));

        return response()->json($this->files->updateVisibility($context->user, $workspace, $reference, $attachment,
            $request->all(), $context->oauthClientId, $context->idempotencyKey, $this->origin($request)));
    }

    public function index(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json($this->files->listing($actor, $workspace, $this->reference($client, $recordType, $recordPublicId),
            $this->audience($request, $actor, $workspace), AttachmentPageQuery::fromInput($request->query())));
    }

    public function downloadUrl(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId, string $attachment): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless($this->body($request) === [], 422, 'Download URL preparation has no request fields.');

        return response()->json($this->files->downloadUrl($actor, $workspace, $this->reference($client, $recordType, $recordPublicId),
            $attachment, $this->audience($request, $actor, $workspace)));
    }

    public function download(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId, string $attachment): StreamedResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 403);
        $actor = $this->actor($request);

        return $this->files->download($actor, $workspace, $this->reference($client, $recordType, $recordPublicId),
            $attachment, $this->audience($request, $actor, $workspace));
    }

    public function uploadUrl(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless($this->body($request) === [], 422, 'Upload URL preparation has no request fields.');

        return response()->json($this->files->uploadUrl(User::query()->findOrFail($actor->id), $workspace,
            $this->reference($client, $recordType, $recordPublicId), $this->origin($request)));
    }

    public function upload(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId): JsonResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 403);
        $context = $this->contexts->from($request);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json($this->files->upload($context->user, $workspace, $this->reference($client, $recordType, $recordPublicId),
            $file, $this->body($request), $context->oauthClientId, $context->idempotencyKey, $this->origin($request)));
    }

    public function visibilityUpdate(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId, string $attachment): JsonResponse
    {
        $context = $this->contexts->from($request);

        return response()->json($this->files->updateVisibility($context->user, $workspace, $this->reference($client, $recordType, $recordPublicId),
            $attachment, $request->all(), $context->oauthClientId, $context->idempotencyKey, $this->origin($request)));
    }

    public function delete(Request $request, Workspace $workspace, string $client, string $recordType, string $recordPublicId, string $attachment): JsonResponse
    {
        $context = $this->contexts->from($request);

        return response()->json($this->files->delete($context->user, $workspace, $this->reference($client, $recordType, $recordPublicId),
            $attachment, $request->all(), $context->oauthClientId, $context->idempotencyKey, $this->origin($request)));
    }

    private function origin(Request $request): AttachmentMutationOrigin
    {
        return FirstPartySession::is($request) ? AttachmentMutationOrigin::Native : AttachmentMutationOrigin::Agent;
    }

    private function audience(Request $request, User|AgentPrincipal $actor, Workspace $workspace): AttachmentAudience
    {
        return FirstPartySession::is($request) ? AttachmentAudience::Internal : $this->reader->agentAudience($actor, $workspace);
    }

    private function actor(Request $request): AgentPrincipal
    {
        $actor = $request->user();
        abort_unless($actor instanceof AgentPrincipal, 401);

        return $actor;
    }

    /** Signed URL query fields are transport authentication, never mutation body fields.
     * @return array<string,mixed> */
    private function body(Request $request): array
    {
        return [...($request->isJson() ? $request->json()->all() : $request->request->all()), ...$request->allFiles()];
    }

    private function reference(string $client, string $type, string $id): AttachmentParentReference
    {
        abort_unless(in_array($type, ['topic', 'comment'], true), 404);

        return new AttachmentParentReference($type, $id, $client);
    }
}
