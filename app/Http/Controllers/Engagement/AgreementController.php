<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Requests\Engagement\SignAgreementRequest;
use App\Http\Requests\Engagement\StoreAgreementRequest;
use App\Http\Requests\Engagement\UpdateAgreementRequest;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\EngagementException;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class AgreementController extends EngagementController
{
    public function store(
        StoreAgreementRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $workspaceAuthorization,
        AgreementWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        Gate::forUser($user)->authorize('manage', $workspace);
        $workspaceAuthorization->assertOwnedBy($workspace, $clientCompany);

        try {
            $agreement = $workflow->create($workspace, $clientCompany, null, null, $request->validated());

            return $this->respond(
                $request,
                ['data' => $agreement],
                'svc.engagement.agreements.store',
                'Agreement created.',
                201,
            );
        } catch (EngagementException $exception) {
            return $this->reportFailure($request, $exception);
        }
    }

    /**
     * Correct an agreement's terms.
     *
     * The same endpoint serves the pencil beside the title and the full terms
     * form, because they are the same act on the same record and differ only in
     * how many fields they send. The request marks every field `sometimes`, so
     * what is not sent is not written.
     *
     * `manage`, not `view`: this rewrites what the client is owed and what the
     * billing engine will charge for it.
     */
    public function update(
        UpdateAgreementRequest $request,
        Workspace $workspace,
        ClientAgreement $clientAgreement,
        WorkspaceAuthorization $workspaceAuthorization,
        AgreementWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        Gate::forUser($user)->authorize('manage', $workspace);
        $workspaceAuthorization->assertOwnedBy($workspace, $clientAgreement);

        try {
            return $this->respond(
                $request,
                ['data' => $workflow->update($workspace, $clientAgreement, $request->validated())],
                'svc.engagement.agreements.store',
                'Agreement updated.',
            );
        } catch (EngagementException $exception) {
            return $this->reportFailure($request, $exception);
        }
    }

    public function activate(
        Workspace $workspace,
        ClientAgreement $clientAgreement,
        WorkspaceAuthorization $workspaceAuthorization,
        AgreementWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $request = request();
        $user = $request->user();
        abort_if($user === null, 401);
        Gate::forUser($user)->authorize('manage', $workspace);
        $workspaceAuthorization->assertOwnedBy($workspace, $clientAgreement);

        try {
            return $this->respond(
                $request,
                ['data' => $workflow->activate($clientAgreement)],
                'svc.engagement.agreements.store',
                'Agreement activated.',
            );
        } catch (EngagementException $exception) {
            return $this->reportFailure($request, $exception);
        }
    }

    public function sign(
        SignAgreementRequest $request,
        Workspace $workspace,
        ClientAgreement $clientAgreement,
        WorkspaceAuthorization $workspaceAuthorization,
        AgreementWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        Gate::forUser($user)->authorize('manage', $workspace);
        $workspaceAuthorization->assertOwnedBy($workspace, $clientAgreement);

        try {
            return $this->respond(
                $request,
                ['data' => $workflow->sign($clientAgreement, $user, $request->string('signer_name')->toString(), $request->validated('signer_title'))],
                'svc.engagement.agreements.store',
                'Agreement signed.',
            );
        } catch (EngagementException $exception) {
            return $this->reportFailure($request, $exception);
        }
    }
}
