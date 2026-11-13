<?php

namespace App\Services\Packages;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageRequest;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\Packages\PackageRequestPresenter;
use App\Support\Packages\PackageRequestStatus;
use App\Support\Packages\PackageStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Workspace-scoped reads of sign-up requests for owners and admins.
 *
 * Each page of requests is presented from bounded loads: the packages and
 * versions they name, the clients they became, and - for open requests - the
 * existing client whose contact already uses the address (EDGE-4).
 */
final class PackageRequestReadService
{
    public function __construct(private readonly AgentAccess $access) {}

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}} */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, ?PackageRequestStatus $status = null, int $limit = 25, ?string $cursor = null): array
    {
        $this->requireManager($actor, $workspace);
        $query = ServicePackageRequest::query()->where('workspace_id', $workspace->id);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $key = 'service_package_requests|'.($status->value ?? '');
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $key);
        if ($after !== null) {
            $query->where('id', '<', $after);
        }
        // Newest first: the queue is read from the top.
        $requests = $query->orderByDesc('id')->limit($limit + 1)->get();
        $more = $requests->count() > $limit;
        if ($more) {
            $requests->pop();
        }
        $last = $requests->last();

        return [
            'data' => $this->present($workspace, $requests),
            'meta' => ['next_cursor' => $more && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $key) : null],
        ];
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): array
    {
        return $this->present($workspace, new Collection([$this->find($actor, $workspace, $publicId)]))[0];
    }

    public function find(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): ServicePackageRequest
    {
        $this->requireManager($actor, $workspace);

        return ServicePackageRequest::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * The enrollment an approved request became: its client, project and
     * agreement, from one bounded load. Never the invitation (ADM-5).
     *
     * @return array{id: string, status: string, client: array{id: string, name: string}, project_id: string, agreement_id: string}
     */
    public function enrollment(User|AgentPrincipal $actor, Workspace $workspace, string $requestPublicId): array
    {
        $request = $this->find($actor, $workspace, $requestPublicId);
        $scoped = static fn ($query) => $query->where('workspace_id', $workspace->id);
        $enrollment = ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->where('service_package_request_id', $request->id)
            ->with(['clientCompany' => $scoped, 'project' => $scoped, 'agreement' => $scoped])
            ->firstOrFail();

        return PackageRequestPresenter::enrollment($enrollment);
    }

    public function openCount(Workspace $workspace): int
    {
        return ServicePackageRequest::query()->where('workspace_id', $workspace->id)->where('status', PackageRequestStatus::New->value)->count();
    }

    /**
     * @param  Collection<int, ServicePackageRequest>  $requests
     * @return list<array<string, mixed>>
     */
    private function present(Workspace $workspace, Collection $requests): array
    {
        if ($requests->isEmpty()) {
            return [];
        }
        $packages = ServicePackage::query()->where('workspace_id', $workspace->id)->whereIn('id', $requests->pluck('service_package_id')->unique()->all())->get()->keyBy('id');
        // With their components: presenting a request reads its version's
        // terms, so one bounded load serves the page (Codex P2 on #666).
        // The versions read, and the versions an approval would offer now (EDGE-7).
        $offeredIds = $packages->filter(static fn (ServicePackage $package): bool => $package->packageStatus() === PackageStatus::Published)
            ->pluck('published_version_id')->filter()->all();
        $versions = ServicePackageVersion::query()->where('workspace_id', $workspace->id)->whereIn('id', array_values(array_unique([...$requests->pluck('service_package_version_id')->all(), ...$offeredIds])))
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])
            ->get()->keyBy('id');
        $clients = ClientCompany::query()->where('workspace_id', $workspace->id)->whereIn('id', $requests->pluck('client_company_id')->filter()->unique()->all())->get()->keyBy('id');
        $suggested = $this->suggestedClients($workspace, $requests);

        return array_values($requests->map(static function (ServicePackageRequest $request) use ($packages, $versions, $clients, $suggested): array {
            $package = $packages->get($request->service_package_id) ?? throw new \LogicException('A request names a package outside its workspace.');
            $offered = $package->packageStatus() === PackageStatus::Published && $package->published_version_id !== null ? $versions->get($package->published_version_id) : null;

            return PackageRequestPresenter::request(
                $request,
                $package,
                $versions->get($request->service_package_version_id) ?? throw new \LogicException('A request names a version outside its workspace.'),
                $request->client_company_id === null ? null : $clients->get($request->client_company_id),
                $suggested[$request->id] ?? null,
                $offered,
            );
        })->all());
    }

    /**
     * For each open request, an existing client whose contact or portal user
     * already uses the request's address (EDGE-4). Two bounded queries for the
     * whole page, matched in memory.
     *
     * @param  Collection<int, ServicePackageRequest>  $requests
     * @return array<int, ClientCompany>
     */
    private function suggestedClients(Workspace $workspace, Collection $requests): array
    {
        $open = $requests->filter(static fn (ServicePackageRequest $request): bool => $request->status === PackageRequestStatus::New->value && $request->email !== null);
        if ($open->isEmpty()) {
            return [];
        }
        $emails = $open->map(static fn (ServicePackageRequest $request): string => mb_strtolower((string) $request->email))->unique()->values()->all();

        $companyByEmail = [];
        foreach (ClientContact::query()->where('workspace_id', $workspace->id)->whereIn(DB::raw('LOWER(email)'), $emails)->orderBy('id')->get(['client_company_id', 'email']) as $contact) {
            $companyByEmail[mb_strtolower((string) $contact->email)] ??= (int) $contact->client_company_id;
        }
        foreach (ClientCompanyMembership::query()
            ->where('client_company_memberships.workspace_id', $workspace->id)
            ->join('users', 'users.id', '=', 'client_company_memberships.user_id')
            ->whereIn(DB::raw('LOWER(users.email)'), $emails)
            ->orderBy('client_company_memberships.id')
            ->get(['client_company_memberships.client_company_id', 'users.email']) as $membership) {
            $companyByEmail[mb_strtolower((string) $membership->getAttribute('email'))] ??= (int) $membership->client_company_id;
        }
        if ($companyByEmail === []) {
            return [];
        }
        $companies = ClientCompany::query()->where('workspace_id', $workspace->id)->whereIn('id', array_values($companyByEmail))->get()->keyBy('id');

        $suggested = [];
        foreach ($open as $request) {
            $companyId = $companyByEmail[mb_strtolower((string) $request->email)] ?? null;
            $company = $companyId === null ? null : $companies->get($companyId);
            if ($company instanceof ClientCompany) {
                $suggested[$request->id] = $company;
            }
        }

        return $suggested;
    }

    private function requireManager(User|AgentPrincipal $actor, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
    }
}
