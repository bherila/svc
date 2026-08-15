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
use Illuminate\Support\Facades\Validator;

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
            'external_system_slug' => $this->validatedSystemSlug($externalSystemSlug),
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
            $this->validatedSystemSlug($externalSystemSlug),
            $externalTransactionUuid,
        ));
    }

    private function validatedSystemSlug(string $value): string
    {
        $validated = Validator::make(
            ['external_system_slug' => $value],
            ['external_system_slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/']],
        )->validate();

        return $validated['external_system_slug'];
    }
}
