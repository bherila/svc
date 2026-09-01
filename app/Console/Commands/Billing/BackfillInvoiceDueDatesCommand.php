<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\UndatedCollectibleInvoiceRepairer;
use App\Support\Billing\UndatedCollectibleInvoiceRepairCounts;
use Illuminate\Console\Command;

/**
 * Give the undated collectible invoices the due date `issue()` would have (#149).
 *
 * A printer and a safety catch over {@see UndatedCollectibleInvoiceRepairer},
 * which owns the reasoning. This writes to billing records, so unlike the audit
 * commands beside it, it does nothing until told twice: `--apply` to write at
 * all, and an interactive confirmation naming the count unless `--force`.
 *
 * Counts only, enforced by {@see UndatedCollectibleInvoiceRepairCounts}, so the
 * output is safe to paste into a public issue.
 */
final class BackfillInvoiceDueDatesCommand extends Command
{
    protected $signature = 'svc:billing:backfill-invoice-due-dates
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

        $apply = (bool) $this->option('apply');

        // The dry run is the same code path, so what it reports is what the
        // write would do rather than a second implementation that agrees today.
        $preview = $repairer->repair(apply: false);

        if ($preview->eligible === 0) {
            $this->render($format, $preview, 'No collectible invoice is missing a due date.');

            return self::SUCCESS;
        }

        if ($apply && ! (bool) $this->option('force') && ! $this->confirm(
            "Set the due date to the issue date on {$preview->eligible} collectible invoice(s)?",
            false,
        )) {
            $this->render($format, $preview, 'Nothing written.');

            return self::SUCCESS;
        }

        $result = $apply ? $repairer->repair(apply: true) : $preview;

        $this->render($format, $result, $apply
            ? "Dated {$result->repaired} collectible invoice(s) from their issue date."
            : "Would date {$result->eligible} collectible invoice(s). Re-run with --apply to write.");

        if ($result->leavesAnUndatedRemainder()) {
            $this->warn(
                "{$result->skippedWithoutAnIssueDate} collectible invoice(s) carry no issue date either and were left alone; "
                .'there is no defensible due date for them, so #149 option (2) applies.',
            );
        }

        return self::SUCCESS;
    }

    private function render(string $format, UndatedCollectibleInvoiceRepairCounts $counts, string $summary): void
    {
        if ($format === 'json') {
            $this->line((string) json_encode($counts->toArray()));

            return;
        }

        $this->line("  Eligible (collectible, undated, with an issue date) ..... {$counts->eligible}");
        $this->line("  Repaired ................................................ {$counts->repaired}");
        $this->line("  Skipped (no issue date to date them from) ............... {$counts->skippedWithoutAnIssueDate}");
        $this->newLine();
        $this->info($summary);
    }
}
