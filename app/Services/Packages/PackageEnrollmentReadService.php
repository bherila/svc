<?php

namespace App\Services\Packages;

use App\Models\AgentPrincipal;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\PackageEnrollmentPresenter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Workspace-scoped reads of enrollments for owners and admins
 * (`enrollments.list`, `enrollments.get`).
 *
 * Anyone else, a client contact included, is answered as if no enrollment
 * existed (API-5). Each page is presented from one bounded eager load of the
 * records an enrollment names, every one of them scoped to the workspace.
 */
final class PackageEnrollmentReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly PackageAutopayRequirement $autopay,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}} */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, ?EnrollmentStatus $status = null, int $limit = 25, ?string $cursor = null): array
    {
        $this->requireManager($actor, $workspace);
        $query = self::withRelations($workspace, ServicePackageEnrollment::query()->where('workspace_id', $workspace->id));
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $key = 'service_package_enrollments|'.($status->value ?? '');
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $key);
        if ($after !== null) {
            $query->where('id', '<', $after);
        }
        // Newest first, like the request queue.
        $enrollments = $query->orderByDesc('id')->limit($limit + 1)->get();
        $more = $enrollments->count() > $limit;
        if ($more) {
            $enrollments->pop();
        }
        $last = $enrollments->last();

        $autopay = $this->autopay->clientAutopay($workspace, array_values($enrollments->pluck('client_company_id')->all()));

        return [
            'data' => array_values($enrollments->map(static fn (ServicePackageEnrollment $enrollment): array => PackageEnrollmentPresenter::forManager($workspace, $enrollment, $autopay[$enrollment->client_company_id] ?? null))->all()),
            'meta' => ['next_cursor' => $more && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $key) : null],
        ];
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): array
    {
        $this->requireManager($actor, $workspace);
        $enrollment = self::withRelations($workspace, ServicePackageEnrollment::query()->where('workspace_id', $workspace->id))
            ->where('public_id', $publicId)
            ->firstOrFail();

        return PackageEnrollmentPresenter::forManager($workspace, $enrollment, $this->autopay->clientAutopay($workspace, [$enrollment->client_company_id])[$enrollment->client_company_id] ?? null);
    }

    public function find(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): ServicePackageEnrollment
    {
        $this->requireManager($actor, $workspace);

        return ServicePackageEnrollment::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @param  Builder<ServicePackageEnrollment>  $query
     * @return Builder<ServicePackageEnrollment>
     */
    public static function withRelations(Workspace $workspace, Builder $query): Builder
    {
        $scoped = static fn ($related) => $related->where('workspace_id', $workspace->id);
        $withComponents = static fn ($version) => $version->where('workspace_id', $workspace->id)
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)]);

        return $query->with([
            'clientCompany' => $scoped, 'project' => $scoped, 'agreement' => $scoped, 'package' => $scoped,
            'version' => $withComponents, 'acceptedVersion' => $withComponents, 'packageRequest' => $scoped,
            'signupInvoice' => $scoped,
        ]);
    }

    private function requireManager(User|AgentPrincipal $actor, Workspace $workspace): void
    {
        // Not 403: a reader who may not manage the workspace learns nothing
        // about which enrollments exist (API-5).
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
    }
}
