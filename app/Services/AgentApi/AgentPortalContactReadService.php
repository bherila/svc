<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\PortalAccessState;
use App\Support\Access\PortalAccessVersion;
use App\Support\AgentApi\CursorPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Bounded portal contact pages with tenant-scoped eager loads. */
final class AgentPortalContactReadService
{
    public function __construct(private readonly AgentAccess $access) {}

    public function company(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): ClientCompany
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);

        return ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->firstOrFail();
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, string $clientId, int $limit, ?string $cursor): array
    {
        $company = $this->company($actor, $workspace, $clientId);
        $query = $this->memberships($workspace, $company)->orderBy('id');

        return CursorPage::run($workspace, 'portal-contacts|client='.$company->public_id, $limit, $cursor,
            fn (int $take, ?int $after): Collection => $query->when($after !== null, fn (Builder $rows): Builder => $rows->where('id', '>', $after))->limit($take)->get(),
            fn (ClientCompanyMembership $member): array => $this->present($workspace, $company, $member));
    }

    /** @return array<string, mixed> */
    public function get(User $actor, Workspace $workspace, string $clientId, string $userId): array
    {
        return $this->contact($workspace, $this->company($actor, $workspace, $clientId), $userId);
    }

    /**
     * One contact of a client the caller has already authorized, as the API
     * presents it.
     *
     * @return array<string, mixed>
     */
    public function contact(Workspace $workspace, ClientCompany $company, string $userId): array
    {
        $member = $this->memberships($workspace, $company)->whereHas('user', fn (Builder $users): Builder => $users->where('public_id', $userId))->firstOrFail();

        return $this->present($workspace, $company, $member);
    }

    /**
     * Every contact of a client the caller has already authorized, for the
     * client's settings page: the API's presentation, with same-origin
     * mutation URLs the page sends straight to `/api/v1`.
     *
     * @return list<array<string, mixed>>
     */
    public function all(Workspace $workspace, ClientCompany $company): array
    {
        return array_values($this->memberships($workspace, $company)->orderBy('id')->get()
            ->filter(static fn (ClientCompanyMembership $member): bool => $member->user instanceof User)
            ->map(fn (ClientCompanyMembership $member): array => $this->present($workspace, $company, $member, absolute: false))->all());
    }

    /** @return Builder<ClientCompanyMembership> */
    private function memberships(Workspace $workspace, ClientCompany $company): Builder
    {
        return ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
            ->with(['user', 'scopedProjects' => fn (BelongsToMany $projects): BelongsToMany => $projects
                ->where('client_projects.workspace_id', $workspace->id)->where('client_projects.client_company_id', $company->id)
                ->wherePivot('workspace_id', $workspace->id)->orderBy('client_projects.id')]);
    }

    /** @return array<string, mixed> */
    private function present(Workspace $workspace, ClientCompany $company, ClientCompanyMembership $member, bool $absolute = true): array
    {
        $user = $member->user;
        abort_unless($user instanceof User, 404);
        $scope = $member->access_scope === PortalAccessScope::Projects->value ? PortalAccessScope::Projects : PortalAccessScope::Company;
        $parameters = ['workspace' => $workspace->public_id, 'client' => $company->public_id, 'user' => $user->public_id];
        $origin = $absolute ? rtrim((string) config('app.url'), '/') : '';

        return [
            'id' => $user->public_id, 'name' => $user->name, 'email' => $user->email, 'scope' => $scope->value,
            'version' => PortalAccessVersion::for($member->public_id, $member->role, new PortalAccessState($scope,
                $scope === PortalAccessScope::Company ? [] : array_values($member->scopedProjects->pluck('public_id')->map(strval(...))->all())), (string) config('app.key')),
            'projects' => $scope === PortalAccessScope::Company ? [] : $member->scopedProjects->map(fn (ClientProject $project): array => [
                'id' => $project->public_id, 'name' => $project->name,
                'href' => $origin.route('agent-api.v1.projects.show', ['workspace' => $workspace->public_id, 'project' => $project->public_id], false),
            ])->values()->all(),
            'update_href' => $origin.route('agent-api.v1.clients.portal-access.update', $parameters, false),
            'revoke_href' => $origin.route('agent-api.v1.clients.portal-access.destroy', $parameters, false),
        ];
    }
}
