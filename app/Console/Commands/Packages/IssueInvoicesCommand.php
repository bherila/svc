<?php

namespace App\Console\Commands\Packages;

use App\Console\Commands\MutationCommand;
use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\Workspace;
use App\Services\Packages\PackageRecurringInvoices;
use App\Support\Packages\AutoInvoiceOutcome;
use App\Support\Packages\AutoInvoiceResult;
use Throwable;

/**
 * Invoices and issues each billing period of the package agreements set to
 * issue automatically (INV-1), never one Square collects (INV-3). Draft-mode
 * package agreements are not touched (INV-2).
 *
 * Every period goes through the same guard and cadence engine as
 * `invoices.generate_period` and `svc:billing:generate-cadence-drafts`, then
 * through the ordinary issue, so automatic delivery and autopay follow the
 * company's own settings ({@see PackageRecurringInvoices}). Each agreement's
 * period is decided in its own transaction, scoped to its own workspace, under
 * its own lock: running it twice, or twice at once, invoices a period once. A
 * refusal is recorded on the enrollment for Needs you, does not stop any other
 * agreement, and is tried again next run; one invoiced since another way is
 * marked covered.
 *
 * Runs in process from the scheduler; there is no queue worker. Previews by
 * default: without --apply it reports what it would do and keeps nothing.
 * While the feature is off it does nothing (X-1).
 */
final class IssueInvoicesCommand extends MutationCommand
{
    protected $signature = 'svc:packages:issue-invoices
        {--workspace= : Only this workspace public UUID (default: every workspace with an automatic package agreement)}
        {--limit=200 : Maximum invoices to issue in this run}';

    protected $description = 'Invoice and issue each due billing period of package agreements set to issue automatically';

    public function handle(PackageRecurringInvoices $recurring): int
    {
        $apply = (bool) $this->applying();
        if (! EnsureServicePackagesEnabled::enabled()) {
            return $this->report($apply, [], 0, 'Service packages are switched off; nothing was considered.');
        }
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $ids = $recurring->workspaceIds();
        $only = $this->option('workspace');
        if (is_string($only)) {
            $workspace = $this->workspaceByPublicId($only);
            if ($workspace === null) {
                $this->error('No workspace has that public UUID.');

                return self::INVALID;
            }
            $ids = array_values(array_intersect($ids, [$workspace->id]));
        }

        $outcomes = [];
        $issued = 0;
        $remaining = 0;
        foreach (Workspace::query()->whereIn('id', $ids)->orderBy('id')->get() as $workspace) {
            $enrollments = $recurring->automaticEnrollments($workspace);
            $due = $recurring->due($workspace, $enrollments);
            $reached = [];
            foreach ($due as $position => [$enrollment, $agreement, $cycle]) {
                // The limit counts invoices issued, not attempts: a refusal
                // keeps its place every run and must not hold later slots.
                if ($issued >= $limit) {
                    $remaining += count($due) - $position;

                    break;
                }
                $reached[$agreement->id] = true;
                try {
                    $result = $this->preview(fn (): AutoInvoiceResult => $recurring->invoice($workspace, $enrollment, $agreement, $cycle));
                } catch (Throwable $failure) {
                    report($failure);
                    $outcomes[] = $this->row($workspace, $enrollment->public_id, $cycle->start->toDateString(), 'failed', null, null);

                    continue;
                }
                if ($result->outcome === AutoInvoiceOutcome::Issued) {
                    $issued++;
                }
                $outcomes[] = $this->row($workspace, $enrollment->public_id, $cycle->start->toDateString(), $result->label(), $result->refusal?->value, $result->invoice?->invoice_number);
            }
            // A refusal recorded earlier whose period an owner has since
            // invoiced by hand: nothing is left for Needs you to ask.
            $unreached = array_diff_key($enrollments, $reached);
            foreach ($this->preview(fn (): array => $recurring->settleCovered($workspace, $unreached)) as $settled) {
                $outcomes[] = $this->row($workspace, $settled->public_id, (string) $settled->auto_invoice_period_start?->toDateString(), AutoInvoiceOutcome::Covered->value, null, null);
            }
        }

        return $this->report($apply, $outcomes, $remaining, null);
    }

    /** @return array{workspace_id: string, enrollment_id: string, period_start: string, outcome: string, refusal: string|null, invoice_number: string|null} */
    private function row(Workspace $workspace, string $enrollment, string $periodStart, string $outcome, ?string $refusal, ?string $invoiceNumber): array
    {
        return ['workspace_id' => $workspace->public_id, 'enrollment_id' => $enrollment, 'period_start' => $periodStart, 'outcome' => $outcome, 'refusal' => $refusal, 'invoice_number' => $invoiceNumber];
    }

    /** @param list<array{workspace_id: string, enrollment_id: string, period_start: string, outcome: string, refusal: string|null, invoice_number: string|null}> $outcomes */
    private function report(bool $apply, array $outcomes, int $remaining, ?string $note): int
    {
        $failed = array_filter($outcomes, static fn (array $row): bool => in_array($row['outcome'], [AutoInvoiceOutcome::Refused->value, 'failed'], true));
        if ($this->option('format') === 'json') {
            $this->json(['dry_run' => ! $apply, 'executed' => $apply, 'note' => $note, 'outcomes' => $outcomes, 'not_reached' => $remaining]);

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info(($apply ? 'Automatic invoicing run: ' : 'Automatic invoicing preview (re-run with --apply to keep it): ').count($outcomes).' period(s); '.$remaining.' beyond --limit.');
        if ($note !== null) {
            $this->line('  '.$note);
        }
        foreach ($outcomes as $row) {
            $this->line(sprintf('  %s  %s  %s  %s', $row['workspace_id'], $row['enrollment_id'], $row['period_start'], $row['outcome'].($row['refusal'] !== null ? ' ('.$row['refusal'].')' : '').($row['invoice_number'] !== null ? ' '.$row['invoice_number'] : '')));
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
