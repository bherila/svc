<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\Workspace;
use App\Services\AgentApi\AgentExpenseReceiptService;
use App\Services\AgentApi\AgentMutationContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AgentExpenseReceiptController extends Controller
{
    public function __construct(private readonly AgentExpenseReceiptService $receipts, private readonly AgentMutationContextFactory $contexts) {}

    public function index(Request $request, Workspace $workspace, string $expense): JsonResponse
    {
        return response()->json($this->receipts->listing($this->actor($request), $workspace, $expense));
    }

    public function download(Request $request, Workspace $workspace, string $expense, string $receipt): JsonResponse
    {
        return response()->json($this->receipts->downloadUrl($this->actor($request), $workspace, $expense, $receipt));
    }

    public function uploadUrl(Request $request, Workspace $workspace, string $expense): JsonResponse
    {
        return response()->json($this->receipts->uploadUrl($this->actor($request), $workspace, $expense));
    }

    public function content(Request $request, Workspace $workspace, string $expense, string $receipt): StreamedResponse
    {
        return $this->receipts->content($this->actor($request), $workspace, $expense, $receipt);
    }

    public function store(Request $request, Workspace $workspace, string $expense): JsonResponse
    {
        // Direct REST uploads need no URL preparation. A prepared URL expires,
        // and its signature must not be stripped or altered by the uploader.
        if ($request->query('signature') !== null || $request->query('expires') !== null) {
            abort_unless($request->hasValidSignature(absolute: false), 403);
        }
        $context = $this->contexts->fromAuthenticatedClient($request);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422, 'A receipt file is required.');

        return response()->json($this->receipts->upload($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $expense,
            $request->request->all(), $file), 201);
    }

    private function actor(Request $request): AgentPrincipal
    {
        $actor = $request->user();
        abort_unless($actor instanceof AgentPrincipal, 401);

        return $actor;
    }
}
