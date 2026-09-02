<?php

namespace App\Console\Commands\ExternalImport;

use App\Models\Workspace;
use App\Services\ExternalImport\SourceGuard;
use App\Services\ExternalImport\SupersededImportRepairer;
use App\Support\ExternalImport\SupersededImportCounts;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Retire the invoices and lines an earlier import took from source rows the
 * predecessor had already deleted.
 *
 * A printer and a safety catch over {@see SupersededImportRepairer}, which owns
 * the reasoning. This deletes billing records, so it does nothing until told
 * twice: `--apply` to write at all, and an interactive confirmation naming the
 * counts unless `--force`.
 *
 * ## One workspace at a time, always
 *
 * The repairer requires a workspace, so this iterates them and reports each in
 * turn rather than issuing one statement across every tenant. `--workspace`
 * narrows it to one, which is how an operator validates the correction on a
 * single client before letting it run everywhere - and a mistake is then
 * bounded by whoever it was scoped to.
 *
 * ## Take a snapshot first
 *
 * This is the one repair on this system that deletes rather than rewrites, and
 * nothing about a deleted row can be reconstructed from what remains. The
 * command refuses to apply without `--snapshot-taken`, which asserts nothing on
 * its own - it exists so that skipping the backup is a thing someone typed
 * rather than a thing they forgot.
 *
 * Counts only, enforced by {@see SupersededImportCounts}, so the output is safe
 * to paste into a public issue.
 */
final class RepairSupersededImportsCommand extends Command
{
    protected $signature = 'svc:import:repair-superseded
        {--source=external : Allowlisted read-only source key from config/external-import.php}
        {--workspace= : Repair one workspace by public id or slug; omit to walk every workspace in turn}
        {--apply : Write the repair; without this the command only reports what it would do}
        {--snapshot-taken : Confirm the invoice and invoice-line tables are backed up; required with --apply}
        {--force : Skip the confirmation prompt, for non-interactive runs}
        {--format=text : Output text or json}';

    protected $description = 'Delete invoices and lines imported from source rows the predecessor had deleted';

    public function handle(SourceGuard $guard, SupersededImportRepairer $repairer): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && ! (bool) $this->option('snapshot-taken')) {
            $this->error('Refusing to apply without --snapshot-taken. This repair deletes rows, and a deleted row '
                .'cannot be reconstructed from what remains. Back up client_invoices and client_invoice_lines first.');

            return self::FAILURE;
        }

        try {
            $source = $guard->resolve((string) $this->option('source'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $workspaces = $this->workspaces();

        if ($workspaces === null) {
            return self::INVALID;
        }

        $perWorkspace = [];
        $totals = ['eligible_invoices' => 0, 'eligible_lines' => 0, 'retired_invoices' => 0, 'retired_lines' => 0];
        $unreconciled = 0;

        foreach ($workspaces as $workspace) {
            // The dry run is the same code path, so what it reports is what the
            // write would do rather than a second implementation that agrees today.
            $preview = $repairer->repair($workspace, $source);

            if ($preview->isClean()) {
                continue;
            }

            $result = $preview;

            if ($apply && ($preview->eligibleInvoices > 0 || $preview->eligibleLines > 0)) {
                if (! (bool) $this->option('force') && ! $this->confirm(
                    "Permanently delete {$preview->eligibleInvoices} invoice(s) and {$preview->eligibleLines} line(s) in {$workspace->slug}?",
                    false,
                )) {
                    $this->comment("Skipped {$workspace->slug}: nothing written.");

                    continue;
                }

                $result = $repairer->repair($workspace, $source, apply: true);
            }

            $perWorkspace[$workspace->slug] = $result->toArray();
            $totals['eligible_invoices'] += $result->eligibleInvoices;
            $totals['eligible_lines'] += $result->eligibleLines;
            $totals['retired_invoices'] += $result->retiredInvoices;
            $totals['retired_lines'] += $result->retiredLines;
            $unreconciled += $result->survivorsNotReconciling;

            if ($format === 'text') {
                $this->line(sprintf(
                    '%s: eligible %d invoice(s) / %d line(s); retired %d / %d; skipped with a payment %d; survivors not reconciling %d',
                    $workspace->slug,
                    $result->eligibleInvoices, $result->eligibleLines,
                    $result->retiredInvoices, $result->retiredLines,
                    $result->skippedWithAPayment, $result->survivorsNotReconciling,
                ));
            }
        }

        if ($format === 'json') {
            $this->line((string) json_encode(['applied' => $apply, 'totals' => $totals, 'workspaces' => $perWorkspace], JSON_PRETTY_PRINT));
        } elseif ($perWorkspace === []) {
            $this->info('Nothing superseded, and every invoice reconciles.');
        } elseif (! $apply) {
            $this->comment('Preview only. Re-run with --apply --snapshot-taken to write.');
        }

        // A repair that ran and left an invoice not adding up has not finished,
        // and the next thing anyone does should not be running it somewhere
        // else. Reported as a failure exit so a script stops here.
        if ($apply && $unreconciled > 0) {
            $this->error("{$unreconciled} surviving invoice(s) still do not sum to their own subtotal. The superseded set was not the whole story.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Workspace>|null */
    private function workspaces(): ?Collection
    {
        $named = $this->option('workspace');

        if ($named === null || $named === '') {
            /** @var Collection<int, Workspace> $all */
            $all = Workspace::query()->orderBy('slug')->get();

            return $all;
        }

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()
            ->where('public_id', (string) $named)
            ->orWhere('slug', (string) $named)
            ->first();

        if ($workspace === null) {
            $this->error('No workspace matches that public id or slug.');

            return null;
        }

        return new Collection([$workspace]);
    }
}
