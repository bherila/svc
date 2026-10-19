<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\InvoicePaymentResource;
use App\Http\Resources\Api\V1\PaymentReconciliationResource;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\FinancePaymentQuery;
use App\Support\Finance\ReconciliationAllocation;
use App\Support\Finance\ReconciliationTarget;
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
    public function index(Request $request, Workspace $workspace, FinanceReconciliationOperations $operations): AnonymousResourceCollection
    {
        $caller = $this->caller($request);

        return InvoicePaymentResource::collection($operations->list($caller, $workspace, FinancePaymentQuery::fromInput($request->query())));
    }

    public function upsert(Request $request, Workspace $workspace, string $payment, string $external_system, string $transaction, FinanceReconciliationOperations $operations): PaymentReconciliationResource
    {
        $caller = $this->caller($request);

        // 201 when the allocation was created, 200 when it was updated.
        return new PaymentReconciliationResource($operations->upsert(
            $caller,
            $workspace,
            ReconciliationTarget::of($payment, $external_system, $transaction),
            ReconciliationAllocation::fromInput($request->all()),
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
