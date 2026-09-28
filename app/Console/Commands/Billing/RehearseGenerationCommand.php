<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoiceLinePreview;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\RecordedOverage;
use App\Support\WorkspaceClock;
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
 *
 * `--company` narrows the run to one client and `--show` prints, before the
 * rollback, every draft the run would create or refresh - its lines, the
 * minutes each line links and the dates they span - with the client's capacity
 * ledger and the deferred work still carried forward. That is how an operator
 * reads next month's invoice off real data before generating it. `--show`
 * prints client billing detail, so it is for the host's own terminal, never
 * for pasting into an issue.
 */
final class RehearseGenerationCommand extends Command
{
    protected $signature = 'svc:billing:rehearse-generation
        {--workspace= : Required. Workspace public id to rehearse}
        {--company= : Only this client company (public id) in that workspace}
        {--show : Print each draft a real run would write, with its lines, linked minutes, ledger and deferred backlog}';

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

        $companyPublicId = $this->option('company');
        $companies = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->when(is_string($companyPublicId) && $companyPublicId !== '', fn ($query) => $query->where('public_id', $companyPublicId))
            ->get();

        if (is_string($companyPublicId) && $companyPublicId !== '' && $companies->isEmpty()) {
            $this->components->error('No client company in that workspace matches that public id.');

            return self::FAILURE;
        }

        $show = (bool) $this->option('show');
        /** @var list<string> $shown */
        $shown = [];

        $this->components->info(sprintf(
            'Rehearsing generation for %d client companies. Nothing will be written - the transaction is always rolled back.',
            $companies->count(),
        ));

        if ($companies->isEmpty()) {
            // Nothing was generated and nothing was compared, so "safe to run"
            // would be a claim about an empty set stated with the authority of
            // a check.
            $this->components->error(
                'This workspace has no client companies, so there is nothing to rehearse. '.
                'Select a workspace that holds the data you mean to test.',
            );

            return self::FAILURE;
        }

        $settledBefore = [];
        $created = 0;
        /** @var list<array{company: string, detail: string}> $failures */
        $failures = [];
        /** @var list<string> $withoutAgreement */
        $withoutAgreement = [];

        DB::beginTransaction();

        try {
            $settledBefore = $this->settledFingerprints($workspace);
            $this->components->twoColumnDetail('settled invoices being watched', (string) count($settledBefore));

            $before = DB::table('client_invoices')->where('workspace_id', $workspace->id)->count();

            $service = app(ClientInvoicingService::class);
            foreach ($companies as $company) {
                try {
                    $result = $service->generateAllInvoices($company);
                } catch (Throwable $e) {
                    $failures[] = ['company' => $company->public_id, 'detail' => $e->getMessage()];

                    continue;
                }

                // A period that threw does not reach the catch above.
                // `generateAllInvoicesForAgreement()` catches each period's
                // Throwable and returns it as a skip carrying an `error`, so
                // relying on exceptions alone let a company fail every period
                // it attempted and still be reported as proof the run is safe.
                foreach ($result['skipped'] as $skip) {
                    // Nothing a cadence can bill: reported, and not a failure.
                    if (($skip['reason_code'] ?? null) === ClientInvoicingService::SKIP_REASON_NO_AGREEMENT) {
                        $withoutAgreement[] = $company->public_id;

                        continue;
                    }

                    if (! isset($skip['error'])) {
                        continue;
                    }

                    $failures[] = [
                        'company' => $company->public_id,
                        'detail' => sprintf('%s: %s', $skip['period'] ?? 'unknown period', $skip['error']),
                    ];
                }

                if ($show) {
                    // Read before the rollback below discards what is shown.
                    $shown = array_merge($shown, $this->describe($company, $result['generated'], $result['updated']));
                }
            }

            $created = DB::table('client_invoices')->where('workspace_id', $workspace->id)->count() - $before;
            $settledAfter = $this->settledFingerprints($workspace);
        } finally {
            // Unconditional, exactly as in the replay.
            DB::rollBack();
        }

        $this->components->twoColumnDetail('invoices a real run would create', (string) $created);
        $this->components->twoColumnDetail('companies skipped with no billable agreement', (string) count($withoutAgreement));
        foreach ($withoutAgreement as $companyPublicId) {
            $this->line(sprintf('  skipped company %s - no agreement in force, ended, or starting within the month', $companyPublicId));
        }

        $changed = [];
        foreach ($settledBefore as $id => $fingerprint) {
            if (! isset($settledAfter[$id]) || $settledAfter[$id] !== $fingerprint) {
                $changed[] = $id;
            }
        }

        foreach ($shown as $line) {
            $this->line($line);
        }

        foreach ($failures as $failure) {
            $this->components->warn(sprintf('generation failed for company %s - %s', $failure['company'], $failure['detail']));
        }

        if ($failures !== []) {
            // Nothing generated means nothing was tested. Reporting "safe to
            // run" because every company threw would give the reader the
            // opposite of the truth, with the authority of a check.
            $this->components->error(sprintf(
                'Generation failed %d time(s) across %d companies, so this rehearsal proves nothing about '.
                'the periods that failed. Fix the failures and run it again.',
                count($failures),
                count(array_unique(array_column($failures, 'company'))),
            ));

            return self::FAILURE;
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
     * What a real run would leave for this company, as printable lines.
     *
     * @param  list<array<string, mixed>>  $generated
     * @param  list<array<string, mixed>>  $updated
     * @return list<string>
     */
    private function describe(ClientCompany $company, array $generated, array $updated): array
    {
        $out = ['', sprintf('Company %s (%s)', $company->public_id, $company->name)];

        $idsOf = static fn (array $rows): array => array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['invoice_id'] ?? 0),
            $rows,
        )));
        $createdIds = $idsOf($generated);
        $ids = array_merge($createdIds, $idsOf($updated));
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            $out[] = '  no draft would be created or refreshed';
        }
        $preview = app(InvoiceLinePreview::class)->forInvoices((int) $company->workspace_id, $invoices);

        foreach ($invoices as $invoice) {
            $out[] = sprintf(
                '  %s %s [%s, %s] issue %s | work %s..%s | sells %s..%s | subtotal %s | total %s | billed at rate %s h',
                in_array((int) $invoice->id, $createdIds, true) ? 'New' : 'Refreshed',
                $invoice->invoice_number,
                $invoice->invoiceKindValue(),
                $invoice->status,
                $invoice->issue_date?->toDateString() ?? '-',
                $invoice->service_period_start?->toDateString() ?? '-',
                $invoice->service_period_end?->toDateString() ?? '-',
                $invoice->cycle_start?->toDateString() ?? '-',
                $invoice->cycle_end?->toDateString() ?? '-',
                $this->money((int) $invoice->subtotal_amount, (string) $invoice->currency),
                $this->money((int) $invoice->total_amount, (string) $invoice->currency),
                $this->hours($invoice->hours_billed_at_rate),
            );

            foreach ($preview[(int) $invoice->id] ?? [] as ['line' => $line, 'time' => $time]) {
                $out[] = sprintf(
                    '    %2d. %-20s %s | %s h | qty %s x %s = %s | %s',
                    (int) $line->sort_order,
                    (string) $line->type,
                    (string) $line->description,
                    $this->hours($line->hours),
                    (string) $line->quantity,
                    $this->money((int) $line->unit_amount, (string) $invoice->currency),
                    $this->money((int) $line->total_amount, (string) $invoice->currency),
                    $time->describe(),
                );
            }
        }

        $agreements = ClientAgreement::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->whereIn('status', ['active', 'paused', 'terminated', 'expired'])
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();
        $through = app(WorkspaceClock::class)->today($company->workspace)->startOfMonth()->addMonthNoOverflow()->endOfMonth();

        foreach ($agreements as $agreement) {
            // Only charged invoices settle debt in the ledger, so this run's
            // own drafts are not in it yet: it is what they were sized against.
            $out[] = sprintf(
                '  Agreement %s (%s, from %s): capacity ledger, last 12 months, charged invoices only',
                $agreement->public_id,
                (string) $agreement->billing_cadence,
                $agreement->starts_on->toDateString(),
            );
            $ledger = app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough($company, $agreement, $through->toMutable());
            foreach (array_slice($ledger, -12) as $month) {
                $out[] = sprintf(
                    '    %s retainer %s | worked %s | billed overage %s | opening available %s | closing %s',
                    $month->yearMonth,
                    $this->hours($month->retainerHours),
                    $this->hours($month->hoursWorked),
                    $this->hours($month->billedOverageHours),
                    $this->hours($month->opening->totalAvailable),
                    $this->hours($month->closing->unusedHours + $month->closing->remainingRollover - $month->closing->negativeBalance),
                );
            }

            // Charged cadence invoices whose recorded overage disagrees with
            // the hourly lines it represents (see RecordedOverage). The ledger
            // reads only the recorded figure, so a mismatch moves every later
            // month.
            $charged = ClientInvoice::query()
                ->where('workspace_id', $company->workspace_id)
                ->where('client_company_id', $company->id)
                ->where('client_agreement_id', $agreement->id)
                ->whereIn('status', InvoiceStatus::charged())
                ->where(fn ($kind) => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
                ->orderBy('service_period_end')
                ->get();
            // Two queries for every charged invoice of the agreement: its
            // additional-hours lines, and which of them link deferred work.
            $overageLines = ClientInvoiceLine::query()
                ->where('workspace_id', $company->workspace_id)
                ->whereIn('client_invoice_id', $charged->pluck('id'))
                ->where('type', InvoiceLineType::AdditionalHours->value)
                ->get(['id', 'client_invoice_id', 'hours']);
            $linesLinkingDeferred = array_flip(DB::table('client_invoice_line_time_entries')
                ->join('client_time_entries', 'client_time_entries.id', '=', 'client_invoice_line_time_entries.client_time_entry_id')
                ->where('client_invoice_line_time_entries.workspace_id', $company->workspace_id)
                ->where('client_time_entries.workspace_id', $company->workspace_id)
                ->where('client_time_entries.is_deferred', true)
                ->whereIn('client_invoice_line_time_entries.client_invoice_line_id', $overageLines->pluck('id'))
                ->distinct()
                ->pluck('client_invoice_line_time_entries.client_invoice_line_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all());
            foreach ($charged as $invoice) {
                $lineHours = RecordedOverage::chargedHours($overageLines
                    ->where('client_invoice_id', $invoice->id)
                    ->map(static fn (ClientInvoiceLine $line): array => [
                        'hours' => (float) $line->hours,
                        'links_deferred' => isset($linesLinkingDeferred[(int) $line->id]),
                    ]));
                $recorded = $invoice->hours_billed_at_rate === null ? null : (float) $invoice->hours_billed_at_rate;
                if (RecordedOverage::disagrees($recorded, $lineHours)) {
                    $out[] = sprintf(
                        '    ! %s (work %s): records %s h billed at rate, its additional-hours lines charge %s h',
                        $invoice->invoice_number,
                        $invoice->service_period_end?->toDateString() ?? '-',
                        $recorded === null ? 'no' : $this->hours($recorded),
                        $this->hours($lineHours),
                    );
                }
            }

            $backlog = ClientTimeEntry::query()
                ->where('workspace_id', $company->workspace_id)
                ->where('client_company_id', $company->id)
                ->where('is_billable', true)
                ->where('is_deferred', true)
                ->unbilled()
                ->retainerBillable()
                ->forAgreementScope($agreement)
                ->get(['id', 'minutes', 'worked_on']);
            $out[] = $backlog->isEmpty()
                ? '  Deferred work carried forward: none'
                : sprintf(
                    '  Deferred work carried forward: %d min in %d entries, worked %s..%s',
                    (int) $backlog->sum('minutes'),
                    $backlog->count(),
                    (string) $backlog->min(fn (ClientTimeEntry $entry): string => $entry->worked_on->toDateString()),
                    (string) $backlog->max(fn (ClientTimeEntry $entry): string => $entry->worked_on->toDateString()),
                );
        }

        return $out;
    }

    private function money(int $minorUnits, string $currency): string
    {
        return sprintf('%s %s', $currency, number_format($minorUnits / 100, 2));
    }

    private function hours(mixed $hours): string
    {
        return number_format((float) $hours, 2);
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
