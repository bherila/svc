<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Requests\Engagement\AcceptProposalRequest;
use App\Http\Requests\Engagement\StoreProposalRequest;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\Workspace;
use App\Services\Engagement\EngagementAuthorization;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\ProposalWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ProposalController extends EngagementController
{
    public function store(
        StoreProposalRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        EngagementAuthorization $authorization,
        ProposalWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($authorization->canManage($user, $workspace), 403);
        abort_unless($clientCompany->workspace_id === $workspace->id, 404);

        try {
            $proposal = $workflow->create($workspace, $clientCompany, null, $user, $request->validated());

            return $this->respond(
                $request,
                ['data' => $proposal],
                'svc.engagement.proposals.store',
                'Proposal created.',
                201,
            );
        } catch (EngagementException $exception) {
            return $this->reportFailure($request, $exception);
        }
    }

    public function send(
        Workspace $workspace,
        ClientProposal $clientProposal,
        EngagementAuthorization $authorization,
        ProposalWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $request = request();
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($authorization->canManage($user, $workspace), 403);
        abort_unless($clientProposal->workspace_id === $workspace->id, 404);

        try {
            $proposal = $workflow->send($clientProposal);

            return $this->respond(
                $request,
                ['data' => $proposal],
                'svc.engagement.proposals.store',
                'Proposal sent.',
            );
        } catch (EngagementException $exception) {
            return $this->reportFailure($request, $exception);
        }
    }

    public function accept(
        AcceptProposalRequest $request,
        ClientCompany $clientCompany,
        ClientProposal $clientProposal,
        EngagementAuthorization $authorization,
        ProposalWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $workspace = Workspace::query()->findOrFail($clientProposal->workspace_id);
        abort_unless(
            $clientProposal->client_company_id === $clientCompany->id
                && $clientProposal->is_visible_to_client,
            404,
        );
        abort_unless($authorization->canActAsClient($user, $workspace, $clientCompany), 403);

        try {
            $proposal = $workflow->accept(
                $clientProposal,
                $user,
                $request->string('signer_name')->toString(),
                $request->validated('signer_title'),
            );

            return $this->respond(
                $request,
                ['data' => $proposal],
                'svc.engagement.proposals.store',
                'Proposal accepted.',
            );
        } catch (EngagementException $exception) {
            return $this->reportFailure($request, $exception);
        }
    }
}
