<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientProposal;
use App\Models\ClientProposalItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Engagement\ProposalAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Eloquent\Builder;

final class AgentProposalReadService
{
    public function __construct(private readonly ProposalAccess $access, private readonly AgentAccess $agents) {}

    /** @return array<string,mixed> */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, ?string $companyId = null, ?string $status = null, int $limit = 25, ?string $cursor = null): array
    {
        $query = $this->access->visible($actor, $workspace);
        if ($companyId !== null) {
            $query->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id)->where('public_id', $companyId));
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        $key = 'proposals|'.json_encode([$companyId, $status], JSON_THROW_ON_ERROR);
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $key);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $records = $this->loads($query, $workspace)->orderBy('id')->limit($limit + 1)->get();
        $more = $records->count() > $limit;
        if ($more) {
            $records->pop();
        }
        $last = $records->last();
        $manager = $this->agents->isWorkspaceManager($actor, $workspace);

        return ['data' => $records->map(fn (ClientProposal $proposal): array => $this->present($manager, $workspace, $proposal))->values()->all(),
            'meta' => ['next_cursor' => $more && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $key) : null]];
    }

    /** @return array<string,mixed> */
    public function get(User|AgentPrincipal $actor, Workspace $workspace, string $id): array
    {
        $proposal = $this->loads($this->access->visible($actor, $workspace), $workspace)->where('public_id', $id)->firstOrFail();

        return $this->present($this->agents->isWorkspaceManager($actor, $workspace), $workspace, $proposal);
    }

    /** @param Builder<ClientProposal> $query
     * @return Builder<ClientProposal> */
    private function loads(Builder $query, Workspace $workspace): Builder
    {
        return $query->with(['clientCompany' => fn ($company) => $company->where('workspace_id', $workspace->id),
            'project' => fn ($project) => $project->where('workspace_id', $workspace->id),
            'items' => fn ($items) => $items->where('workspace_id', $workspace->id)->orderBy('sort_order')->orderBy('id')]);
    }

    /** @return array<string,mixed> */
    private function present(bool $manager, Workspace $workspace, ClientProposal $proposal): array
    {
        return [
            'id' => $proposal->public_id, 'workspace_id' => $workspace->public_id,
            'company_id' => $proposal->clientCompany->public_id, 'project_id' => $proposal->project?->public_id,
            'title' => $proposal->title, 'summary' => $proposal->summary, 'terms' => $proposal->terms,
            'currency' => $proposal->currency, 'status' => $proposal->status, 'is_visible_to_client' => $proposal->is_visible_to_client,
            'valid_until' => $proposal->valid_until?->toDateString(), 'sent_at' => $proposal->sent_at?->toIso8601String(),
            'accepted_at' => $proposal->accepted_at?->toIso8601String(), 'version' => AgentApiVersion::for($proposal),
            'total_amount' => $proposal->totalAmount(),
            'items' => $proposal->items->map(fn (ClientProposalItem $item): array => ['id' => $item->public_id, 'description' => $item->description, 'quantity' => (string) $item->quantity, 'unit_amount' => $item->unit_amount, 'cadence' => $item->cadence, 'sort_order' => $item->sort_order])->values()->all(),
            'url' => rtrim((string) config('app.url'), '/').($manager
                ? route('clients.proposal', ['workspace' => $workspace, 'clientCompany' => $proposal->clientCompany, 'clientProposal' => $proposal], false)
                : route('portal.proposal', ['clientCompany' => $proposal->clientCompany, 'clientProposal' => $proposal], false)),
        ];
    }
}
