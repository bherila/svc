<?php

namespace App\Console\Commands\Packages;

use App\Console\Commands\MutationCommand;
use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Support\Packages\EnrollmentLapse;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\EnrollmentVersions;
use App\Support\Packages\LapseOutcome;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Lapses service package sign-ups left open past their lapse date (ACC-6): an
 * offer never accepted, or an acceptance whose payment or autopay never came.
 * The unpaid sign-up invoice is voided and the draft agreement stays a draft.
 *
 * Each enrollment is decided in its own transaction, scoped to its own
 * workspace, under its own lock, so running it twice - or beside a payment -
 * changes nothing the second time. Runs in process from the scheduler; there is
 * no queue worker. Previews by default: without --apply it reports what it
 * would do and keeps nothing. While the feature is off it does nothing (X-1).
 */
final class LapseEnrollmentsCommand extends MutationCommand
{
    protected $signature = 'svc:packages:lapse-enrollments {--page=200 : Open enrollments read at a time; every open enrollment is considered, a page after another}';

    protected $description = 'Lapse service package sign-ups left unaccepted or unpaid past their lapse date';

    public function handle(PackageEnrollmentWorkflow $workflow, WorkspaceClock $clock): int
    {
        $apply = (bool) $this->applying();
        if (! EnsureServicePackagesEnabled::enabled()) {
            return $this->report($apply, [], 'Service packages are switched off; nothing was considered.');
        }
        $page = max(1, min(1000, (int) $this->option('page')));

        // Every open enrollment, a page at a time by id, so a run never stops
        // at the oldest few and leaves a newer one waiting behind them. No
        // elapsed-time filter here: an enrollment lapses on a day of its
        // workspace's calendar, which for a one-day offer made late in the
        // evening is minutes away. EnrollmentLapse decides, on the workspace's
        // calendar, which are due, and only those are locked and lapsed.
        $outcomes = [];
        $after = 0;
        do {
            $open = ServicePackageEnrollment::query()
                ->whereIn('status', [EnrollmentStatus::Offered->value, EnrollmentStatus::AwaitingPayment->value])
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($page)
                ->get(['id', 'public_id', 'workspace_id', 'service_package_id', 'status', 'service_package_version_id', 'accepted_version_id', 'current_version_id', 'offered_at', 'accepted_at']);
            $after = (int) ($open->last()->id ?? $after);
            foreach ($this->due($open, $clock) as [$workspace, $enrollment]) {
                try {
                    $outcome = $this->preview(fn (): LapseOutcome => $workflow->lapse($workspace, $enrollment))->value;
                } catch (Throwable $failure) {
                    report($failure);
                    $outcome = 'failed';
                }
                if ($outcome !== LapseOutcome::NotDue->value) {
                    $outcomes[] = ['workspace_id' => $workspace->public_id, 'enrollment_id' => $enrollment->public_id, 'outcome' => $outcome];
                }
            }
        } while ($open->count() === $page);

        return $this->report($apply, $outcomes, null);
    }

    /**
     * The page's enrollments due to lapse today on their workspace's calendar,
     * with their workspace. Read without locks: lapse() decides again under
     * the enrollment's lock. Two bounded reads per page.
     *
     * @param  Collection<int, ServicePackageEnrollment>  $open
     * @return list<array{Workspace, ServicePackageEnrollment}>
     */
    private function due(Collection $open, WorkspaceClock $clock): array
    {
        if ($open->isEmpty()) {
            return [];
        }
        $workspaces = Workspace::query()->whereIn('id', $open->pluck('workspace_id')->unique()->all())->get()->keyBy('id');
        $packages = ServicePackage::query()
            ->whereIn('id', $open->pluck('service_package_id')->unique()->all())
            ->get(['id', 'workspace_id', 'status', 'published_version_id'])
            ->keyBy('id');
        // The lapse window is the shown version's, as the portal and the API
        // show it (EnrollmentVersions::lapseWindow()).
        $windows = [];
        foreach ($open as $enrollment) {
            $package = $packages->get($enrollment->service_package_id);
            if ($package instanceof ServicePackage && (int) $package->workspace_id === (int) $enrollment->workspace_id) {
                $windows[$enrollment->id] = EnrollmentVersions::lapseWindow($package, $enrollment);
            }
        }
        $versions = ServicePackageVersion::query()
            ->whereIn('id', array_values(array_unique($windows)))
            ->get(['id', 'workspace_id', 'payment_lapse_days'])
            ->keyBy('id');

        $due = [];
        foreach ($open as $enrollment) {
            $workspace = $workspaces->get($enrollment->workspace_id);
            $version = isset($windows[$enrollment->id]) ? $versions->get($windows[$enrollment->id]) : null;
            if (! $workspace instanceof Workspace || ! $version instanceof ServicePackageVersion || (int) $version->workspace_id !== (int) $workspace->id) {
                continue;
            }
            $today = $clock->today($workspace);
            if (EnrollmentLapse::hasLapsed($enrollment->enrollmentStatus(), $enrollment->offered_at, $enrollment->accepted_at, (int) $version->payment_lapse_days, $today->tzName, $today)) {
                $due[] = [$workspace, $enrollment];
            }
        }

        return $due;
    }

    /** @param list<array{workspace_id: string, enrollment_id: string, outcome: string}> $outcomes */
    private function report(bool $apply, array $outcomes, ?string $note): int
    {
        if ($this->option('format') === 'json') {
            $this->json(['dry_run' => ! $apply, 'executed' => $apply, 'note' => $note, 'outcomes' => $outcomes]);

            return self::SUCCESS;
        }

        $this->components->info(($apply ? 'Lapse run: ' : 'Lapse preview (re-run with --apply to keep it): ').count($outcomes).' enrollment(s).');
        if ($note !== null) {
            $this->line('  '.$note);
        }
        foreach ($outcomes as $outcome) {
            $this->line(sprintf('  %s  %s  %s', $outcome['workspace_id'], $outcome['enrollment_id'], $outcome['outcome']));
        }

        return self::SUCCESS;
    }
}
