<?php

namespace App\Services\Packages;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\CompanyContacts;
use App\Services\Authorization\PortalAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\EnrollmentVersions;
use App\Support\Packages\PackageEnrollmentPresenter;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PortalAddRule;
use App\Support\Packages\PublicPackagePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A client's own services, for the client: `client_services.*` over the agent
 * API (API-5) and the portal's Services pages.
 *
 * A portal contact sees their company's enrollments - only their projects'
 * when their access is limited to projects - with the terms they agreed to,
 * or before acceptance the terms on offer. Only a company-wide contact is
 * offered the packages they could add (ACC-8). Withdrawn offers are not
 * shown. Workspace staff reach enrollments through `enrollments.*` instead,
 * except an owner or admin previewing the portal, who sees what the company's
 * contacts see.
 *
 * Every page is presented from bounded loads: the enrollments with their
 * records, then one query for every version whose terms are shown.
 */
final class ClientServiceReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly PortalAccess $portal,
        private readonly CompanyContacts $contacts,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, addable_packages: list<array<string, mixed>>, meta: array{next_cursor: string|null}}
     */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, ?string $companyId = null, int $limit = 25, ?string $cursor = null): array
    {
        $query = $this->visibleToContact($actor, $workspace);
        if ($companyId !== null) {
            $query->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id)->where('public_id', $companyId));
        }
        $key = 'client_services|'.($companyId ?? '');
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $key);
        if ($after !== null) {
            $query->where('id', '<', $after);
        }
        $enrollments = PackageEnrollmentReadService::withRelations($workspace, $query)->orderByDesc('id')->limit($limit + 1)->get();
        $more = $enrollments->count() > $limit;
        if ($more) {
            $enrollments->pop();
        }
        $last = $enrollments->last();

        return [
            'data' => $this->present($workspace, $enrollments),
            'addable_packages' => $this->companyContactIn($actor, $workspace, $companyId) ? $this->addable($workspace, null) : [],
            'meta' => ['next_cursor' => $more && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $key) : null],
        ];
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): array
    {
        $enrollment = PackageEnrollmentReadService::withRelations($workspace, $this->visibleToContact($actor, $workspace))->where('public_id', $publicId)->firstOrFail();

        return $this->present($workspace, new Collection([$enrollment]))[0];
    }

    /**
     * The portal's Services page: this company's services as the viewer may
     * see them, and - for a company-wide contact only - what they could add.
     * Null `addable_packages` means the viewer may not add, and the section
     * is not drawn.
     *
     * @return array{services: list<array<string, mixed>>, addable_packages: list<array<string, mixed>>|null}
     */
    public function forPortal(User $viewer, ClientCompany $company): array
    {
        $workspace = Workspace::query()->findOrFail($company->workspace_id);
        $enrollments = PackageEnrollmentReadService::withRelations($workspace, $this->visibleInPortal($viewer, $company))->orderByDesc('id')->get();

        return [
            'services' => $this->present($workspace, $enrollments),
            'addable_packages' => $this->contacts->isCompanyContact($company, $viewer) ? $this->addable($workspace, $company) : null,
        ];
    }

    /** One of this company's enrollments, as the viewer may see it in the portal; 404 otherwise. */
    public function findForPortal(User $viewer, ClientCompany $company, string $publicId): ServicePackageEnrollment
    {
        $workspace = Workspace::query()->findOrFail($company->workspace_id);

        return PackageEnrollmentReadService::withRelations($workspace, $this->visibleInPortal($viewer, $company))->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * One service's review-and-sign page: its terms (the version an acceptance
     * signs now, or the one signed), and - when a newer version was published
     * after the offer - the offered terms and which of them changed (EDGE-7).
     * `accept_url` is set only while the offer is open, it can be accepted
     * now, and the viewer is a company-wide contact (ACC-1).
     *
     * @return array{service: array<string, mixed>, offered_terms: array<string, mixed>|null, changed_terms: list<string>, accept_url: string|null, accept_refusal: array{code: string, message: string}|null}
     */
    public function reviewForPortal(User $viewer, ClientCompany $company, string $publicId): array
    {
        $workspace = Workspace::query()->findOrFail($company->workspace_id);
        $enrollment = $this->findForPortal($viewer, $company, $publicId);
        $package = $enrollment->package ?? throw new \LogicException('An enrollment names a package outside its workspace.');
        $shownId = EnrollmentVersions::shown($package, $enrollment);
        $versions = $this->versions($workspace, [$shownId, $enrollment->service_package_version_id]);
        $shown = PackageTerms::fromVersion($versions->get($shownId) ?? throw new \LogicException('An enrollment names a version outside its workspace.'));
        $offered = PackageTerms::fromVersion($versions->get($enrollment->service_package_version_id) ?? throw new \LogicException('An enrollment names a version outside its workspace.'));
        $changes = $shownId === $enrollment->service_package_version_id ? [] : $shown->differences($offered);

        $open = $enrollment->enrollmentStatus() === EnrollmentStatus::Offered;
        $refusal = match (true) {
            ! $open => null,
            EnrollmentVersions::toAccept($package, $enrollment) === null => PackageRefusal::OfferNotOpen,
            $shown->involvesPayment() => PackageRefusal::PaymentNotAvailableYet,
            default => null,
        };

        return [
            'service' => $this->present($workspace, new Collection([$enrollment]))[0],
            'offered_terms' => $changes === [] ? null : PackageEnrollmentPresenter::clientTerms($offered),
            'changed_terms' => $changes,
            'accept_url' => $open && $refusal === null && $this->contacts->isCompanyContact($company, $viewer)
                ? route('portal.services.accept', [$company, $enrollment->public_id], absolute: false)
                : null,
            'accept_refusal' => $refusal === null ? null : ['code' => $refusal->value, 'message' => $refusal->message()],
        ];
    }

    /**
     * Enrollments of companies this principal is a portal contact of, narrowed
     * to their projects when their access is limited (API-5).
     *
     * @return Builder<ServicePackageEnrollment>
     */
    private function visibleToContact(User|AgentPrincipal $actor, Workspace $workspace): Builder
    {
        abort_unless($this->access->canViewWorkspace($actor, $workspace), 404);

        return ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', EnrollmentStatus::Withdrawn->value)
            ->whereExists(fn (QueryBuilder $membership): QueryBuilder => $membership->selectRaw('1')->from('client_company_memberships')
                ->whereColumn('client_company_memberships.workspace_id', 'service_package_enrollments.workspace_id')
                ->whereColumn('client_company_memberships.client_company_id', 'service_package_enrollments.client_company_id')
                ->where('client_company_memberships.user_id', $actor->getAuthIdentifier()))
            ->whereIn('client_project_id', $this->portal->constrainProjectQuery(ClientProject::query()->where('workspace_id', $workspace->id), $actor)->select('id'));
    }

    /**
     * One company's enrollments for a portal viewer, by the portal's own
     * project rule: everything for a company-wide contact or an owner or
     * admin previewing, the granted projects for anyone narrower.
     *
     * @return Builder<ServicePackageEnrollment>
     */
    private function visibleInPortal(User $viewer, ClientCompany $company): Builder
    {
        $projects = $this->portal->visibleProjectIds($company, $viewer);

        return ServicePackageEnrollment::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('status', '!=', EnrollmentStatus::Withdrawn->value)
            ->when($projects !== null, fn (Builder $query): Builder => $query->whereIn('client_project_id', $projects ?? []));
    }

    private function companyContactIn(User|AgentPrincipal $actor, Workspace $workspace, ?string $companyId): bool
    {
        return ClientCompanyMembership::query()
            ->where('client_company_memberships.workspace_id', $workspace->id)
            ->where('client_company_memberships.user_id', $actor->getAuthIdentifier())
            ->where('client_company_memberships.access_scope', ClientCompanyMembership::SCOPE_COMPANY)
            ->when($companyId !== null, fn (Builder $query): Builder => $query->whereIn('client_company_memberships.client_company_id', ClientCompany::query()
                ->where('workspace_id', $workspace->id)->where('public_id', $companyId)->select('id')))
            ->exists();
    }

    /**
     * The workspace's published, portal-addable packages, each with the reason
     * it cannot be added now, if any. With a company, each also carries the
     * portal address that adds it, when it may be added.
     *
     * @return list<array<string, mixed>>
     */
    private function addable(Workspace $workspace, ?ClientCompany $company): array
    {
        $packages = ServicePackage::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', PackageStatus::Published->value)
            ->where('portal_addable', true)
            ->whereNotNull('published_version_id')
            ->orderBy('id')
            ->get();
        $versions = $this->versions($workspace, $packages->pluck('published_version_id')->filter()->all());

        $addable = [];
        foreach ($packages as $package) {
            $version = $versions->get((int) $package->published_version_id);
            if (! $version instanceof ServicePackageVersion) {
                continue;
            }
            $terms = PackageTerms::fromVersion($version);
            $refusal = PortalAddRule::refusal($package, $terms);
            $addable[] = [
                'id' => $package->public_id,
                ...PublicPackagePresenter::summary($workspace, $package, $terms),
                'add_refusal' => $refusal === null ? null : ['code' => $refusal->value, 'message' => $refusal->message()],
                ...($company === null ? [] : ['add_url' => $refusal === null ? route('portal.services.add', $company, absolute: false) : null]),
            ];
        }

        return $addable;
    }

    /**
     * @param  Collection<int, ServicePackageEnrollment>  $enrollments  loaded with their relations
     * @return list<array<string, mixed>>
     */
    private function present(Workspace $workspace, Collection $enrollments): array
    {
        if ($enrollments->isEmpty()) {
            return [];
        }
        $shown = [];
        foreach ($enrollments as $enrollment) {
            $package = $enrollment->package ?? throw new \LogicException('An enrollment names a package outside its workspace.');
            $shown[$enrollment->id] = EnrollmentVersions::shown($package, $enrollment);
        }
        $versions = $this->versions($workspace, array_values(array_unique($shown)));
        $base = rtrim((string) config('app.url'), '/');

        return array_values($enrollments->map(static function (ServicePackageEnrollment $enrollment) use ($versions, $shown, $base): array {
            $company = $enrollment->clientCompany ?? throw new \LogicException('An enrollment names a company outside its workspace.');

            return PackageEnrollmentPresenter::forClient(
                $enrollment,
                $versions->get($shown[$enrollment->id]) ?? throw new \LogicException('An enrollment names a version outside its workspace.'),
                $base.route('portal.services.show', [$company, $enrollment->public_id], false),
            );
        })->all());
    }

    /**
     * @param  list<int|string|null>  $ids
     * @return Collection<int, ServicePackageVersion> keyed by id
     */
    private function versions(Workspace $workspace, array $ids): Collection
    {
        return ServicePackageVersion::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_filter($ids, static fn (mixed $id): bool => $id !== null)))
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])
            ->get()
            ->keyBy('id');
    }
}
