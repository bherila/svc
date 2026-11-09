<?php

namespace App\Services\Packages;

use App\Models\AgentPrincipal;
use App\Models\ServicePackage;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Payments\PaymentConnectionResolver;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\ServicePackagePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Workspace-scoped reads of service packages for owners and admins (PKG-1).
 *
 * Everyone else is answered as if the package did not exist. Versions are
 * loaded in one bounded query per page, never one per package.
 */
final class ServicePackageReadService
{
    public function __construct(private readonly AgentAccess $access, private readonly PaymentConnectionResolver $payments) {}

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}} */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, ?PackageStatus $status = null, int $limit = 25, ?string $cursor = null): array
    {
        $this->requireManager($actor, $workspace);
        $query = ServicePackage::query()->where('workspace_id', $workspace->id);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $key = 'service_packages|'.($status->value ?? '');
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $key);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $packages = $query->orderBy('id')->limit($limit + 1)->get();
        $more = $packages->count() > $limit;
        if ($more) {
            $packages->pop();
        }
        $last = $packages->last();

        return [
            'data' => $this->present($workspace, $packages),
            'meta' => ['next_cursor' => $more && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $key) : null],
        ];
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): array
    {
        return $this->present($workspace, new Collection([$this->find($actor, $workspace, $publicId)]))[0];
    }

    public function find(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): ServicePackage
    {
        $this->requireManager($actor, $workspace);

        return ServicePackage::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @param  Collection<int, ServicePackage>  $packages
     * @return list<array<string, mixed>>
     */
    private function present(Workspace $workspace, Collection $packages): array
    {
        if ($packages->isEmpty()) {
            return [];
        }
        $versions = ServicePackageVersion::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('service_package_id', $packages->modelKeys())
            ->where(fn (Builder $query) => $query
                ->where('draft_marker', 1)
                ->orWhereIn('id', $packages->pluck('published_version_id')->filter()->all()))
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])
            ->get();
        $stripeConnected = $this->payments->stripe($workspace) !== null;

        return array_values($packages->map(function (ServicePackage $package) use ($workspace, $versions, $stripeConnected): array {
            $published = $versions->first(fn (ServicePackageVersion $version): bool => $version->id === $package->published_version_id);
            $draft = $versions->first(fn (ServicePackageVersion $version): bool => $version->service_package_id === $package->id && $version->draft_marker === 1);

            return ServicePackagePresenter::package($workspace, $package, $published, $draft, $stripeConnected);
        })->all());
    }

    private function requireManager(User|AgentPrincipal $actor, Workspace $workspace): void
    {
        // Not 403: a reader who may not manage the workspace learns nothing
        // about which packages exist (API-5).
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
    }
}
