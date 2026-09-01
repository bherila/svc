<?php

namespace App\Console\Commands\Billing;

use App\Models\Workspace;
use App\Services\Billing\UndatedCollectibleInvoiceRepairer;
use App\Support\Billing\EligibleSetChanged;
use App\Support\Billing\UndatedCollectibleInvoiceRepairCounts;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Give the undated collectible invoices the due date `issue()` would have (#149).
 *
 * A printer and a safety catch over {@see UndatedCollectibleInvoiceRepairer},
 * which owns the reasoning. This writes to billing records, so unlike the audit
 * commands beside it, it does nothing until told twice: `--apply` to write at
 * all, and an interactive confirmation naming the count unless `--force`.
 *
 * ## One workspace at a time, always
 *
 * The repairer requires a workspace, so this iterates them and reports each in
 * turn rather than issuing one statement across every tenant. `--workspace`
 * narrows it to one. An operator can then validate the correction on a single
 * client before letting it run everywhere, and a mistake is bounded by whoever
 * it was scoped to - neither of which is true of an unscoped update.
 *
 * Counts only, enforced by {@see UndatedCollectibleInvoiceRepairCounts}, so the
 * output is safe to paste into a public issue.
 */
final class BackfillInvoiceDueDatesCommand extends Command
{
    protected $signature = 'svc:billing:backfill-invoice-due-dates
        {--workspace= : Repair one workspace by public id or slug; omit to walk every workspace in turn}
        {--apply : Write the repair; without this the command only reports what it would do}
        {--force : Skip the confirmation prompt, for non-interactive runs}
        {--format=text : Output text or json}';

    protected $description = 'Set the due date of collectible invoices that have none to their issue date';

    public function handle(UndatedCollectibleInvoiceRepairer $repairer): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $workspaces = $this->workspaces();

        if ($workspaces === null) {
            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $totals = ['eligible' => 0, 'repaired' => 0, 'skipped' => 0];
        $perWorkspace = [];

        foreach ($workspaces as $workspace) {
            // The dry run is the same code path, so what it reports is what the
            // write would do rather than a second implementation that agrees today.
            $preview = $repairer->repair($workspace);

            if ($preview->eligible === 0 && $preview->skippedWithoutAnIssueDate === 0) {
                continue;
            }

            $result = $preview;

            if ($apply && $preview->eligible > 0) {
                if (! (bool) $this->option('force') && ! $this->confirm(
                    "Set the due date to the issue date on {$preview->eligible} collectible invoice(s) in {$workspace->slug}?",
                    false,
                )) {
                    $this->comment("Skipped {$workspace->slug}: nothing written.");

                    continue;
                }

                try {
                    // The approved count travels with the write. Between the
                    // preview's transaction closing and the answer arriving,
                    // the eligible set can move - a paid invoice refunded to
                    // partially paid is enough - and writing the larger set
                    // would act on approval nobody gave for it.
                    $result = $repairer->repair($workspace, apply: true, expected: $preview->eligible);
                } catch (EligibleSetChanged $changed) {
                    // In JSON mode this is the whole output, for the same
                    // reason the warning is suppressed there: a consumer of the
                    // advertised format must not be handed prose.
                    if ($format === 'json') {
                        $this->line((string) json_encode([
                            'applied' => false,
                            'aborted' => [
                                'workspace' => $workspace->slug,
                                'approved' => $changed->approved,
                                'found' => $changed->found,
                            ],
                        ]));
                    } else {
                        $this->error("{$workspace->slug}: {$changed->getMessage()}");
                    }

                    return self::FAILURE;
                }
            }

            $perWorkspace[$workspace->slug] = $result->toArray();
            $totals['eligible'] += $result->eligible;
            $totals['repaired'] += $result->repaired;
            $totals['skipped'] += $result->skippedWithoutAnIssueDate;
        }

        $this->report($format, $totals, $perWorkspace, $apply);

        return self::SUCCESS;
    }

    /**
     * The workspaces to walk, or null if `--workspace` names none.
     *
     * @return Collection<int, Workspace>|null
     */
    private function workspaces(): mixed
    {
        $named = $this->option('workspace');

        if ($named === null) {
            return Workspace::query()->orderBy('id')->get();
        }

        $workspace = Workspace::query()
            ->where('public_id', (string) $named)
            ->orWhere('slug', (string) $named)
            ->first();

        if (! $workspace instanceof Workspace) {
            $this->error('No workspace matches that public id or slug.');

            return null;
        }

        return collect([$workspace]);
    }

    /**
     * @param  array<string, int>  $totals
     * @param  array<string, array<string, int|bool>>  $perWorkspace
     */
    private function report(string $format, array $totals, array $perWorkspace, bool $apply): void
    {
        if ($format === 'json') {
            // Nothing but the object. A warning appended after it would make
            // the output unparseable for the scripts this format exists for,
            // and the count that would have warned is already in it.
            $this->line((string) json_encode([
                'applied' => $apply,
                'totals' => $totals,
                'workspaces' => $perWorkspace,
            ]));

            return;
        }

        $this->line("  Eligible (collectible, undated, with an issue date) ..... {$totals['eligible']}");
        $this->line("  Repaired ................................................ {$totals['repaired']}");
        $this->line("  Skipped (no issue date to date them from) ............... {$totals['skipped']}");
        $this->newLine();

        if ($totals['eligible'] === 0 && $totals['skipped'] === 0) {
            $this->info('No collectible invoice is missing a due date.');

            return;
        }

        $this->info($apply
            ? "Dated {$totals['repaired']} collectible invoice(s) from their issue date."
            : "Would date {$totals['eligible']} collectible invoice(s). Re-run with --apply to write.");

        // Emitted whenever the remainder is non-zero, including when nothing
        // was eligible. That case is the one an operator most needs told: every
        // affected invoice is undatable, so the run looks clean while the
        // problem it was called for is entirely unaddressed.
        if ($totals['skipped'] > 0) {
            $this->warn(
                "{$totals['skipped']} collectible invoice(s) carry no issue date either and were left alone; "
                .'there is no defensible due date for them, so #149 option (2) applies.',
            );
        }
    }
}
