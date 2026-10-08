<?php

namespace App\Services\Engagement;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Engagement\ProposalAcceptanceAgreementQuery;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\DB;

/** Shared authorized, tenant-scoped mutation boundary for the website and agents. */
final class ProposalMutationAction
{
    public function __construct(private readonly ProposalAccess $access, private readonly ProposalWorkflow $workflow, private readonly ProposalAcceptanceAgreementQuery $agreements) {}

    /** @param array<string,mixed> $facts */
    public function create(User $actor, Workspace $workspace, ClientCompany $company, array $facts, ?string $expectedVersion = null, ?string $projectId = null): ClientProposal
    {
        $this->access->requireManager($actor, $workspace);

        return DB::transaction(function () use ($actor, $workspace, $company, $facts, $expectedVersion, $projectId): ClientProposal {
            $locked = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($company->id)->tap(Locks::forUpdate())->firstOrFail();
            $this->version($locked, $expectedVersion);
            $project = $projectId === null ? null : ClientProject::query()->where('workspace_id', $workspace->id)
                ->where('client_company_id', $locked->id)->where('public_id', $projectId)->firstOrFail();

            return $this->workflow->create($workspace, $locked, $project, $actor, $facts);
        });
    }

    public function send(User $actor, Workspace $workspace, ClientProposal $proposal, ?string $expectedVersion = null): ClientProposal
    {
        $this->access->requireManager($actor, $workspace);

        return DB::transaction(function () use ($workspace, $proposal, $expectedVersion): ClientProposal {
            $locked = $this->locked($workspace, $proposal);
            $this->version($locked, $expectedVersion);

            return $this->workflow->send($locked);
        });
    }

    public function accept(User $actor, Workspace $workspace, ClientProposal $proposal, string $signerName, ?string $signerTitle, ?string $expectedVersion = null): ClientProposal
    {
        return DB::transaction(function () use ($actor, $workspace, $proposal, $signerName, $signerTitle, $expectedVersion): ClientProposal {
            $locked = $this->locked($workspace, $proposal);
            // Take the agreement serialization lock before visibility reads can open a repeatable-read snapshot.
            $this->agreements->lockCompany($workspace->id, $locked->client_company_id);
            $this->access->requireAcceptance($actor, $workspace, $locked);
            $this->version($locked, $expectedVersion);

            return $this->workflow->accept($locked, $actor, $signerName, $signerTitle);
        });
    }

    private function locked(Workspace $workspace, ClientProposal $proposal): ClientProposal
    {
        return ClientProposal::query()->where('workspace_id', $workspace->id)->where('client_company_id', $proposal->client_company_id)
            ->whereKey($proposal->id)
            ->whereHas('clientCompany', fn ($company) => $company->where('workspace_id', $workspace->id))
            ->where(fn ($parents) => $parents->whereNull('client_project_id')->orWhereHas('project', fn ($project) => $project->where('workspace_id', $workspace->id)->whereColumn('client_projects.client_company_id', 'client_proposals.client_company_id')))
            ->tap(Locks::forUpdate())->firstOrFail();
    }

    private function version(ClientCompany|ClientProposal $record, ?string $expectedVersion): void
    {
        abort_unless($expectedVersion === null || AgentApiVersion::matches($record, $expectedVersion), 409, 'The resource changed. Read it again before retrying.');
    }
}
