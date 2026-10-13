<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientPortalInvitation;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\PortalInvitationStatus;
use App\Support\AgentApi\CursorPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Portal invitations as agents see them (#430).
 *
 * Never the acceptance link: it is a bearer credential, kept for the managers
 * on the client's settings page. Nothing here reads it, and the presented
 * shape has no field it could go in.
 */
final class AgentPortalInvitationReadService
{
    public function __construct(private readonly AgentPortalContactReadService $contacts) {}

    public function company(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): ClientCompany
    {
        return $this->contacts->company($actor, $workspace, $publicId);
    }

    /**
     * The client's open and not-applied invitations, oldest first.
     *
     * @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}}
     */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, string $clientId, int $limit, ?string $cursor): array
    {
        $company = $this->company($actor, $workspace, $clientId);
        $query = $this->invitations($workspace, $company)
            ->where(fn (Builder $rows): Builder => $rows
                ->whereIn('status', [PortalInvitationStatus::Pending->value, PortalInvitationStatus::Expired->value, PortalInvitationStatus::NotApplied->value])
                ->orWhere(fn (Builder $withdrawal): Builder => $withdrawal->where('status', PortalInvitationStatus::Revoked->value)->where('provider_revocation_pending', true)))
            ->orderBy('id');

        // The page's projects are loaded once, when the page is, not per row.
        $projects = new Collection;

        return CursorPage::run($workspace, 'portal-invitations|client='.$company->public_id, $limit, $cursor,
            function (int $take, ?int $after) use ($query, $workspace, $company, &$projects): Collection {
                $page = (clone $query)->when($after !== null, fn (Builder $rows): Builder => $rows->where('id', '>', $after))->limit($take)->get();
                $projects = $this->projects($workspace, $company, $page);

                return $page;
            },
            function (ClientPortalInvitation $invitation) use ($workspace, $company, &$projects): array {
                return $this->present($workspace, $company, $invitation, $projects);
            });
    }

    /** @return array<string, mixed> */
    public function get(User $actor, Workspace $workspace, string $clientId, string $invitationId): array
    {
        $company = $this->company($actor, $workspace, $clientId);
        $invitation = $this->find($workspace, $company, $invitationId);

        return $this->present($workspace, $company, $invitation, $this->projects($workspace, $company, new Collection([$invitation])));
    }

    /** One of this client's invitations, by public id; anything else is not found. */
    public function find(Workspace $workspace, ClientCompany $company, string $publicId): ClientPortalInvitation
    {
        return $this->invitations($workspace, $company)->where('public_id', $publicId)->firstOrFail();
    }

    /** @return Builder<ClientPortalInvitation> */
    private function invitations(Workspace $workspace, ClientCompany $company): Builder
    {
        return ClientPortalInvitation::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id);
    }

    /**
     * Every project these invitations name that still belongs to the client,
     * in one query, keyed by id.
     *
     * @param  Collection<int, ClientPortalInvitation>  $invitations
     * @return Collection<int, ClientProject>
     */
    private function projects(Workspace $workspace, ClientCompany $company, Collection $invitations): Collection
    {
        $ids = array_values(array_unique(array_merge([], ...array_values($invitations
            ->filter(static fn (ClientPortalInvitation $invitation): bool => $invitation->scope() === PortalAccessScope::Projects)
            ->map(static fn (ClientPortalInvitation $invitation): array => $invitation->projectIds())
            ->all()))));

        return $ids === [] ? new Collection : ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, ClientProject>  $loaded  from {@see projects()}, keyed by id
     * @return array<string, mixed>
     */
    private function present(Workspace $workspace, ClientCompany $company, ClientPortalInvitation $invitation, Collection $loaded): array
    {
        $status = $invitation->currentStatus();
        $projects = $invitation->scope() === PortalAccessScope::Company ? new Collection : $loaded
            ->filter(static fn (ClientProject $project, int $id): bool => in_array($id, $invitation->projectIds(), true))
            ->sortKeys()
            ->values();
        $base = rtrim((string) config('app.url'), '/');
        $parameters = ['workspace' => $workspace->public_id, 'client' => $company->public_id, 'invitation' => $invitation->public_id];

        return [
            'id' => $invitation->public_id,
            'email' => $invitation->email,
            'scope' => $invitation->scope()->value,
            'projects' => array_values($projects->map(fn (ClientProject $project): array => [
                'id' => $project->public_id, 'name' => $project->name,
                'href' => $base.route('agent-api.v1.projects.show', ['workspace' => $workspace->public_id, 'project' => $project->public_id], false),
            ])->all()),
            'status' => $status->value,
            'not_applied_reason' => $invitation->notAppliedReason()?->value,
            'expires_at' => $invitation->expires_at?->utc()->toIso8601ZuluString(),
            'email_sent' => $invitation->email_sent,
            'created_at' => $invitation->created_at->utc()->toIso8601ZuluString(),
            'resend_href' => $status->isOpen() ? $base.route('agent-api.v1.clients.portal-invitations.resend', $parameters, false) : null,
            'revoke_href' => $status === PortalInvitationStatus::Accepted || ($status === PortalInvitationStatus::Revoked && ! $invitation->provider_revocation_pending) ? null : $base.route('agent-api.v1.clients.portal-invitations.destroy', $parameters, false),
        ];
    }
}
