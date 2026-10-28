<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\Workspace;
use App\Services\AgentApi\AgentExpenseReceiptService;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentUploadPolicy;
use App\Support\AgentApi\FirstPartySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AgentExpenseReceiptController extends Controller
{
    public function __construct(private readonly AgentExpenseReceiptService $receipts, private readonly AgentMutationContextFactory $contexts) {}

    public function index(Request $request, Workspace $workspace, string $expense): JsonResponse
    {
        return response()->json($this->receipts->listing($this->actor($request), $workspace, $expense, AttachmentPageQuery::fromInput($request->query()), $this->origin($request)));
    }

    public function download(Request $request, Workspace $workspace, string $expense, string $receipt): JsonResponse
    {
        return response()->json($this->receipts->downloadUrl($this->actor($request), $workspace, $expense, $receipt, $this->origin($request)));
    }

    public function uploadUrl(Request $request, Workspace $workspace, string $expense): JsonResponse
    {
        AttachmentUploadPolicy::validateLegacyPayload($request->all());

        return response()->json($this->receipts->uploadUrl($this->actor($request), $workspace, $expense, $this->origin($request)));
    }

    public function content(Request $request, Workspace $workspace, string $expense, string $receipt): StreamedResponse
    {
        return $this->receipts->content($this->actor($request), $workspace, $expense, $receipt, $this->origin($request));
    }

    public function store(Request $request, Workspace $workspace, string $expense): JsonResponse
    {
        // Direct REST uploads need no URL preparation. A prepared URL expires,
        // and its signature must not be stripped or altered by the uploader.
        if ($request->query('signature') !== null || $request->query('expires') !== null) {
            abort_unless($request->hasValidSignature(absolute: false), 403);
        }
        AttachmentUploadPolicy::validateLegacyPayload($request->all());
        $context = $this->contexts->fromAuthenticatedClient($request);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422, 'A receipt file is required.');

        return response()->json($this->receipts->upload($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $expense,
            $request->request->all(), $file, $this->origin($request)), 201);
    }

    public function destroy(Request $request, Workspace $workspace, string $expense, string $receipt): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);

        return response()->json($this->receipts->delete($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey,
            $expense, $receipt, $request->all(), $this->origin($request)));
    }

    private function origin(Request $request): AttachmentMutationOrigin
    {
        return FirstPartySession::is($request) ? AttachmentMutationOrigin::Native : AttachmentMutationOrigin::Agent;
    }

    private function actor(Request $request): AgentPrincipal
    {
        $actor = $request->user();
        abort_unless($actor instanceof AgentPrincipal, 401);

        return $actor;
    }
}
