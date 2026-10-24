<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\MutationCommand;
use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\Workspace;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\CadencePeriodGuard;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\UncoveredCadencePeriods;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Create the draft for every active recurring agreement whose current cadence
 * period has started with nothing billed for it - the periods
 * `billing_audit.stale_and_missing` reports missing - so a month does not go
 * unbilled because nobody called `invoices.generate_period` on the 1st.
 *
 * Drafts only. Issuing is the act of asking a client for money and stays an
 * operator's, as does sending; a draft charges nobody and an operator can
 * still regenerate, issue or void it. Each period goes through
 * {@see CadencePeriodGuard} and then `generateCadencePeriod()`, exactly as
 * `invoices.generate_period` does, so this can create nothing an operator
 * could not. The scheduled run is withheld until
 * `svc.billing.scheduled_cadence_drafts` is on; manual runs are unaffected.
 *
 * An agreement with an active billing schedule is left to that schedule,
 * which issues its own invoices: a draft here would claim its period without
 * billing it, and the schedule halts on a pending draft. Only the period
 * containing today is considered; an older gap may have been settled off the
 * books and is an operator's to reopen.
 *
 * Every period commits or rolls back on its own, so one agreement's refusal
 * neither undoes nor blocks another's draft. A refusal is logged and makes the
 * run exit non-zero; the next run tries that period again.
 */
final class GenerateCadenceDraftsCommand extends MutationCommand
{
    protected $signature = 'svc:billing:generate-cadence-drafts
        {--workspace= : Only this workspace public UUID (default: every workspace)}
        {--limit=50 : Maximum drafts to create in this run}';

    protected $description = 'Create the draft for each recurring agreement whose current cadence period has started with no invoice';

    public function handle(UncoveredCadencePeriods $uncovered, CadencePeriodGuard $guard, ClientInvoicingService $cadence, WorkspaceClock $clock): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $only = $this->option('workspace');
        $workspaces = Workspace::query()->orderBy('id');
        if (is_string($only)) {
            $workspace = $this->workspaceByPublicId($only);
            if ($workspace === null) {
                $this->error('No workspace has that public UUID.');

                return self::INVALID;
            }
            $workspaces->whereKey($workspace->id);
        }

        /** @var list<array{Workspace, ClientAgreement, BillingCycle}> $due */
        $due = [];
        $remaining = 0;
        foreach ($workspaces->lazyById(100) as $workspace) {
            $scheduled = ClientBillingSchedule::query()->where('workspace_id', $workspace->id)->where('is_active', true)->pluck('client_agreement_id')->flip();
            $uncovered->each($workspace, $clock->today($workspace), function (ClientAgreement $agreement, BillingCycle $cycle) use ($workspace, $scheduled, $limit, &$due, &$remaining): void {
                if ($scheduled->has($agreement->id)) {
                    return;
                }
                if (count($due) < $limit) {
                    $due[] = [$workspace, $agreement, $cycle];
                } else {
                    $remaining++;
                }
            });
        }

        $generated = [];
        $failed = [];
        foreach ($due as [$workspace, $agreement, $cycle]) {
            $period = ['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id, 'period_start' => $cycle->start->toDateString(), 'period_end' => $cycle->end->toDateString()];
            if (! $this->applying()) {
                $generated[] = $period;

                continue;
            }
            try {
                $draft = DB::transaction(function () use ($guard, $cadence, $workspace, $agreement, $cycle) {
                    [$company, $record, $start] = $guard->lock($workspace, $agreement->public_id, $cycle->start->toDateString(), null);

                    return $cadence->generateCadencePeriod($company, $record, $start);
                });
                $generated[] = $period + ['invoice_id' => $draft->public_id, 'invoice_number' => $draft->invoice_number, 'total_amount' => (int) $draft->total_amount, 'currency' => $draft->currency];
            } catch (Throwable $refused) {
                // Covered between the read and the lock - an operator or agent
                // generated it first. That is the outcome this run wanted.
                if ($refused instanceof HttpExceptionInterface && $refused->getStatusCode() === 409) {
                    continue;
                }
                Log::warning('A scheduled cadence draft was not generated.', $period + ['exception' => $refused::class, 'reason' => $refused->getMessage()]);
                $failed[] = $period + ['reason' => $refused->getMessage()];
            }
        }

        $key = $this->applying() ? 'generated' : 'would_generate';
        if ($this->option('format') === 'json') {
            $this->json(['dry_run' => ! $this->applying(), $key => $generated, 'failed' => $failed, 'not_reached' => $remaining]);

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($generated as $row) {
            $this->components->twoColumnDetail($row['agreement_id'].' '.$row['period_start'], $row['invoice_number'] ?? 'would generate');
        }
        foreach ($failed as $row) {
            $this->components->twoColumnDetail($row['agreement_id'].' '.$row['period_start'], '<fg=red>'.$row['reason'].'</>');
        }
        $verb = $this->applying() ? 'Generated' : 'Would generate';
        $this->components->info("{$verb} ".count($generated).' cadence draft(s); '.count($failed).' refused; '.$remaining.' beyond --limit.');
        if (! $this->applying()) {
            $this->info('Dry run: nothing written. Re-run with --apply to create the drafts.');
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
