<?php

namespace App\Console\Commands\Packages;

use App\Console\Commands\MutationCommand;
use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\ServicePackageMigrationTarget;
use App\Models\Workspace;
use App\Services\Packages\PackageMigrationApplier;
use App\Support\Packages\Migration\MigrationRules;
use App\Support\Packages\Migration\MigrationRunOutcome;
use App\Support\Packages\Migration\MigrationTargetStatus;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Moves each subscriber to its new version on the effective date (MIG-3):
 * the agreement it is on ends the day before, and one carrying the new terms
 * starts that day ({@see PackageMigrationApplier}). A subscriber whose
 * agreement has ended, or ends first, is skipped and the skip recorded; one
 * waiting on an owner (an invoice already sold for the new period, a pending
 * cancellation asking to end before it) is held, recorded, and tried again
 * every run.
 *
 * Every scheduled subscriber is walked a page at a time by id, so a run never
 * stops at the oldest few; whether one is due is decided on its workspace's
 * calendar, and only those are locked. Each is carried out in its own
 * transaction: a second run, or one beside it, finds it settled. Runs in
 * process from the scheduler; there is no queue worker. Previews by default:
 * without --apply it reports what it would do and keeps nothing. While the
 * feature is off it does nothing (X-1).
 */
final class ApplyMigrationsCommand extends MutationCommand
{
    protected $signature = 'svc:packages:apply-migrations {--page=200 : Scheduled subscribers read at a time; every one is considered, a page after another}';

    protected $description = 'Move package subscribers to their new version on the effective date';

    public function handle(PackageMigrationApplier $applier, WorkspaceClock $clock): int
    {
        $apply = (bool) $this->applying();
        if (! EnsureServicePackagesEnabled::enabled()) {
            return $this->report($apply, [], 'Service packages are switched off; nothing was considered.');
        }
        $page = max(1, min(1000, (int) $this->option('page')));

        $outcomes = [];
        $after = 0;
        do {
            $scheduled = ServicePackageMigrationTarget::query()
                ->where('status', MigrationTargetStatus::Scheduled->value)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($page)
                ->get(['id', 'public_id', 'workspace_id', 'service_package_migration_id', 'service_package_enrollment_id', 'client_agreement_id', 'status', 'effective_on']);
            $after = (int) ($scheduled->last()->id ?? $after);
            foreach ($this->due($scheduled, $clock) as [$workspace, $target]) {
                try {
                    $outcome = $this->preview(fn (): MigrationRunOutcome => $applier->applyDue($workspace, $target))->value;
                } catch (Throwable $failure) {
                    report($failure);
                    $outcome = 'failed';
                }
                if ($outcome !== MigrationRunOutcome::NotDue->value) {
                    $outcomes[] = ['workspace_id' => $workspace->public_id, 'target_id' => $target->public_id, 'effective_on' => $target->effective_on->toDateString(), 'outcome' => $outcome];
                }
            }
        } while ($scheduled->count() === $page);

        return $this->report($apply, $outcomes, null);
    }

    /**
     * The page's subscribers due on their workspace's calendar, with their
     * workspace. Read without locks: applyDue() decides again under its own.
     *
     * @param  Collection<int, ServicePackageMigrationTarget>  $scheduled
     * @return list<array{Workspace, ServicePackageMigrationTarget}>
     */
    private function due(Collection $scheduled, WorkspaceClock $clock): array
    {
        if ($scheduled->isEmpty()) {
            return [];
        }
        $workspaces = Workspace::query()->whereIn('id', $scheduled->pluck('workspace_id')->unique()->all())->get()->keyBy('id');

        $due = [];
        foreach ($scheduled as $target) {
            $workspace = $workspaces->get($target->workspace_id);
            if ($workspace instanceof Workspace && MigrationRules::isDue($target->targetStatus(), $target->effective_on, $clock->today($workspace))) {
                $due[] = [$workspace, $target];
            }
        }

        return $due;
    }

    /** @param list<array{workspace_id: string, target_id: string, effective_on: string, outcome: string}> $outcomes */
    private function report(bool $apply, array $outcomes, ?string $note): int
    {
        if ($this->option('format') === 'json') {
            $this->json(['dry_run' => ! $apply, 'executed' => $apply, 'note' => $note, 'outcomes' => $outcomes]);

            return self::SUCCESS;
        }

        $this->components->info(($apply ? 'Package move run: ' : 'Package move preview (re-run with --apply to keep it): ').count($outcomes).' subscriber(s).');
        if ($note !== null) {
            $this->line('  '.$note);
        }
        foreach ($outcomes as $outcome) {
            $this->line(sprintf('  %s  %s  %s  %s', $outcome['workspace_id'], $outcome['target_id'], $outcome['effective_on'], $outcome['outcome']));
        }

        return self::SUCCESS;
    }
}
