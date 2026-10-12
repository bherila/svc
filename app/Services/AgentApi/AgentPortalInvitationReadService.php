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
            ->whereIn('status', [PortalInvitationStatus::Pending->value, PortalInvitationStatus::Expired->value, PortalInvitationStatus::NotApplied->value])
            ->orderBy('id');

        return CursorPage::run($workspace, 'portal-invitations|client='.$company->public_id, $limit, $cursor,
            fn (int $take, ?int $after): Collection => (clone $query)->when($after !== null, fn (Builder $rows): Builder => $rows->where('id', '>', $after))->limit($take)->get(),
            fn (ClientPortalInvitation $invitation): array => $this->present($workspace, $company, $invitation));
    }

    /** @return array<string, mixed> */
    public function get(User $actor, Workspace $workspace, string $clientId, string $invitationId): array
    {
        $company = $this->company($actor, $workspace, $clientId);

        return $this->present($workspace, $company, $this->find($workspace, $company, $invitationId));
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

    /** @return array<string, mixed> */
    private function present(Workspace $workspace, ClientCompany $company, ClientPortalInvitation $invitation): array
    {
        $status = $invitation->currentStatus();
        $projects = $invitation->scope() === PortalAccessScope::Company ? new Collection : ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereIn('id', $invitation->projectIds())
            ->orderBy('id')
            ->get();
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
            'revoke_href' => in_array($status, [PortalInvitationStatus::Accepted, PortalInvitationStatus::Revoked], true) ? null : $base.route('agent-api.v1.clients.portal-invitations.destroy', $parameters, false),
        ];
    }
}
