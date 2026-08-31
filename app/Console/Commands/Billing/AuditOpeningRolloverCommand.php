<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientAgreement;
use Illuminate\Console\Command;

/**
 * Size the population #134 would change, before changing it.
 *
 * `InvoiceLedgerBuilder` seeds a month of opening capacity from an agreement's
 * initial rollover, and reads it from `initial_rollover_hours` - which is
 * neither a column nor an accessor, so the read is always null and the seed has
 * never been built. Correcting the read grants capacity that agreements do not
 * currently receive, which lowers overage on the next invoice they generate.
 * This command answers how many agreements that is before the correction lands.
 *
 * Three conditions have to hold together, and each one alone is misleading:
 *
 * 1. The agreement carries an initial rollover at all.
 * 2. It takes the legacy monthly branch. The seed sits after the cadence branch
 *    has already returned, so an agreement with period retainer terms never
 *    reaches it however large its initial rollover.
 * 3. It has a rollover policy. The seed month is carried into the first real
 *    month by `RolloverCalculator`, so with `rollover_months` unset or zero the
 *    capacity expires in the month it was granted and no invoice ever sees it.
 *
 * It prints counts and aggregate minutes only - never a row, an id, a name, a
 * company, or a workspace. The whole point is that it is safe to run against a
 * database of client and billing records and to paste the output into an issue.
 *
 * What it deliberately does not report is the change to any particular invoice.
 * That depends on how much of each month's capacity was actually used, which
 * cannot be read off the agreement, and an audit that guessed at it would be
 * offering a number nobody could check. Capacity at stake is the honest
 * measure: it is the ceiling on what the correction can move.
 */
final class AuditOpeningRolloverCommand extends Command
{
    protected $signature = 'svc:billing:audit-opening-rollover
        {--format=text : Output text or json}';

    protected $description = 'Count agreements whose ledger would change if the opening rollover seed were repaired';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $withRollover = ClientAgreement::query()->where('initial_rollover_minutes', '>', 0);

        // Read through the same column the accessors read, not through the
        // accessor: this has to be one query against the whole table rather
        // than a load of every agreement, and `retainer_hours` is derived.
        $legacyMonthly = (clone $withRollover)->whereNull('period_retainer_minutes');
        $affected = (clone $legacyMonthly)->where('rollover_months', '>', 0);

        $summary = [
            'agreements' => ClientAgreement::query()->count(),
            'with_initial_rollover' => (clone $withRollover)->count(),
            'legacy_monthly_of_those' => (clone $legacyMonthly)->count(),
            'affected' => (clone $affected)->count(),
            'capacity_at_stake_minutes' => (int) (clone $affected)->sum('initial_rollover_minutes'),
            'longest_rollover_months' => (int) ((clone $affected)->max('rollover_months') ?? 0),
        ];

        if ($format === 'json') {
            $this->line((string) json_encode(['summary' => $summary], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Agreements', (string) $summary['agreements']);
        $this->components->twoColumnDetail('With an initial rollover', (string) $summary['with_initial_rollover']);
        $this->components->twoColumnDetail('... of those, legacy monthly', (string) $summary['legacy_monthly_of_those']);
        $this->components->twoColumnDetail('... of those, with a rollover policy', (string) $summary['affected']);
        $this->newLine();
        $this->components->twoColumnDetail('Capacity at stake (minutes)', (string) $summary['capacity_at_stake_minutes']);
        $this->components->twoColumnDetail('Longest rollover policy (months)', (string) $summary['longest_rollover_months']);

        $this->newLine();

        if ($summary['affected'] === 0) {
            $this->components->info(
                'No agreement would change. The repair is a latent-defect fix with no effect on what any client is charged.'
            );
        } else {
            $this->components->warn(
                $summary['affected'].' agreement(s) would receive opening capacity they do not currently get, lowering overage on the next invoice each generates.'
            );
        }

        // Always a clean exit. Unlike the tenant-key audit this is not a gate on
        // anything - a non-zero count here is a number to read before merging
        // #134, not a database in a state something is about to refuse.
        return self::SUCCESS;
    }
}
