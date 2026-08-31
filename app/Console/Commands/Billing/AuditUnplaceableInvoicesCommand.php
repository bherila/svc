<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\UnplaceableInvoiceAuditor;
use App\Support\Billing\UnplaceableInvoiceCounts;
use Illuminate\Console\Command;

/**
 * Report invoices whose period or cycle cannot be placed on a calendar.
 *
 * A printer, deliberately. Every question this answers lives in
 * {@see UnplaceableInvoiceAuditor}, because the counting is the durable part
 * and an operator screen should show the same numbers rather than re-deriving
 * them from a second copy of the funnel that can drift from this one.
 *
 * What the counts mean, why each stage of the funnel is there, and why no fix
 * is implied for the cycle columns are all documented on that service.
 *
 * It prints counts and aggregate hours only - never a row, an id, an invoice
 * number, a company, or a workspace. That is enforced by the shape of
 * {@see UnplaceableInvoiceCounts} rather than by care
 * taken here, so the output is safe to paste into a public issue against a
 * database of real client billing records.
 */
final class AuditUnplaceableInvoicesCommand extends Command
{
    protected $signature = 'svc:billing:audit-unplaceable-invoices
        {--format=text : Output text or json}';

    protected $description = 'Count invoices with no service period end, and how much billed overage they carry';

    public function handle(UnplaceableInvoiceAuditor $auditor): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        // Unscoped: an operator sizing this needs every workspace at once. A
        // tenant-facing caller passes its own workspace instead.
        $counts = $auditor->count();
        $summary = $counts->toArray();

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

        $this->components->twoColumnDetail('Invoices', (string) $counts->invoices);
        $this->components->twoColumnDetail('Without a service period', (string) $counts->withoutAServicePeriod);
        $this->components->twoColumnDetail('... of those, charged', (string) $counts->chargedOfThose);
        $this->components->twoColumnDetail('... of those, on an agreement in their workspace', (string) $counts->onAnAgreementOfThose);
        $this->components->twoColumnDetail('... of those, carrying overage', (string) $counts->affected);
        $this->newLine();
        $this->components->twoColumnDetail('Overage hours at stake', (string) $counts->overageHoursAtStake);

        $this->newLine();
        $this->components->twoColumnDetail('Without a cycle start or end', (string) $counts->withoutACycle);
        $this->components->twoColumnDetail('... of those, of a kind matched by cycle', (string) $counts->ofAKindReadByCycle);
        $this->components->twoColumnDetail('... of those, live and on an agreement', (string) $counts->liveWithoutACycle);
        $this->components->twoColumnDetail('... of those, charged and carrying overage', (string) $counts->cycleAffected);
        $this->components->twoColumnDetail('Cycle overage hours at stake', (string) $counts->cycleOverageHoursAtStake);

        $this->newLine();

        if ($counts->affected === 0) {
            $this->components->info(
                'No charged overage is placed by fallback. Every invoice that feeds a billed-overage sum has a real service period.'
            );
        } else {
            $this->components->warn(
                $counts->affected.' charged invoice(s) carry overage with no service period, and are counted as already billed by default rather than by date. Give them a period.'
            );
        }

        if ($counts->liveWithoutACycle === 0) {
            $this->components->info(
                'Every live invoice on an agreement can be matched to its cycle, so no duplicate guard is blind and no interim sum is short.'
            );
        } else {
            $this->components->warn(
                $counts->liveWithoutACycle.' live invoice(s) name no cycle. The duplicate guards cannot see them, so a second invoice can be created for a cycle they already cover'
                .($counts->cycleAffected > 0
                    ? '; '.$counts->cycleAffected.' of them carry overage a cadence invoice would then charge again.'
                    : '.')
            );
        }

        // Always a clean exit. This reports on data quality that the guard in
        // the overage sum already handles safely; it is a prompt to correct
        // rows, not a gate something is about to refuse.
        return self::SUCCESS;
    }
}
