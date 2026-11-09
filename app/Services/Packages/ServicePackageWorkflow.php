<?php

namespace App\Services\Packages;

use App\Exceptions\ServicePackageRefused;
use App\Models\ServicePackage;
use App\Models\ServicePackageComponent;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Payments\PaymentConnectionResolver;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use App\Support\Packages\PackageComponentTerms;
use App\Support\Packages\PackagePublishRules;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\ServicePackageRules;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Every change to a service package, for the website and the agent API alike.
 *
 * One boundary so both surfaces get the same authorization, validation,
 * locking and refusals: input is validated here, not only in a form request,
 * and term updates are merged onto the stored draft before the whole is
 * validated (ServicePackageRules). A published version is never written again
 * (PKG-2): changing terms writes the draft, creating it from the published
 * version when there is none.
 *
 * Every write locks the package row first. Versions and components are its
 * children and are written only under that lock.
 */
final class ServicePackageWorkflow
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly PaymentConnectionResolver $payments,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, Workspace $workspace, array $input): ServicePackage
    {
        $this->requireManager($actor, $workspace);
        $package = Validator::make($input, ServicePackageRules::package(creating: true))->validate();
        $terms = $this->validatedTerms([
            ...PackageTerms::defaults((string) ($workspace->default_currency ?? 'USD')),
            ...array_intersect_key($input, array_flip(ServicePackageRules::termKeys())),
        ]);

        return DB::transaction(function () use ($actor, $workspace, $package, $terms): ServicePackage {
            $this->refuseTakenLinkName($workspace, (string) $package['link_name'], null);

            $created = new ServicePackage;
            $created->forceFill([
                'workspace_id' => $workspace->id,
                'link_name' => $package['link_name'],
                'status' => PackageStatus::Draft->value,
                'is_listed' => (bool) ($package['is_listed'] ?? false),
                'portal_addable' => (bool) ($package['portal_addable'] ?? true),
                'created_by_user_id' => $actor->id,
            ])->save();
            $this->writeDraft($created, null, 1, $terms);

            return $created;
        });
    }

    /**
     * Change package fields and/or the draft's terms.
     *
     * Keyed presence: only what the caller sent changes. Sending `components`
     * or `intake_questions` replaces that whole list.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, Workspace $workspace, ServicePackage $package, array $input, ?string $expectedVersion = null): ServicePackage
    {
        $this->requireManager($actor, $workspace);
        $fields = Validator::make($input, ServicePackageRules::package(creating: false))->validate();
        $termInput = array_intersect_key($input, array_flip(ServicePackageRules::termKeys()));

        return DB::transaction(function () use ($workspace, $package, $fields, $termInput, $expectedVersion): ServicePackage {
            $locked = $this->locked($workspace, $package);
            $this->assertVersion($locked, $expectedVersion);

            $revision = $locked->lock_version;
            if ($fields !== []) {
                if (isset($fields['link_name'])) {
                    $this->refuseTakenLinkName($workspace, (string) $fields['link_name'], $locked->id);
                }
                $locked->forceFill($fields)->save();
            }

            if ($termInput !== []) {
                $draft = $this->draftOf($locked);
                $base = $draft ?? $this->publishedOf($locked);
                $terms = $this->validatedTerms([
                    ...($base === null ? [] : PackageTerms::fromVersion($base)->toArray()),
                    ...$termInput,
                ]);
                $this->writeDraft($locked, $draft, $draft->number ?? $this->nextNumber($locked), $terms);
                // The terms live on the draft, so the package row may not have
                // changed - and package fields sent with their current values
                // save nothing. Either way the package's revision must move,
                // or a second editor holding the old version would not be
                // refused (Codex P1 on #665).
                if ($locked->lock_version === $revision) {
                    $locked->advanceAgentRevision();
                }
            }

            return $locked->refresh();
        });
    }

    public function publish(User $actor, Workspace $workspace, ServicePackage $package, ?string $expectedVersion = null): ServicePackage
    {
        $this->requireManager($actor, $workspace);
        $stripeConnected = $this->payments->stripe($workspace) !== null;

        return DB::transaction(function () use ($actor, $workspace, $package, $expectedVersion, $stripeConnected): ServicePackage {
            $locked = $this->locked($workspace, $package);
            $this->assertVersion($locked, $expectedVersion);
            $draft = $this->draftOf($locked) ?? throw ServicePackageRefused::because(PackageRefusal::NothingToPublish);

            $refusals = PackagePublishRules::refusals(PackageTerms::fromVersion($draft), $stripeConnected);
            if ($refusals !== []) {
                throw new ServicePackageRefused($refusals);
            }

            $draft->forceFill([
                'draft_marker' => null,
                'published_at' => $this->clock->now(),
                'published_by_user_id' => $actor->id,
            ])->save();
            $locked->forceFill([
                'status' => PackageStatus::Published->value,
                'published_version_id' => $draft->id,
            ])->save();
            $this->ensureCatalogToken($workspace);

            return $locked->refresh();
        });
    }

    public function retire(User $actor, Workspace $workspace, ServicePackage $package, ?string $expectedVersion = null): ServicePackage
    {
        $this->requireManager($actor, $workspace);

        return DB::transaction(function () use ($workspace, $package, $expectedVersion): ServicePackage {
            $locked = $this->locked($workspace, $package);
            $this->assertVersion($locked, $expectedVersion);

            if ($locked->packageStatus() !== PackageStatus::Published) {
                throw ServicePackageRefused::because(PackageRefusal::NotPublished);
            }

            $locked->forceFill(['status' => PackageStatus::Retired->value])->save();

            return $locked->refresh();
        });
    }

    private function requireManager(User $actor, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
    }

    /** @param array<string, mixed> $terms */
    private function validatedTerms(array $terms): PackageTerms
    {
        $terms = ServicePackageRules::withQuestionKeys($terms);

        return PackageTerms::fromArray(Validator::make($terms, ServicePackageRules::terms())->validate());
    }

    private function locked(Workspace $workspace, ServicePackage $package): ServicePackage
    {
        return ServicePackage::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($package->getKey())
            ->tap(Locks::forUpdate())
            ->firstOrFail();
    }

    private function assertVersion(ServicePackage $package, ?string $expectedVersion): void
    {
        abort_unless($expectedVersion === null || AgentApiVersion::matches($package, $expectedVersion), 409, 'The package changed. Read it again before retrying.');
    }

    private function refuseTakenLinkName(Workspace $workspace, string $linkName, ?int $exceptId): void
    {
        $taken = ServicePackage::query()
            ->where('workspace_id', $workspace->id)
            ->where('link_name', $linkName)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ServicePackageRefused::because(PackageRefusal::LinkNameTaken);
        }
    }

    private function draftOf(ServicePackage $package): ?ServicePackageVersion
    {
        return ServicePackageVersion::query()
            ->where('workspace_id', $package->workspace_id)
            ->where('service_package_id', $package->id)
            ->where('draft_marker', 1)
            ->with(['components' => fn ($components) => $components->where('workspace_id', $package->workspace_id)])
            ->first();
    }

    private function publishedOf(ServicePackage $package): ?ServicePackageVersion
    {
        if ($package->published_version_id === null) {
            return null;
        }

        return ServicePackageVersion::query()
            ->where('workspace_id', $package->workspace_id)
            ->where('service_package_id', $package->id)
            ->whereKey($package->published_version_id)
            ->with(['components' => fn ($components) => $components->where('workspace_id', $package->workspace_id)])
            ->first();
    }

    private function nextNumber(ServicePackage $package): int
    {
        return 1 + (int) ServicePackageVersion::query()
            ->where('workspace_id', $package->workspace_id)
            ->where('service_package_id', $package->id)
            ->max('number');
    }

    private function writeDraft(ServicePackage $package, ?ServicePackageVersion $draft, int $number, PackageTerms $terms): ServicePackageVersion
    {
        $version = $draft ?? new ServicePackageVersion;
        $version->forceFill([
            ...$terms->versionAttributes(),
            'workspace_id' => $package->workspace_id,
            'service_package_id' => $package->id,
            'number' => $number,
            'draft_marker' => 1,
        ])->save();

        ServicePackageComponent::query()
            ->where('workspace_id', $package->workspace_id)
            ->where('service_package_version_id', $version->id)
            ->delete();
        foreach ($terms->components as $index => $component) {
            $version->components()->make()->forceFill([
                ...$this->componentAttributes($component),
                'workspace_id' => $package->workspace_id,
                'sort_order' => $index,
            ])->save();
        }

        return $version;
    }

    /** @return array<string, mixed> */
    private function componentAttributes(PackageComponentTerms $component): array
    {
        return $component->toArray();
    }

    /**
     * Give the workspace its public catalog address on first publish (PUB-2).
     *
     * A conditional update rather than a read-then-write, so two first
     * publishes cannot both mint a token; the loser keeps the winner's.
     */
    private function ensureCatalogToken(Workspace $workspace): void
    {
        Workspace::query()
            ->whereKey($workspace->id)
            ->whereNull('package_catalog_token')
            ->update(['package_catalog_token' => Str::lower(Str::random(24))]);

        // Whichever publish minted it, the caller's workspace carries the
        // token from here on, so the answer it builds names the public URL.
        $workspace->setAttribute('package_catalog_token', Workspace::query()->whereKey($workspace->id)->value('package_catalog_token'));
        $workspace->syncOriginalAttribute('package_catalog_token');
    }
}
