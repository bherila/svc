<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\Authorization\AgentTokenScopes;
use App\Services\Square\SquareCoveredPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentSquareCoveredPaymentController extends Controller
{
    public function review(Request $request, Workspace $workspace, string $invoice, string $squareInvoice, SquareCoveredPaymentService $payments, AgentTokenScopes $scopes): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);

        return response()->json($payments->review($actor, $workspace, $invoice, $squareInvoice, $scopes->allows($request, 'payments:record')));
    }

    public function record(Request $request, Workspace $workspace, string $invoice, string $squareInvoice, string $payment,
        AgentMutationContextFactory $contexts, SquareCoveredPaymentService $payments): JsonResponse
    {
        $context = $contexts->from($request);
        $recorded = $payments->record($context->user, $workspace, $invoice, $squareInvoice, $payment,
            $context->oauthClientId, $context->idempotencyKey, $request->all());

        return response()->json(['data' => ['payment_id' => $recorded->public_id, 'invoice_id' => $invoice]]);
    }
}
