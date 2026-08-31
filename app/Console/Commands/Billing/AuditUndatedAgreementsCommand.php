<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\UndatedAgreementAuditor;
use App\Support\Billing\UndatedAgreementCounts;
use Illuminate\Console\Command;

/**
 * Report agreements with no start date, and the work they price (#147).
 *
 * A printer over {@see UndatedAgreementAuditor}, which owns every question this
 * answers - what counts, why the entry figures are a bracket rather than one
 * number, and why the contract must not be chosen before this runs.
 *
 * Counts only, enforced by the shape of {@see UndatedAgreementCounts}, so the
 * output is safe to paste into a public issue against real client records.
 *
 * It exits clean whatever it finds. #147 is explicit that no code should change
 * before these numbers exist, so this is a thing to read, not a gate.
 */
final class AuditUndatedAgreementsCommand extends Command
{
    protected $signature = 'svc:billing:audit-undated-agreements
        {--format=text : Output text or json}';

    protected $description = 'Count agreements with no start date, and the time entries and invoice lines they price';

    public function handle(UndatedAgreementAuditor $auditor): int
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

        $this->components->twoColumnDetail('Agreements', (string) $counts->agreements);
        $this->components->twoColumnDetail('With no start date, and able to price', (string) $counts->undated);
        $this->components->twoColumnDetail('... hourly-only', (string) $counts->hourlyOnly);
        $this->components->twoColumnDetail('... carrying retainer terms', (string) $counts->withRetainerTerms);

        $this->newLine();
        $this->line('  <fg=gray>Undated, by status</>');
        foreach ($counts->byStatus as $status => $count) {
            $this->components->twoColumnDetail('  '.$status, (string) $count);
        }

        $this->newLine();
        $this->line('  <fg=gray>Undated, by billing cadence</>');
        foreach ($counts->byCadence as $cadence => $count) {
            $this->components->twoColumnDetail('  '.$cadence, (string) $count);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Time entries one of them could price', (string) $counts->entriesWithAnUndatedCandidate);
        $this->components->twoColumnDetail('... with no dated agreement to outrank it', (string) $counts->entriesWithNoOtherCandidate);
        $this->components->twoColumnDetail('Invoice lines already billed against one', (string) $counts->billedLinesOnAnUndatedAgreement);

        $this->newLine();

        if ($counts->undated === 0) {
            $this->components->info(
                'Every agreement that can price work states when it starts. #147 is latent: the readers still disagree, but no row exercises the disagreement.'
            );

            return self::SUCCESS;
        }

        if ($counts->isLive()) {
            $this->components->warn(
                $counts->entriesWithNoOtherCandidate.' time entry/entries are priced by an agreement with no start date and nothing else eligible, so the rate resolver treats it as in force while the timesheet gives it no capacity and the date-based selectors drop it. Decide the contract in #147 before changing any of them; if these agreements mean "in force since always", backfill an explicit historical start date rather than keeping two meanings for the null.'
            );
        } else {
            $this->components->warn(
                'No entry is provably priced by an undated agreement, but '.$counts->entriesWithAnUndatedCandidate.' could be: a dated agreement is also eligible for each, and which one wins is decided by the resolver ordering rather than by anything asserted here. Read this as "not proven", not as "safe".'
            );
        }

        if ($counts->billedLinesOnAnUndatedAgreement > 0) {
            $this->components->warn(
                $counts->billedLinesOnAnUndatedAgreement.' invoice line(s) are already billed against an undated agreement, so the effective-date fallback has been depended on rather than merely reachable. Adopting the proposed contract does not unbill them; it changes what happens next time.'
            );
        }

        return self::SUCCESS;
    }
}
