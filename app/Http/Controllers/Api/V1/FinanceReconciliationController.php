<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FinancePayoutResource;
use App\Http\Resources\Api\V1\InvoicePaymentResource;
use App\Http\Resources\Api\V1\PaymentReconciliationResource;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\FinanceDoor;
use App\Support\Finance\FinancePaymentQuery;
use App\Support\Finance\FinancePayoutQuery;
use App\Support\Finance\FinanceProcessorMovementQuery;
use App\Support\Finance\ReconciliationAllocation;
use App\Support\Finance\ReconciliationTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Passport\AccessToken;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * The finance reconciliation routes (#457): a thin REST door over
 * {@see FinanceReconciliationOperations}, which the MCP tools reach through
 * the internal transport and the console commands call directly.
 *
 * Bound from the operation registry, which gates the scopes: an OAuth
 * caller's `finance:read` / `finance:reconcile`, or the Sanctum finance
 * token's `finance.read` / `finance.reconcile` abilities, read as those
 * scopes on these routes alone.
 */
final class FinanceReconciliationController extends Controller
{
    public function index(Request $request, Workspace $workspace, FinanceReconciliationOperations $operations): AnonymousResourceCollection|JsonResponse
    {
        $caller = $this->caller($request);
        $query = FinancePaymentQuery::fromInput($request->query());
        if ($query->changes()) {
            return response()->json($operations->changes($caller, $workspace, $query));
        }

        return InvoicePaymentResource::collection($operations->list($caller, $workspace, $query));
    }

    public function payouts(Request $request, Workspace $workspace, FinanceReconciliationOperations $operations): AnonymousResourceCollection|JsonResponse
    {
        $caller = $this->caller($request);
        $query = FinancePayoutQuery::fromInput($request->query());
        if ($query->changes()) {
            return response()->json($operations->payoutChanges($caller, $workspace, $query));
        }

        return FinancePayoutResource::collection($operations->payouts($caller, $workspace, $query));
    }

    public function movements(Request $request, Workspace $workspace, FinanceReconciliationOperations $operations): JsonResponse
    {
        return response()->json($operations->movementChanges($this->caller($request), $workspace, FinanceProcessorMovementQuery::fromInput($request->query())));
    }

    public function upsert(Request $request, Workspace $workspace, string $payment, string $external_system, string $transaction, FinanceReconciliationOperations $operations): PaymentReconciliationResource
    {
        $caller = $this->caller($request);

        // 201 when the allocation was created, 200 when it was updated.
        return new PaymentReconciliationResource($operations->upsert(
            $caller,
            $workspace,
            ReconciliationTarget::of($payment, $external_system, $transaction),
            // Required through OAuth (and so MCP): the state wanted now, never left to a default.
            ReconciliationAllocation::fromInput($request->all(), statesActivity: $caller->door === FinanceDoor::OAuth),
        ));
    }

    public function destroy(Request $request, Workspace $workspace, string $payment, string $external_system, string $transaction, FinanceReconciliationOperations $operations): PaymentReconciliationResource
    {
        $caller = $this->caller($request);

        return new PaymentReconciliationResource($operations->deactivate(
            $caller,
            $workspace,
            ReconciliationTarget::of($payment, $external_system, $transaction),
        ));
    }

    private function caller(Request $request): FinanceCaller
    {
        $user = $request->user();
        if ($user instanceof AgentPrincipal) {
            $token = $user->token();
            $clientId = $token instanceof AccessToken ? $token->oauth_client_id : null;
            abort_unless(is_string($clientId) && $clientId !== '', 401);

            return FinanceCaller::oauth(User::query()->findOrFail($user->id), $clientId);
        }
        $token = $user instanceof User ? $user->currentAccessToken() : null;
        abort_unless($user instanceof User && $token instanceof HasAbilities, 401);

        return FinanceCaller::financeToken($user, $token);
    }
}
