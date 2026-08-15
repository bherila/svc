<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Requests\Engagement\SignAgreementRequest;
use App\Http\Requests\Engagement\StoreAgreementRequest;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\EngagementAuthorization;
use App\Services\Engagement\EngagementException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AgreementController extends EngagementController
{
    public function store(
        StoreAgreementRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        EngagementAuthorization $authorization,
        AgreementWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($authorization->canManage($user, $workspace), 403);
        abort_unless($clientCompany->workspace_id === $workspace->id, 404);

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

    public function activate(
        Workspace $workspace,
        ClientAgreement $clientAgreement,
        EngagementAuthorization $authorization,
        AgreementWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $request = request();
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($authorization->canManage($user, $workspace), 403);
        abort_unless($clientAgreement->workspace_id === $workspace->id, 404);

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
        EngagementAuthorization $authorization,
        AgreementWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($authorization->canManage($user, $workspace), 403);
        abort_unless($clientAgreement->workspace_id === $workspace->id, 404);

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
