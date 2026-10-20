<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\DeferredBuyDownOperations;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeferredBuyDownController extends Controller
{
    public function __construct(private readonly DeferredBuyDownOperations $operations) {}

    public function preview(Request $request, Workspace $workspace, string $agreement): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);

        return response()->json(['data' => $this->operations->preview($actor, $workspace, $agreement, $request->query())]);
    }

    public function create(Request $request, Workspace $workspace, string $agreement, AgentMutationContextFactory $contexts, AgentInvoicePresenter $presenter): JsonResponse
    {
        $context = $contexts->from($request);
        $invoice = $this->operations->create($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $agreement, $request->all());

        return response()->json(['data' => $presenter->mutation($workspace, $invoice)], 201);
    }
}
