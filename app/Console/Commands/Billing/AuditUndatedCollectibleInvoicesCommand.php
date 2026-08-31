<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\UndatedCollectibleInvoiceAuditor;
use App\Support\Billing\UndatedCollectibleInvoiceCounts;
use Illuminate\Console\Command;

/**
 * Report collectible invoices that no overdue figure can include (#149).
 *
 * A printer over {@see UndatedCollectibleInvoiceAuditor}, which owns every
 * question this answers - what counts as collectible, why the population is
 * split by issue date, and why `orWhereNull` is the wrong fix.
 *
 * Counts and balances only, enforced by the shape of
 * {@see UndatedCollectibleInvoiceCounts}, so the output is safe to paste into a
 * public issue against real client billing records.
 *
 * It exits clean whatever it finds. The figures it reports on are wrong in a way
 * that understates rather than overcharges, and #149 is explicit that the repair
 * is a data one; this is a number to read, not a gate.
 */
final class AuditUndatedCollectibleInvoicesCommand extends Command
{
    protected $signature = 'svc:billing:audit-undated-collectible-invoices
        {--format=text : Output text or json}';

    protected $description = 'Count collectible invoices with no due date, which appear in collectible balances but in no overdue figure';

    public function handle(UndatedCollectibleInvoiceAuditor $auditor): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        // Unscoped: an operator sizing this needs every workspace at once. A
        // tenant-facing caller passes its own workspace instead.
        $counts = $auditor->count();

        if ($format === 'json') {
            $this->line((string) json_encode(
                ['summary' => $counts->toArray()],
                JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Invoices', (string) $counts->invoices);
        $this->components->twoColumnDetail('Collectible', (string) $counts->collectible);
        $this->components->twoColumnDetail('... of those, with no due date', (string) $counts->undated);
        $this->components->twoColumnDetail('... ... datable from their issue date', (string) $counts->withAnIssueDate);
        $this->components->twoColumnDetail('... ... with no defensible date at all', (string) $counts->withoutAnIssueDate);

        $this->newLine();
        $this->line('  <fg=gray>Undated balance, by currency</>');
        foreach ($counts->undatedBalances as $currency => $balance) {
            $this->components->twoColumnDetail('  '.$currency, (string) $balance);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Would become overdue if backfilled', (string) $counts->wouldBecomeOverdueIfBackfilled);
        foreach ($counts->wouldBecomeOverdueBalances as $currency => $balance) {
            $this->components->twoColumnDetail('  '.$currency, (string) $balance);
        }

        $this->newLine();

        if (! $counts->isLive()) {
            $this->components->info(
                'Every collectible invoice states a due date, so the collectible and overdue figures are asking the same question of the same rows. #149 is latent.'
            );

            return self::SUCCESS;
        }

        $this->components->warn(
            $counts->undated.' collectible invoice(s) have no due date. They are counted in collectible balances and excluded from every overdue figure, so the two disagree and nothing says why.'
        );

        // Stated even when the count is zero, because the reader arriving at
        // this command may well have arrived intending to write `orWhereNull`.
        $this->components->warn(
            'Do not count a null as overdue. That fix was right for #135, which was fail-closed against charging a client twice; here it would move invoices into a collections-adjacent report on no evidence, and an invoice with no stated term is not self-evidently late.'
        );

        if ($counts->withAnIssueDate > 0) {
            $this->components->info(
                $counts->withAnIssueDate.' of them carry an issue date, so they can be dated exactly as `InvoiceLifecycleService::issue()` would have dated them had they gone through it - which repairs the data rather than special-casing the query. '
                .$counts->wouldBecomeOverdueIfBackfilled.' would land in overdue reporting immediately; check the balances above before approving that.'
            );
        }

        if ($counts->withoutAnIssueDate > 0) {
            $this->components->warn(
                $counts->withoutAnIssueDate.' carry no issue date either, so no backfill can date them honestly. These are the population a separate undated-collectible bucket exists for: report them rather than absorbing them into a figure that is silently wrong.'
            );
        }

        return self::SUCCESS;
    }
}
