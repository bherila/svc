<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpsertPaymentReconciliationRequest;
use App\Http\Resources\Api\V1\PaymentReconciliationResource;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\PaymentReconciliationService;
use App\Services\WorkspaceAuthorization;
use Illuminate\Support\Facades\Gate;

class PaymentReconciliationController extends Controller
{
    public function __construct(
        private readonly PaymentReconciliationService $reconciliations,
        private readonly WorkspaceAuthorization $workspaceAuthorization,
    ) {}

    public function upsert(
        UpsertPaymentReconciliationRequest $request,
        Workspace $workspace,
        ClientInvoicePayment $clientInvoicePayment,
        string $externalSystemSlug,
        string $externalTransactionUuid,
    ): PaymentReconciliationResource {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientInvoicePayment);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $reconciliation = $this->reconciliations->upsert($workspace, $clientInvoicePayment, $user, [
            ...$request->validated(),
            'external_system_slug' => $externalSystemSlug,
            'external_transaction_uuid' => $externalTransactionUuid,
        ]);

        return new PaymentReconciliationResource($reconciliation);
    }

    public function destroy(
        Workspace $workspace,
        ClientInvoicePayment $clientInvoicePayment,
        string $externalSystemSlug,
        string $externalTransactionUuid,
    ): PaymentReconciliationResource {
        Gate::authorize('manage', $workspace);
        $this->workspaceAuthorization->assertOwnedBy($workspace, $clientInvoicePayment);

        return new PaymentReconciliationResource($this->reconciliations->deactivate(
            $workspace,
            $clientInvoicePayment,
            $externalSystemSlug,
            $externalTransactionUuid,
        ));
    }
}
