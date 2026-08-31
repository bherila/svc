<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\MissingBilledOverageAuditor;
use App\Support\Billing\MissingBilledOverageCounts;
use Illuminate\Console\Command;

/**
 * Report charged invoices carrying no billed-overage figure (#144).
 *
 * A printer over {@see MissingBilledOverageAuditor}, which owns every question
 * this answers - what counts as affected, why each stage of the funnel is
 * there, and why the fix is a decision rather than a patch.
 *
 * Counts only, enforced by the shape of
 * {@see MissingBilledOverageCounts}, so the output is safe
 * to paste into a public issue against real client billing records.
 */
final class AuditMissingBilledOverageCommand extends Command
{
    protected $signature = 'svc:billing:audit-missing-billed-overage
        {--format=text : Output text or json}';

    protected $description = 'Count charged invoices with no hours_billed_at_rate, and the agreements whose already-billed sums they corrupt';

    public function handle(MissingBilledOverageAuditor $auditor): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $counts = $auditor->count();

        if ($format === 'json') {
            $this->line((string) json_encode(
                ['summary' => $counts->toArray()],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            ));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Invoices', (string) $counts->invoices);
        $this->components->twoColumnDetail('With no billed-overage figure', (string) $counts->withoutABilledOverage);
        $this->components->twoColumnDetail('... of those, charged', (string) $counts->chargedOfThose);
        $this->components->twoColumnDetail('... of those, on an agreement in their workspace', (string) $counts->onAnAgreementOfThose);
        $this->newLine();
        $this->components->twoColumnDetail('Agreements whose already-billed sum is wrong', (string) $counts->agreementsAffected);
        $this->newLine();

        if ($counts->agreementsAffected === 0) {
            $this->components->info(
                'No charged invoice is missing its billed-overage figure, so every already-billed sum reads what was actually charged. #144 is latent.'
            );
        } else {
            $this->components->warn(
                $counts->agreementsAffected.' agreement(s) have an already-billed sum that reads short, because a charged invoice contributes nothing to it. '
                .'Overage those invoices carry can be sold again. This needs a decision, not a default: a null is not a quantity, so there is no value to coerce it to.'
            );
        }

        // Always a clean exit. It is a number to read, not a gate - and unlike
        // the unplaceable audit there is no guard already handling the bad
        // outcome, so the number is the whole point.
        return self::SUCCESS;
    }
}
