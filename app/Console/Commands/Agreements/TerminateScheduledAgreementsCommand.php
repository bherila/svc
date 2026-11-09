<?php

namespace App\Console\Commands\Agreements;

use App\Console\Commands\MutationCommand;
use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\AgreementScheduledTermination;
use App\Models\Workspace;
use App\Services\Engagement\AgreementCancellations;
use App\Support\Engagement\Cancellation\CancellationRules;
use App\Support\Engagement\Cancellation\ScheduledTerminationStatus;
use App\Support\Engagement\Cancellation\TerminationRunOutcome;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Terminates each agreement on its scheduled end date (CAN-5, EDGE-9): a
 * cancellation a company contact scheduled in `self_serve` mode, a request an
 * owner or admin approved, or an end an owner or admin scheduled. Termination
 * goes through AgreementWorkflow::terminate(), which writes `status`,
 * `ends_on` and `terminated_at` and records activity, so billing after it
 * follows the existing termination rules.
 *
 * Every scheduled end is walked a page at a time by id, so a run never stops
 * at the oldest few; whether one is due is decided on its workspace's
 * calendar, and only those are locked. Each is carried out in its own
 * transaction, under its agreement's lock: an undo racing it, a second run or
 * a run beside it each find it already settled. Runs in process from the
 * scheduler; there is no queue worker. Previews by default: without --apply
 * it reports what it would do and keeps nothing. While the feature is off it
 * does nothing (X-1).
 */
final class TerminateScheduledAgreementsCommand extends MutationCommand
{
    protected $signature = 'svc:agreements:terminate-scheduled {--page=200 : Scheduled ends read at a time; every scheduled end is considered, a page after another}';

    protected $description = 'Terminate agreements whose scheduled end date has been reached';

    public function handle(AgreementCancellations $cancellations, WorkspaceClock $clock): int
    {
        $apply = (bool) $this->applying();
        if (! EnsureServicePackagesEnabled::enabled()) {
            return $this->report($apply, [], 'Service packages are switched off; nothing was considered.');
        }
        $page = max(1, min(1000, (int) $this->option('page')));

        $outcomes = [];
        $after = 0;
        do {
            $scheduled = AgreementScheduledTermination::query()
                ->where('status', ScheduledTerminationStatus::Scheduled->value)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($page)
                ->get(['id', 'public_id', 'workspace_id', 'client_agreement_id', 'status', 'ends_on']);
            $after = (int) ($scheduled->last()->id ?? $after);
            foreach ($this->due($scheduled, $clock) as [$workspace, $end]) {
                try {
                    $outcome = $this->preview(fn (): TerminationRunOutcome => $cancellations->terminateDue($workspace, $end))->value;
                } catch (Throwable $failure) {
                    report($failure);
                    $outcome = 'failed';
                }
                if ($outcome !== TerminationRunOutcome::NotDue->value) {
                    $outcomes[] = ['workspace_id' => $workspace->public_id, 'scheduled_end_id' => $end->public_id, 'ends_on' => $end->ends_on->toDateString(), 'outcome' => $outcome];
                }
            }
        } while ($scheduled->count() === $page);

        return $this->report($apply, $outcomes, null);
    }

    /**
     * The page's scheduled ends that are due on their workspace's calendar,
     * with their workspace. Read without locks: terminateDue() decides again
     * under the agreement's lock. One read of workspaces per page.
     *
     * @param  Collection<int, AgreementScheduledTermination>  $scheduled
     * @return list<array{Workspace, AgreementScheduledTermination}>
     */
    private function due(Collection $scheduled, WorkspaceClock $clock): array
    {
        if ($scheduled->isEmpty()) {
            return [];
        }
        $workspaces = Workspace::query()->whereIn('id', $scheduled->pluck('workspace_id')->unique()->all())->get()->keyBy('id');

        $due = [];
        foreach ($scheduled as $end) {
            $workspace = $workspaces->get($end->workspace_id);
            if ($workspace instanceof Workspace && CancellationRules::isDue($end->terminationStatus(), $end->ends_on, $clock->today($workspace))) {
                $due[] = [$workspace, $end];
            }
        }

        return $due;
    }

    /** @param list<array{workspace_id: string, scheduled_end_id: string, ends_on: string, outcome: string}> $outcomes */
    private function report(bool $apply, array $outcomes, ?string $note): int
    {
        if ($this->option('format') === 'json') {
            $this->json(['dry_run' => ! $apply, 'executed' => $apply, 'note' => $note, 'outcomes' => $outcomes]);

            return self::SUCCESS;
        }

        $this->components->info(($apply ? 'Termination run: ' : 'Termination preview (re-run with --apply to keep it): ').count($outcomes).' agreement(s).');
        if ($note !== null) {
            $this->line('  '.$note);
        }
        foreach ($outcomes as $outcome) {
            $this->line(sprintf('  %s  %s  %s  %s', $outcome['workspace_id'], $outcome['scheduled_end_id'], $outcome['ends_on'], $outcome['outcome']));
        }

        return self::SUCCESS;
    }
}
