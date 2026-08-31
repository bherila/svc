<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
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
 * It reports the same class on `cycle_start`/`cycle_end` too (#141), because
 * that pair is nullable for the same reason and drops rows out of the same kind
 * of predicate. Two counts, because they endanger two different things.
 * `InterimOverageGenerator::cycleInvoices()` matches on both columns, so the
 * charged rows fall out of the already-billed subtraction and are charged
 * again - the money case - while the live rows are invisible to the duplicate
 * guards that refuse to create a second invoice for a cycle, which costs a
 * whole invoice rather than a wrong number.
 *
 * No fix is implied for the cycle columns, and none should be inferred from
 * this count. A null service period can be read fail-closed because the
 * question is which side of a window the row falls on. A null cycle cannot: the
 * question is which single cycle the row belongs to, and counting it in every
 * cycle would under-charge repeatedly rather than repair anything. Those rows
 * need a real value, which is what this command exists to find.
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
        $onAgreement = $this->onAnAgreementInItsOwnWorkspace(clone $charged);
        $affected = (clone $onAgreement)->where('hours_billed_at_rate', '!=', 0);

        // The cycle columns are the same class on different columns (#141), and
        // they endanger two different things, so they are counted twice.
        //
        // `InterimOverageGenerator::cycleInvoices()` matches on both, so a row
        // missing either is invisible to every caller. The charged funnel is
        // the money one: it feeds the already-billed subtraction and
        // `interimOverageHoursForCycle()`, and a row that drops out of those is
        // charged a second time. The live count is the guard one: the duplicate
        // checks that refuse to create a second invoice for a cycle read live
        // and settled statuses, and a row they cannot see costs a whole invoice
        // rather than a wrong number.
        $noCycle = ClientInvoice::query()
            ->where(function (Builder $missing): void {
                $missing->whereNull('cycle_start')->orWhereNull('cycle_end');
            });

        // Kind first, exactly as those lookups apply it. Running this audit
        // against real data is what put this condition here: all three
        // null-cycle rows in the replay corpus are ad-hoc, and no cycle lookup
        // reads an ad-hoc invoice, so reporting them as exposed would have been
        // an overcount of a population that is in fact empty.
        $readByCycle = (clone $noCycle)->where(function (Builder $kind): void {
            $kind->whereNull('invoice_kind')->orWhereIn('invoice_kind', InvoiceKind::matchedByCycle());
        });

        $liveNoCycle = $this->onAnAgreementInItsOwnWorkspace(
            (clone $readByCycle)->whereIn('status', InvoiceStatus::live()),
        );
        $chargedNoCycle = $this->onAnAgreementInItsOwnWorkspace(
            (clone $readByCycle)->whereIn('status', InvoiceStatus::charged()),
        );
        $cycleAffected = (clone $chargedNoCycle)->where('hours_billed_at_rate', '!=', 0);

        $summary = [
            'invoices' => ClientInvoice::query()->count(),
            'without_a_service_period' => $unplaceable->count(),
            'charged_of_those' => $charged->count(),
            'on_an_agreement_of_those' => $onAgreement->count(),
            'affected' => $affected->count(),
            'overage_hours_at_stake' => round((float) $affected->sum(DB::raw('abs(hours_billed_at_rate)')), 4),
            'without_a_cycle' => $noCycle->count(),
            'of_a_kind_read_by_cycle' => $readByCycle->count(),
            'live_without_a_cycle' => $liveNoCycle->count(),
            'cycle_affected' => $cycleAffected->count(),
            'cycle_overage_hours_at_stake' => round((float) $cycleAffected->sum(DB::raw('abs(hours_billed_at_rate)')), 4),
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
        $this->components->twoColumnDetail('Without a cycle start or end', (string) $summary['without_a_cycle']);
        $this->components->twoColumnDetail('... of those, of a kind matched by cycle', (string) $summary['of_a_kind_read_by_cycle']);
        $this->components->twoColumnDetail('... of those, live and on an agreement', (string) $summary['live_without_a_cycle']);
        $this->components->twoColumnDetail('... of those, charged and carrying overage', (string) $summary['cycle_affected']);
        $this->components->twoColumnDetail('Cycle overage hours at stake', (string) $summary['cycle_overage_hours_at_stake']);

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

        if ($summary['live_without_a_cycle'] === 0) {
            $this->components->info(
                'Every live invoice on an agreement can be matched to its cycle, so no duplicate guard is blind and no interim sum is short.'
            );
        } else {
            $this->components->warn(
                $summary['live_without_a_cycle'].' live invoice(s) name no cycle. The duplicate guards cannot see them, so a second invoice can be created for a cycle they already cover'
                .($summary['cycle_affected'] > 0
                    ? '; '.$summary['cycle_affected'].' of them carry overage a cadence invoice would then charge again.'
                    : '.')
            );
        }

        // Always a clean exit. This reports on data quality that the guard in
        // the overage sum already handles safely; it is a prompt to correct
        // rows, not a gate something is about to refuse.
        return self::SUCCESS;
    }

    /**
     * Narrow to invoices whose named agreement exists in their own workspace.
     *
     * Every sum and guard this command reports on filters agreement and
     * workspace together. `client_agreement_id` is unconstrained lineage, so a
     * row can name an agreement that has been deleted or one belonging to
     * another tenant; no sum ever reads such a row, and counting it would
     * overstate the population this command exists to bound.
     *
     * @param  Builder<ClientInvoice>  $invoices
     * @return Builder<ClientInvoice>
     */
    private function onAnAgreementInItsOwnWorkspace(Builder $invoices): Builder
    {
        return $invoices->whereExists(
            fn (QueryBuilder $query): QueryBuilder => $query
                ->select(DB::raw(1))
                ->from('client_agreements')
                ->whereColumn('client_agreements.id', 'client_invoices.client_agreement_id')
                ->whereColumn('client_agreements.workspace_id', 'client_invoices.workspace_id'),
        );
    }
}
