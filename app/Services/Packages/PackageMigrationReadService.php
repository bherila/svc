<?php

namespace App\Services\Packages;

use App\Models\AgentPrincipal;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageMigration;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\Migration\MigrationStatus;
use App\Support\Packages\Migration\MigrationTargetStatus;
use App\Support\Packages\Migration\PackageMigrationPresenter;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Workspace-scoped reads of moves for owners and admins
 * (`package_migrations.list`, `package_migrations.get`, the package editor).
 *
 * Anyone else, a client contact included, is answered as if no move existed
 * (API-5). Each page is presented from one bounded eager load of the records
 * a move names, every one scoped to the workspace.
 */
final class PackageMigrationReadService
{
    public function __construct(private readonly AgentAccess $access) {}

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}} */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, ?MigrationStatus $status = null, int $limit = 25, ?string $cursor = null, ?ServicePackage $package = null): array
    {
        $this->requireManager($actor, $workspace);
        $query = self::withRelations($workspace, ServicePackageMigration::query()->where('workspace_id', $workspace->id));
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        if ($package !== null) {
            $query->where('service_package_id', $package->id);
        }
        $key = 'service_package_migrations|'.($status->value ?? '').'|'.($package->public_id ?? '');
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $key);
        if ($after !== null) {
            $query->where('id', '<', $after);
        }
        $migrations = $query->orderByDesc('id')->limit($limit + 1)->get();
        $more = $migrations->count() > $limit;
        if ($more) {
            $migrations->pop();
        }
        $last = $migrations->last();

        return [
            'data' => array_values($migrations->map(static fn (ServicePackageMigration $migration): array => PackageMigrationPresenter::forManager($workspace, $migration))->all()),
            'meta' => ['next_cursor' => $more && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $key) : null],
        ];
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): array
    {
        $this->requireManager($actor, $workspace);

        return PackageMigrationPresenter::forManager($workspace, self::withRelations($workspace, ServicePackageMigration::query()->where('workspace_id', $workspace->id))
            ->where('public_id', $publicId)->firstOrFail());
    }

    public function find(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): ServicePackageMigration
    {
        $this->requireManager($actor, $workspace);

        return ServicePackageMigration::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * The package's published versions, oldest first, each with how many
     * active services carry it: what the editor offers to move.
     *
     * @return list<array{version: ServicePackageVersion, subscribers: int}>
     */
    public function versions(Workspace $workspace, ServicePackage $package): array
    {
        $versions = ServicePackageVersion::query()
            ->where('workspace_id', $workspace->id)
            ->where('service_package_id', $package->id)
            ->whereNull('draft_marker')
            ->whereNotNull('published_at')
            ->orderBy('number')
            ->get(['id', 'public_id', 'workspace_id', 'service_package_id', 'number', 'name', 'published_at']);
        /** @var array<int, int> $counts */
        $counts = ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->where('service_package_id', $package->id)
            ->where('status', EnrollmentStatus::Active->value)
            ->groupByRaw(ServicePackageEnrollment::CARRIED_VERSION_SQL)
            ->selectRaw(ServicePackageEnrollment::CARRIED_VERSION_SQL.' as carried, count(*) as subscribers')
            ->pluck('subscribers', 'carried')
            ->mapWithKeys(static fn (mixed $count, mixed $id): array => [(int) $id => (int) $count])
            ->all();

        return array_values($versions->map(static fn (ServicePackageVersion $version): array => ['version' => $version, 'subscribers' => $counts[$version->id] ?? 0])->all());
    }

    /**
     * @param  Builder<ServicePackageMigration>  $query
     * @return Builder<ServicePackageMigration>
     */
    public static function withRelations(Workspace $workspace, Builder $query): Builder
    {
        $scoped = static fn ($related) => $related->where('workspace_id', $workspace->id);
        $withComponents = static fn ($version) => $version->where('workspace_id', $workspace->id)
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)]);

        return $query->with([
            'package' => $scoped, 'fromVersion' => $withComponents, 'toVersion' => $withComponents, 'scheduledBy:id,name',
            'targets' => static fn ($targets) => $targets->where('workspace_id', $workspace->id)->orderBy('id')
                ->with(['clientCompany' => $scoped, 'agreement' => $scoped, 'successorAgreement' => $scoped, 'enrollment' => $scoped]),
        ]);
    }

    /**
     * Eager loads for a query of enrollments: each one's scheduled move, if
     * any, with the version it moves to.
     *
     * @return array<string, Closure>
     */
    public static function scheduledRelation(int $workspaceId): array
    {
        return [
            'migrationTargets' => static fn ($targets) => $targets->where('workspace_id', $workspaceId)
                ->where('status', MigrationTargetStatus::Scheduled->value)->orderBy('id')
                ->with(['migration' => static fn ($migration) => $migration->where('workspace_id', $workspaceId)
                    ->with(['toVersion' => static fn ($version) => $version->where('workspace_id', $workspaceId)
                        ->with(['components' => static fn ($components) => $components->where('workspace_id', $workspaceId)])])]),
        ];
    }

    private function requireManager(User|AgentPrincipal $actor, Workspace $workspace): void
    {
        // Not 403: a reader who may not manage the workspace learns nothing
        // about which moves exist (API-5).
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
    }
}
