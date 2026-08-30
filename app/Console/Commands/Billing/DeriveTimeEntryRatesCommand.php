<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Billing\AgreementBillingRateResolver;
use DomainException;
use Illuminate\Console\Command;

/**
 * Gives imported time entries the billing rate SVC needs to invoice them.
 *
 * The source system stored no per-entry client rate — it lived on the agreement
 * — so every imported entry has a null rate, and `InvoiceFromTimeService`
 * refuses an entry without one. Nothing imported can currently be invoiced.
 *
 * Two deliberate limits:
 *
 * - Only unallocated time is touched. An entry already on an invoice line was
 *   billed at whatever that line charged, and the line is the record of it.
 *   Retainer draw-downs carry a zero unit price, so the line cannot be read
 *   back as an hourly rate either. Writing a rate onto invoiced history would
 *   invent a number that was never charged.
 * - Every rate written is stamped `agreement`, so an inferred figure stays
 *   distinguishable from one that was actually recorded.
 */
final class DeriveTimeEntryRatesCommand extends Command
{
    protected $signature = 'svc:billing:derive-time-rates
        {--workspace= : Workspace public id to repair (required; rates are never derived across tenants)}
        {--include-deferred : Also resolve deferred entries, which cannot be invoiced until released}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Resolve the agreement billing rate for imported time entries that have none';

    public function handle(AgreementBillingRateResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = ClientTimeEntry::query()
            ->whereNull('billing_rate_amount')
            ->where('is_billable', true)
            ->retainerBillable()
            ->whereDoesntHave('invoiceLines');

        if (! $this->option('include-deferred')) {
            $query->where('is_deferred', false);
        }

        // Deliberately required. Repairing one onboarding must never reach into
        // another tenant's billing data.
        $workspacePublicId = $this->option('workspace');
        if (! is_string($workspacePublicId) || $workspacePublicId === '') {
            $this->components->error('A --workspace is required so rates are never derived across tenants.');

            return self::FAILURE;
        }

        $workspace = Workspace::query()->where('public_id', $workspacePublicId)->first();
        if (! $workspace instanceof Workspace) {
            $this->components->error('No workspace matches that public id.');

            return self::FAILURE;
        }
        $query->where('workspace_id', $workspace->id);

        $eligible = (clone $query)->count();
        if ($eligible === 0) {
            $this->components->info('Every eligible time entry already has a rate.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d entries need a rate.%s',
            $eligible,
            $dryRun ? ' Dry run - nothing will be written.' : '',
        ));

        $resolved = 0;
        $unresolved = [];

        $query->orderBy('id')->chunkById(200, function ($entries) use ($resolver, $dryRun, &$resolved, &$unresolved): void {
            foreach ($entries as $entry) {
                try {
                    $rate = $resolver->resolve($entry);
                } catch (DomainException $e) {
                    // No agreement covers this entry's worked-on date. That is a real
                    // gap in the record, not a failure here - surface it and move on.
                    $unresolved[] = $entry->public_id;

                    continue;
                }

                $resolved++;
                if (! $dryRun) {
                    // Amount and currency are resolved as one pair. Keeping the
                    // entry's old currency beside the agreement's amount would
                    // mislabel the money and invoice it in the wrong currency.
                    $entry->forceFill([
                        'billing_rate_amount' => $rate['amount'],
                        'billing_rate_source' => 'agreement',
                        'currency' => $rate['currency'],
                    ])->save();
                }
            }
        });

        $this->components->twoColumnDetail('resolved from an agreement', (string) $resolved);
        $this->components->twoColumnDetail('no agreement in force', (string) count($unresolved));

        if ($unresolved !== []) {
            $this->components->warn('These entries have no agreement covering their worked-on date and still cannot be invoiced:');
            foreach (array_slice($unresolved, 0, 20) as $publicId) {
                $this->line("  {$publicId}");
            }
            if (count($unresolved) > 20) {
                $this->line(sprintf('  ... and %d more', count($unresolved) - 20));
            }
        }

        return self::SUCCESS;
    }
}
