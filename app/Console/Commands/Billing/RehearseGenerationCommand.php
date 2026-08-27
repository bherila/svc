<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run a real generation against real data and roll it back, to answer one
 * question: would pressing "Generate Invoices" change anything a client has
 * already been charged for?
 *
 * This is not the replay. The replay blanks history and rebuilds it to ask
 * whether the engine reproduces the past. This changes nothing first: it runs
 * generation exactly as an operator would, on the data as it stands, and then
 * checks that every issued, partially paid, paid and void invoice is
 * byte-identical afterwards - every column, and every line.
 *
 * The distinction matters because four billing behaviours were deliberately
 * corrected in this port. Each changes what a period costs, which is intended
 * for work not yet billed and unacceptable for work already billed. The
 * generator's guard for that is `isImmutable()`; this asks the database rather
 * than trusting the guard.
 *
 * Always rolled back, like the replay. Nothing here is a migration step.
 */
final class RehearseGenerationCommand extends Command
{
    protected $signature = 'svc:billing:rehearse-generation
        {--workspace= : Required. Workspace public id to rehearse}';

    protected $description = 'Generate invoices in a rolled-back transaction and prove no settled invoice changed';

    public function handle(): int
    {
        $publicId = $this->option('workspace');
        if (! is_string($publicId) || $publicId === '') {
            $this->components->error('--workspace is required.');

            return self::FAILURE;
        }

        $workspace = Workspace::query()->where('public_id', $publicId)->first();
        if (! $workspace instanceof Workspace) {
            $this->components->error('No workspace matches that public id.');

            return self::FAILURE;
        }

        $companies = ClientCompany::query()->where('workspace_id', $workspace->id)->get();

        $this->components->info(sprintf(
            'Rehearsing generation for %d client companies. Nothing will be written - the transaction is always rolled back.',
            $companies->count(),
        ));

        $settledBefore = [];
        $created = 0;
        $failures = [];

        DB::beginTransaction();

        try {
            $settledBefore = $this->settledFingerprints($workspace);
            $this->components->twoColumnDetail('settled invoices being watched', (string) count($settledBefore));

            $before = DB::table('client_invoices')->where('workspace_id', $workspace->id)->count();

            $service = app(ClientInvoicingService::class);
            foreach ($companies as $company) {
                try {
                    $service->generateAllInvoices($company);
                } catch (Throwable $e) {
                    $failures[] = $company->public_id;
                }
            }

            $created = DB::table('client_invoices')->where('workspace_id', $workspace->id)->count() - $before;
            $settledAfter = $this->settledFingerprints($workspace);
        } finally {
            // Unconditional, exactly as in the replay.
            DB::rollBack();
        }

        $this->components->twoColumnDetail('invoices a real run would create', (string) $created);

        $changed = [];
        foreach ($settledBefore as $id => $fingerprint) {
            if (! isset($settledAfter[$id]) || $settledAfter[$id] !== $fingerprint) {
                $changed[] = $id;
            }
        }

        foreach ($failures as $companyPublicId) {
            $this->components->warn('generation failed for company '.$companyPublicId);
        }

        if ($changed !== []) {
            $this->components->error(sprintf(
                '%d settled invoice(s) would be modified by a generation run. An issued or paid invoice is a '.
                'statement the client has already seen; nothing may rewrite it.',
                count($changed),
            ));

            return self::FAILURE;
        }

        $this->components->info('No settled invoice was touched. Generation is safe to run against this data.');

        return self::SUCCESS;
    }

    /**
     * Every column and every line of each settled invoice, hashed.
     *
     * `updated_at` and `lock_version` are excluded: a no-op save would move them
     * without changing what the client owes, and the question here is about the
     * money and the statement, not about row bookkeeping.
     *
     * @return array<int, string>
     */
    private function settledFingerprints(Workspace $workspace): array
    {
        $invoices = DB::table('client_invoices')
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', InvoiceStatus::settled())
            ->orderBy('id')
            ->get();

        $fingerprints = [];

        foreach ($invoices as $invoice) {
            $row = (array) $invoice;
            unset($row['updated_at'], $row['lock_version']);

            $lines = DB::table('client_invoice_lines')
                ->where('client_invoice_id', $invoice->id)
                ->orderBy('id')
                ->get()
                ->map(static function (object $line): array {
                    $columns = (array) $line;
                    unset($columns['updated_at']);

                    return $columns;
                })
                ->all();

            $fingerprints[(int) $invoice->id] = hash('sha256', (string) json_encode([$row, $lines]));
        }

        return $fingerprints;
    }
}
