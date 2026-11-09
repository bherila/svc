<?php

namespace App\Services\Packages;

use App\Exceptions\PackageMigrationRefused;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageMigration;
use App\Models\ServicePackageMigrationTarget;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use App\Support\Packages\Migration\MigrationRefusal;
use App\Support\Packages\Migration\MigrationRules;
use App\Support\Packages\Migration\MigrationStatus;
use App\Support\Packages\Migration\MigrationSubscriber;
use App\Support\Packages\Migration\MigrationTargetStatus;
use App\Support\Packages\Migration\MigrationTermChanges;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PublicPackagePresenter;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scheduling and cancelling a move of subscribers to a new version
 * (docs/service-packages.md, MIG-1, MIG-2, MIG-4, MIG-5).
 *
 * **Scheduling** takes the package's lock (and its version, against
 * `expected_version`), then each chosen enrollment's, then their scheduled
 * targets, agreements and issued invoices with locking reads, in registry
 * order ({@see PackageMigrationRecords}), and refuses the whole move with a
 * coded reason when any chosen subscriber cannot take it on that date: only
 * the package's published version, from an older one, at a period start the
 * subscriber's notice and invoices allow (MIG-1). The move and one target per
 * subscriber are written together, with the new price and the company's
 * autopay limit, whose shortfall is flagged and never raised (MIG-4). Each
 * client is emailed the old and new terms and the date after commit, and the
 * portal shows the notice from then on (MIG-2).
 *
 * **Cancelling** is allowed until the effective date (MIG-5): the move and
 * every target still scheduled become cancelled under their locks, and each
 * client is told the terms stay as they are.
 *
 * Applying a move on its date is {@see PackageMigrationApplier}'s.
 */
final class PackageMigrationWorkflow
{
    public const int MESSAGE_MAX = 2000;

    public function __construct(
        private readonly AgentAccess $access,
        private readonly PackageMigrationRecords $records,
        private readonly ClientActivityRecorder $activities,
        private readonly PackageNotifier $notifier,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @param list<string> $enrollmentIds public ids */
    public function schedule(User $actor, Workspace $workspace, ServicePackage $package, string $fromVersionId, string $effectiveOn, array $enrollmentIds, ?string $message, ?string $expectedVersion = null): ServicePackageMigration
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
        $date = PackageMigrationPlanner::date($effectiveOn);
        $message = self::text($message);
        $enrollmentIds = array_values(array_unique($enrollmentIds));
        if ($enrollmentIds === []) {
            throw PackageMigrationRefused::because(MigrationRefusal::NoSubscribers);
        }

        return DB::transaction(function () use ($actor, $workspace, $package, $fromVersionId, $date, $enrollmentIds, $message, $expectedVersion): ServicePackageMigration {
            $locked = ServicePackage::query()->where('workspace_id', $workspace->id)->whereKey($package->getKey())->tap(Locks::forUpdate())->firstOrFail();
            abort_unless($expectedVersion === null || AgentApiVersion::matches($locked, $expectedVersion), 409, 'This package changed since it was read. Read it again before retrying.');
            $from = PackageMigrationPlanner::fromVersion($workspace, $locked, $fromVersionId);
            $to = PackageMigrationPlanner::toVersion($workspace, $locked);
            $fromTerms = PackageTerms::fromVersion($from);
            $toTerms = $to === null ? null : PackageTerms::fromVersion($to);
            $refusal = PackageMigrationPlanner::packageRefusal($locked, $from, $to, $fromTerms, $toTerms);
            if ($refusal !== null || $to === null || $toTerms === null) {
                throw PackageMigrationRefused::because($refusal ?? MigrationRefusal::NotNewer);
            }

            $ids = array_values(ServicePackageEnrollment::query()
                ->where('workspace_id', $workspace->id)
                ->where('service_package_id', $locked->id)
                ->whereIn('public_id', $enrollmentIds)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all());
            if (count($ids) !== count($enrollmentIds)) {
                throw PackageMigrationRefused::because(MigrationRefusal::NotASubscriber);
            }
            $subscribers = $this->records->subscribers($workspace, $from, $ids, locking: true);
            if (count($subscribers) !== count($ids)) {
                throw PackageMigrationRefused::because(MigrationRefusal::NotASubscriber);
            }
            foreach ($subscribers as $subscriber) {
                $refused = $subscriber->refusal($date);
                if ($refused !== null) {
                    throw PackageMigrationRefused::because($refused, "({$subscriber->company->name})");
                }
            }

            $price = PublicPackagePresenter::cycleAmount($toTerms);
            $migration = new ServicePackageMigration;
            $migration->forceFill([
                'workspace_id' => $workspace->id,
                'service_package_id' => $locked->id,
                'from_version_id' => $from->id,
                'to_version_id' => $to->id,
                'effective_on' => $date->toDateString(),
                'message' => $message,
                'status' => MigrationStatus::Scheduled->value,
                'scheduled_by_user_id' => $actor->id,
                'scheduled_at' => $this->instant(),
            ])->save();
            $changes = MigrationTermChanges::forClient($fromTerms, $toTerms);
            foreach ($subscribers as $subscriber) {
                $target = new ServicePackageMigrationTarget;
                $target->forceFill([
                    'workspace_id' => $workspace->id,
                    'service_package_migration_id' => $migration->id,
                    'service_package_enrollment_id' => $subscriber->enrollment->id,
                    'client_company_id' => $subscriber->company->id,
                    'client_agreement_id' => $subscriber->agreement->id,
                    'effective_on' => $date->toDateString(),
                    'status' => MigrationTargetStatus::Scheduled->value,
                    'new_price_amount' => $price,
                    'new_price_currency' => strtoupper($toTerms->currency),
                    'autopay_limit_amount' => $subscriber->autopayLimit,
                ])->save();
                $this->activities->record($workspace, $subscriber->company, 'agreement.move_scheduled', $subscriber->agreement, [
                    'migration_id' => $migration->public_id,
                    'from_version' => $from->number,
                    'to_version' => $to->number,
                    'effective_on' => $date->toDateString(),
                ], $actor, (string) Str::uuid());
                $this->notifier->moveScheduled($workspace, $subscriber->company, $toTerms->name, $date, $changes, $message,
                    PackageMigrationPlanner::autopayBelowPrice($subscriber, $price, $toTerms->currency) ? $subscriber->autopayLimit : null,
                    $price, $toTerms->currency, $this->portalUrl($subscriber));
            }

            return $migration;
        });
    }

    /**
     * Cancel a scheduled move and tell each client (MIG-5): before its date,
     * the whole move; from it on, the subscribers still waiting (held), the
     * ones already moved staying moved.
     */
    public function cancel(User $actor, Workspace $workspace, ServicePackageMigration $migration, ?string $expectedVersion = null): ServicePackageMigration
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);

        return DB::transaction(function () use ($actor, $workspace, $migration, $expectedVersion): ServicePackageMigration {
            $locked = ServicePackageMigration::query()->where('workspace_id', $workspace->id)->whereKey($migration->getKey())->tap(Locks::forUpdate())->firstOrFail();
            abort_unless($expectedVersion === null || AgentApiVersion::matches($locked, $expectedVersion), 409, 'This move changed since it was read. Read it again before retrying.');
            $refusal = MigrationRules::cancelRefusal($locked->migrationStatus());
            if ($refusal !== null) {
                throw PackageMigrationRefused::because($refusal);
            }
            $targets = ServicePackageMigrationTarget::query()
                ->where('workspace_id', $workspace->id)
                ->where('service_package_migration_id', $locked->id)
                ->where('status', MigrationTargetStatus::Scheduled->value)
                ->orderBy('id')
                ->tap(Locks::forUpdate())
                ->get();
            $now = $this->instant();
            // From its date on, subscribers already moved stay moved: the move then completes with them.
            $applied = ServicePackageMigrationTarget::query()
                ->where('workspace_id', $workspace->id)
                ->where('service_package_migration_id', $locked->id)
                ->where('status', MigrationTargetStatus::Applied->value)
                ->tap(Locks::forShare())
                ->exists();
            $locked->forceFill([
                'status' => ($applied ? MigrationStatus::Completed : MigrationStatus::Cancelled)->value,
                'cancelled_by_user_id' => $actor->id,
                'cancelled_at' => $now,
                'completed_at' => $applied ? $now : null,
            ])->save();
            if ($targets->isEmpty()) {
                return $locked;
            }

            $to = PackageTerms::fromVersion(ServicePackageVersion::query()->where('workspace_id', $workspace->id)->whereKey($locked->to_version_id)
                ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])->firstOrFail());
            $companies = ClientCompany::query()->where('workspace_id', $workspace->id)->whereIn('id', $targets->pluck('client_company_id')->unique()->all())->get()->keyBy('id');
            $enrollments = ServicePackageEnrollment::query()->where('workspace_id', $workspace->id)->whereIn('id', $targets->pluck('service_package_enrollment_id')->all())->get(['id', 'public_id'])->keyBy('id');
            $agreements = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereIn('id', $targets->pluck('client_agreement_id')->all())->get()->keyBy('id');
            foreach ($targets as $target) {
                $target->forceFill(['status' => MigrationTargetStatus::Cancelled->value, 'settled_at' => $now])->save();
                $company = $companies->get($target->client_company_id);
                $agreement = $agreements->get($target->client_agreement_id);
                $enrollment = $enrollments->get($target->service_package_enrollment_id);
                if (! $company instanceof ClientCompany || $agreement === null || $enrollment === null) {
                    continue;
                }
                $this->activities->record($workspace, $company, 'agreement.move_cancelled', $agreement, ['migration_id' => $locked->public_id], $actor, (string) Str::uuid());
                $this->notifier->moveCancelled($workspace, $company, $agreement->title, $to->name, $locked->effective_on,
                    route('portal.services.show', [$company, $enrollment->public_id], absolute: true));
            }

            return $locked;
        });
    }

    private function portalUrl(MigrationSubscriber $subscriber): string
    {
        return route('portal.services.show', [$subscriber->company, $subscriber->enrollment->public_id], absolute: true);
    }

    /** The present instant in UTC, for an event's timestamp; dates stay the workspace's. */
    private function instant(): CarbonImmutable
    {
        return $this->clock->now()->utc();
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
