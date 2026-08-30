<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Count invoices whose service period cannot be placed on a calendar.
 *
 * `service_period_end` is nullable and stays that way (#73): an invoice can be
 * created by hand without one, and the external importer passes the source
 * value through unchanged. Everything downstream, though, decides which period
 * an invoice belongs to by comparing that column, and SQL comparison answers
 * false for a null rather than unknown. So an unplaceable invoice does not
 * raise, does not warn, and does not appear - it is quietly treated as being
 * outside whatever window is being asked about.
 *
 * The sum in `ClientInvoicingService::totalBilledOveragesThrough()` is where
 * that costs money. It totals the overage an agreement has already charged, so
 * the next period can avoid charging it twice; an invoice that drops out of it
 * gets billed again. That read is now fail-closed - a null period counts as
 * inside the window - which turns a double charge into capacity credited a
 * period early. Recoverable, but still an invoice placed by a fallback rather
 * than by a date anyone entered.
 *
 * Hence this command. The guard stops the bad outcome; this surfaces the rows
 * behind it so they can be given a real period instead of a defaulted one. Run
 * it after an import, and after any bulk invoice edit.
 *
 * It prints counts and aggregate hours only - never a row, an id, an invoice
 * number, a company, or a workspace. The output is safe to paste into a public
 * issue against a database of real client billing records.
 */
final class AuditUnplaceableInvoicesCommand extends Command
{
    protected $signature = 'svc:billing:audit-unplaceable-invoices
        {--format=text : Output text or json}';

    protected $description = 'Count invoices with no service period end, and how much billed overage they carry';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $unplaceable = ClientInvoice::query()->whereNull('service_period_end');

        // The same conditions the overage sum applies, in the same order. Each
        // one alone overstates: a draft has charged nobody; an invoice is only
        // summed against an agreement that exists in its own workspace, since
        // the sum filters on both keys and the agreement column is
        // unconstrained lineage that can dangle or cross tenants; and a row
        // with zero overage hours contributes nothing whichever side of the
        // window it lands on. Zero, not positive: negative hours move the sum
        // too - they shrink it - so the hours at stake are a magnitude, kept
        // from cancelling against the positive rows.
        $charged = (clone $unplaceable)->whereIn('status', InvoiceStatus::charged());
        $onAgreement = (clone $charged)->whereExists(
            fn (QueryBuilder $query): QueryBuilder => $query
                ->select(DB::raw(1))
                ->from('client_agreements')
                ->whereColumn('client_agreements.id', 'client_invoices.client_agreement_id')
                ->whereColumn('client_agreements.workspace_id', 'client_invoices.workspace_id'),
        );
        $affected = (clone $onAgreement)->where('hours_billed_at_rate', '!=', 0);

        $summary = [
            'invoices' => ClientInvoice::query()->count(),
            'without_a_service_period' => $unplaceable->count(),
            'charged_of_those' => $charged->count(),
            'on_an_agreement_of_those' => $onAgreement->count(),
            'affected' => $affected->count(),
            'overage_hours_at_stake' => round((float) $affected->sum(DB::raw('abs(hours_billed_at_rate)')), 4),
        ];

        if ($format === 'json') {
            // Zero fraction preserved: `overage_hours_at_stake` is hours, and a
            // consumer that reads 0 where every other run gives 0.0 has to
            // decide for itself which type this field is.
            $this->line((string) json_encode(
                ['summary' => $summary],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            ));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Invoices', (string) $summary['invoices']);
        $this->components->twoColumnDetail('Without a service period', (string) $summary['without_a_service_period']);
        $this->components->twoColumnDetail('... of those, charged', (string) $summary['charged_of_those']);
        $this->components->twoColumnDetail('... of those, on an agreement in their workspace', (string) $summary['on_an_agreement_of_those']);
        $this->components->twoColumnDetail('... of those, carrying overage', (string) $summary['affected']);
        $this->newLine();
        $this->components->twoColumnDetail('Overage hours at stake', (string) $summary['overage_hours_at_stake']);

        $this->newLine();

        if ($summary['affected'] === 0) {
            $this->components->info(
                'No charged overage is placed by fallback. Every invoice that feeds a billed-overage sum has a real service period.'
            );
        } else {
            $this->components->warn(
                $summary['affected'].' charged invoice(s) carry overage with no service period, and are counted as already billed by default rather than by date. Give them a period.'
            );
        }

        // Always a clean exit. This reports on data quality that the guard in
        // the overage sum already handles safely; it is a prompt to correct
        // rows, not a gate something is about to refuse.
        return self::SUCCESS;
    }
}
