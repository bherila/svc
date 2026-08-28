<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair invoice periods that begin where another invoice's period ended.
 *
 * A service period here is the closed interval `[first, last]`: 1 January to
 * 31 January covers January, and February begins on the 1st. The predecessor
 * did not always agree. For one two-month run it set each period to start on
 * the previous invoice's end date, so consecutive invoices shared a boundary
 * day, and the import copied that across verbatim.
 *
 * The consequence is not cosmetic. `assertNoOverlappingInvoice()` treats a
 * shared endpoint as an overlap - correctly, under a closed interval - so every
 * attempt to generate those months is refused. Against the migrated data this
 * was the single largest cause of divergence in `svc:billing:replay`:
 * 11 of 13 unexplained invoices regenerated completely empty, and every one of
 * the generator's stated reasons was this overlap.
 *
 * ## It reports by default
 *
 * Like {@see BackfillBillingLedgerCommand}, and for the same reason: this points
 * at production data, and a run meant as a look should not become a write
 * because an option was forgotten. `--apply` writes, and the whole repair is one
 * transaction.
 *
 * ## Settled invoices are not touched without being asked for
 *
 * Every affected invoice is likely to be issued or paid, because the defect is
 * historical. Moving a service period by a day changes no money and no line -
 * it corrects which work the invoice says it reconciled - but rewriting a
 * settled invoice is forbidden everywhere else here, so it is not done silently.
 * They are counted, named by public id, and left alone unless
 * `--include-settled` says otherwise.
 */
final class NormalizeInvoicePeriodsCommand extends Command
{
    protected $signature = 'svc:billing:normalize-invoice-periods
        {--workspace= : Required. Workspace public id}
        {--apply : Write the repairs. Without it the command reports and writes nothing}
        {--include-settled : Also repair issued, part-paid and paid invoices}';

    protected $description = 'Advance invoice periods that start on another invoice\'s end date';

    public function handle(): int
    {
        $workspace = Workspace::query()
            ->where('public_id', (string) $this->option('workspace'))
            ->first();

        if (! $workspace instanceof Workspace) {
            $this->components->error('No workspace matches that public id.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $includeSettled = (bool) $this->option('include-settled');

        $this->components->info($apply
            ? 'Repairing invoice periods.'
            : 'Reporting only - nothing will be written. Pass --apply to write.');

        $affected = $this->affected($workspace);

        if ($affected === []) {
            $this->components->info('Every invoice period begins after the one before it. Nothing to repair.');

            return self::SUCCESS;
        }

        $settled = array_filter($affected, static fn (ClientInvoice $i): bool => InvoiceStatus::isSettledValue($i->status));
        $open = array_filter($affected, static fn (ClientInvoice $i): bool => ! InvoiceStatus::isSettledValue($i->status));

        $this->components->twoColumnDetail('periods starting on another invoice\'s end date', (string) count($affected));
        $this->components->twoColumnDetail('  of those, settled', (string) count($settled));

        $toRepair = $includeSettled ? $affected : $open;

        if ($settled !== [] && ! $includeSettled) {
            $this->components->warn(sprintf(
                '%d settled invoice(s) carry the defect and are being left alone. Moving a service period '.
                'changes no money, but rewriting a settled invoice is refused everywhere else here, so it is '.
                'not done without being asked: re-run with --include-settled.',
                count($settled),
            ));

            foreach ($settled as $invoice) {
                $this->components->twoColumnDetail('  '.$invoice->public_id, (string) $invoice->status);
            }
        }

        if ($toRepair === []) {
            return self::SUCCESS;
        }

        // One transaction, so a repair that cannot be completed is not half
        // applied. Rolled back unless --apply asked for the write.
        DB::beginTransaction();

        try {
            foreach ($toRepair as $invoice) {
                $invoice->forceFill([
                    'service_period_start' => $invoice->service_period_start->copy()->addDay(),
                ])->save();
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if (! $apply) {
            DB::rollBack();
            $this->components->twoColumnDetail('periods that would move forward one day', (string) count($toRepair));

            return self::SUCCESS;
        }

        DB::commit();
        $this->components->twoColumnDetail('periods moved forward one day', (string) count($toRepair));

        return self::SUCCESS;
    }

    /**
     * Invoices whose period starts on the end date of another invoice.
     *
     * Matched within a company, and within an agreement when both carry one:
     * two agreements for the same client bill independently, and their periods
     * may legitimately abut. The invoice is never compared against itself, and a
     * void invoice reserves nothing.
     *
     * @return list<ClientInvoice>
     */
    private function affected(Workspace $workspace): array
    {
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('service_period_start')
            ->whereNotNull('service_period_end')
            ->where('status', '!=', 'void')
            ->orderBy('id')
            ->get();

        $ends = [];
        foreach ($invoices as $invoice) {
            $key = $invoice->client_company_id.'|'.($invoice->client_agreement_id ?? 'none');
            $ends[$key][$invoice->service_period_end->toDateString()][] = $invoice->id;
        }

        $affected = [];
        foreach ($invoices as $invoice) {
            $key = $invoice->client_company_id.'|'.($invoice->client_agreement_id ?? 'none');
            $sharing = $ends[$key][$invoice->service_period_start->toDateString()] ?? [];

            if (array_diff($sharing, [$invoice->id]) !== []) {
                $affected[] = $invoice;
            }
        }

        return $affected;
    }
}
