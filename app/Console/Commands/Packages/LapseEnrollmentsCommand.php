<?php

namespace App\Console\Commands\Packages;

use App\Console\Commands\MutationCommand;
use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\ServicePackageEnrollment;
use App\Models\Workspace;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\LapseOutcome;
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
    protected $signature = 'svc:packages:lapse-enrollments {--limit=200 : Maximum open enrollments to consider in this run}';

    protected $description = 'Lapse service package sign-ups left unaccepted or unpaid past their lapse date';

    public function handle(PackageEnrollmentWorkflow $workflow): int
    {
        $apply = (bool) $this->applying();
        if (! EnsureServicePackagesEnabled::enabled()) {
            return $this->report($apply, [], 'Service packages are switched off; nothing was considered.');
        }
        $limit = max(1, min(1000, (int) $this->option('limit')));

        // Oldest first: an enrollment can lapse no sooner than a day after it
        // was offered, and whichever lapses first was offered first.
        $open = ServicePackageEnrollment::query()
            ->whereIn('status', [EnrollmentStatus::Offered->value, EnrollmentStatus::AwaitingPayment->value])
            ->where('offered_at', '<=', now()->subDay())
            ->orderBy('offered_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'public_id', 'workspace_id', 'status']);
        $workspaces = Workspace::query()->whereIn('id', $open->pluck('workspace_id')->unique()->all())->get()->keyBy('id');

        $outcomes = [];
        foreach ($open as $enrollment) {
            $workspace = $workspaces->get($enrollment->workspace_id);
            if (! $workspace instanceof Workspace) {
                continue;
            }
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

        return $this->report($apply, $outcomes, null);
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
