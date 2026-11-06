<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Services\WorkspaceAuthorization;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentClientPresenter;
use App\Support\AgentApi\Presenters\AgentProposalPresenter;
use App\Support\AgentApi\Presenters\AgreementReadPresenter;
use App\Support\AgentApi\Presenters\BillingScheduleReadPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Agreements tab of one client: the engagement tools that used to live on
 * the classic Operations page.
 *
 * Proposals (draft and send), agreements (activate, add a billing schedule) and
 * billing schedules (generate due invoices). Every control is the finished
 * capability of an existing agent API route, offered only to a viewer who
 * manages the workspace and only when the record is in a state that route
 * accepts; nothing here decides a billing rule. Anyone who may view the client
 * reads the lists, as Operations allowed.
 *
 * Every query names the workspace and the company: a company is bound by a
 * public id unique across workspaces, so the company alone is not a scope.
 */
final class ClientAgreementsController extends Controller
{
    public function __construct(
        private readonly BillingScheduleReadPresenter $schedules,
        private readonly WorkspaceAuthorization $authorization,
        private readonly ProjectAccess $access,
    ) {}

    public function __invoke(Request $request, Workspace $workspace, ClientCompany $clientCompany): Response
    {
        Gate::authorize('view', $workspace);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorization->assertOwnedBy($workspace, $clientCompany);
        $reachable = $this->access->reachableCompanyIds($user, $workspace);
        abort_if($reachable !== null && ! in_array((int) $clientCompany->id, $reachable, true), 404);

        $manages = Gate::forUser($user)->allows('manage', $workspace);

        $proposals = ClientProposal::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->with(['items' => fn ($items) => $items->where('workspace_id', $workspace->id)])
            ->latest('id')
            ->get();
        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->latest('id')
            ->get();
        $schedules = ClientBillingSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->with('agreement')
            ->latest('id')
            ->get();

        return Inertia::render('clients/agreements', [
            'company' => AgentClientPresenter::identity($clientCompany),
            'version' => AgentApiVersion::for($clientCompany),
            // The workspace's calendar, which a schedule's first run is read on.
            'timezone' => $workspace->timezone,
            'create_proposal_href' => $manages ? route('agent-api.v1.proposals.store', [$workspace], absolute: false) : null,
            'proposals' => $proposals->map(fn (ClientProposal $proposal): array => [
                ...AgentProposalPresenter::summary($proposal),
                'version' => AgentApiVersion::for($proposal),
                'href' => route('clients.proposal', [$workspace, $clientCompany, $proposal], absolute: false),
                'send_href' => $manages && $proposal->status === 'draft'
                    ? route('agent-api.v1.proposals.send', [$workspace, $proposal->public_id], absolute: false) : null,
            ])->values()->all(),
            'agreements' => $agreements->map(fn (ClientAgreement $agreement): array => [
                ...AgreementReadPresenter::summary($agreement),
                'version' => AgentApiVersion::for($agreement),
                'href' => route('clients.agreement', [$workspace, $clientCompany, $agreement], absolute: false),
                'activate_href' => $manages && $agreement->status === 'draft'
                    ? route('agent-api.v1.agreements.activate', [$workspace, $agreement->public_id], absolute: false) : null,
                'create_schedule_href' => $manages && $agreement->status === 'active' && ! $schedules->contains('client_agreement_id', $agreement->id)
                    ? route('agent-api.v1.billing-schedules.store', [$workspace], absolute: false) : null,
                'currency' => $agreement->currency,
            ])->values()->all(),
            'billing_schedules' => $schedules->map(fn (ClientBillingSchedule $schedule): array => [
                ...$this->schedules->present($schedule),
                'generate_href' => $manages && $schedule->is_active
                    ? route('agent-api.v1.billing-schedules.generate', [$workspace, $schedule->public_id], absolute: false) : null,
            ])->values()->all(),
        ]);
    }
}
