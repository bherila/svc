<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Requests\Engagement\AcceptProposalRequest;
use App\Http\Requests\Engagement\StoreProposalRequest;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\EngagementAuthorization;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\ProposalMutationAction;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProposalController extends EngagementController
{
    public function store(
        StoreProposalRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $workspaceAuthorization,
        ProposalMutationAction $workflow,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('manage', $workspace);
        $workspaceAuthorization->assertOwnedBy($workspace, $clientCompany);

        try {
            $workflow->create($user, $workspace, $clientCompany, $request->validated());

            return $this->respond('Proposal created.');
        } catch (EngagementException $exception) {
            return $this->reportFailure($exception);
        }
    }

    public function send(
        Workspace $workspace,
        ClientProposal $clientProposal,
        WorkspaceAuthorization $workspaceAuthorization,
        ProposalMutationAction $workflow,
    ): RedirectResponse {
        $request = request();
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('manage', $workspace);
        $workspaceAuthorization->assertOwnedBy($workspace, $clientProposal);

        try {
            $workflow->send($user, $workspace, $clientProposal);

            return $this->respond('Proposal sent.');
        } catch (EngagementException $exception) {
            return $this->reportFailure($exception);
        }
    }

    public function accept(
        AcceptProposalRequest $request,
        ClientCompany $clientCompany,
        ClientProposal $clientProposal,
        EngagementAuthorization $engagementAuthorization,
        WorkspaceAuthorization $workspaceAuthorization,
        ProposalMutationAction $workflow,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workspace = Workspace::query()->findOrFail($clientProposal->workspace_id);
        abort_unless(
            $clientProposal->client_company_id === $clientCompany->id
                && $clientProposal->is_visible_to_client,
            404,
        );
        $workspaceAuthorization->assertOwnedBy($workspace, $clientCompany);
        abort_unless($engagementAuthorization->canActAsClient($user, $workspace, $clientCompany), 403);

        try {
            $workflow->accept(
                $user,
                $workspace,
                $clientProposal,
                $request->string('signer_name')->toString(),
                $request->validated('signer_title'),
            );

            return $this->respond('Proposal accepted.');
        } catch (EngagementException $exception) {
            return $this->reportFailure($exception);
        }
    }
}
