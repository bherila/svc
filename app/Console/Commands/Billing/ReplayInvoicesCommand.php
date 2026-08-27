<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceKind;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Regenerate historical invoices and compare them against what was billed.
 *
 * The billing engine was ported from another system. Its unit tests prove the
 * arithmetic still produces the predecessor's worked examples, but those are
 * hand-built fixtures. This answers the question they cannot: does the ported
 * engine, given the real agreements and the real time entries, still produce the
 * invoices the client actually received?
 *
 * ## It cannot write anything
 *
 * Replaying means deleting every invoice and regenerating it, which is
 * obviously unacceptable against real data. So the whole run happens inside one
 * transaction that is rolled back unconditionally - on success exactly as on
 * failure. There is no code path that commits. The generator's own
 * `DB::transaction()` calls nest as savepoints inside it, so its commits are
 * subsumed by the outer rollback too.
 *
 * That is what makes this safe to point at production data. Do not add a
 * `--commit` option; if a divergence needs fixing, fix the engine and replay
 * again.
 *
 * ## What counts as a failure
 *
 * Money is exact. A difference of one minor unit in any line or total is a
 * divergence and the command exits non-zero.
 *
 * Hours are reported but do not fail the run. The source stored fractional hours
 * directly; this schema derives them from whole minutes, and 197 of the 771
 * source lines do not divide evenly. Differences there are expected
 * representation noise and need a human read, not a gate.
 *
 * Ad-hoc invoices are excluded from the comparison entirely. They were typed by
 * an operator rather than generated, so no generator will reproduce them; they
 * are counted and set aside.
 *
 * ## Replaying as of a date
 *
 * Generation walks cycles up to the present, but history stops wherever the
 * last invoice was issued. Replaying against the real clock therefore invents an
 * invoice for every cycle since, and the genuine divergences drown in them. The
 * clock is pinned instead - by default to the newest cycle history contains - so
 * the engine is asked for exactly the set that should exist.
 */
final class ReplayInvoicesCommand extends Command
{
    protected $signature = 'svc:billing:replay
        {--workspace= : Required. Workspace public id to replay}
        {--company= : Restrict to one client company public id}
        {--as-of= : Replay as the system saw it on this date; defaults to the newest historical cycle end}
        {--report= : Write per-invoice detail to this file; stdout stays aggregate}';

    protected $description = 'Regenerate historical invoices in a rolled-back transaction and diff them against what was billed';

    public function handle(): int
    {
        $workspacePublicId = $this->option('workspace');
        if (! is_string($workspacePublicId) || $workspacePublicId === '') {
            $this->components->error('--workspace is required.');

            return self::FAILURE;
        }

        $workspace = Workspace::query()->where('public_id', $workspacePublicId)->first();
        if (! $workspace instanceof Workspace) {
            $this->components->error('No workspace matches that public id.');

            return self::FAILURE;
        }

        $companies = $this->companies($workspace);
        if ($companies->isEmpty()) {
            $this->components->error('No client companies to replay.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Replaying %d client compan%s. Nothing will be written - the transaction is always rolled back.',
            $companies->count(),
            $companies->count() === 1 ? 'y' : 'ies',
        ));

        /** @var array{comparisons: list<array<string, mixed>>, generation: list<string>} $outcome */
        $outcome = ['comparisons' => [], 'generation' => []];

        DB::beginTransaction();

        try {
            $expected = $this->snapshot($workspace, $companies);
            $this->components->twoColumnDetail('invoices captured', (string) count($expected));

            $skippedAdHoc = count(array_filter(
                $expected,
                static fn (array $row): bool => $row['invoice_kind'] === InvoiceKind::AdHoc->value,
            ));
            if ($skippedAdHoc > 0) {
                $this->components->twoColumnDetail('ad-hoc, not machine-generated', (string) $skippedAdHoc);
            }

            $asOf = $this->asOf($workspace);
            $this->components->twoColumnDetail('replaying as of', $asOf->toDateString());

            $this->clear($workspace, $companies);

            // Pinned for the duration so cycle walks stop where history does.
            Carbon::setTestNow($asOf);

            try {
                $service = app(ClientInvoicingService::class);
                foreach ($companies as $company) {
                    try {
                        $service->generateAllInvoices($company);
                    } catch (Throwable $e) {
                        // One company that cannot generate must not hide the
                        // comparison for the others.
                        $outcome['generation'][] = sprintf('%s: %s', $company->public_id, $e->getMessage());
                    }
                }
            } finally {
                Carbon::setTestNow();
            }

            $outcome['comparisons'] = $this->compare($expected, $this->snapshot($workspace, $companies));
        } finally {
            // Unconditional. This is the whole safety model.
            DB::rollBack();
        }

        return $this->report($outcome);
    }

    /**
     * @return Collection<int, ClientCompany>
     */
    private function companies(Workspace $workspace): Collection
    {
        $query = ClientCompany::query()->where('workspace_id', $workspace->id);

        if (is_string($companyPublicId = $this->option('company')) && $companyPublicId !== '') {
            $query->where('public_id', $companyPublicId);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Every invoice keyed by what identifies it independently of its number.
     *
     * Invoice numbers are not usable as the key: regeneration allocates fresh
     * ones, so matching on them would report every invoice as both missing and
     * unexpected. The cycle a retainer was sold for, or failing that the work
     * period reconciled, is what actually identifies an invoice.
     *
     * @param  Collection<int, ClientCompany>  $companies
     * @return array<string, array<string, mixed>>
     */
    private function snapshot(Workspace $workspace, Collection $companies): array
    {
        $rows = [];

        // Narrowed to the companies being replayed. Snapshotting the whole
        // workspace while regenerating one company reports every other
        // company's invoice as missing.
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', $companies->pluck('id'))
            ->with('lines')
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $lines = [];
            foreach ($invoice->lines as $line) {
                $lines[] = [
                    'type' => (string) $line->type,
                    'total_amount' => (int) $line->total_amount,
                    'unit_amount' => (int) $line->unit_amount,
                    'tax_amount' => (int) $line->tax_amount,
                    'hours' => $line->hours === null ? null : round((float) $line->hours, 4),
                ];
            }

            // Sorted so that a pure ordering difference is not reported as a
            // money difference; sort_order is compared separately by count.
            usort($lines, static fn (array $a, array $b): int => [$a['type'], $a['total_amount']] <=> [$b['type'], $b['total_amount']]);

            $rows[$this->key($invoice)] = [
                'invoice_number' => (string) $invoice->invoice_number,
                'invoice_kind' => $invoice->invoiceKindValue(),
                'status' => (string) $invoice->status,
                'currency' => (string) $invoice->currency,
                'subtotal_amount' => (int) $invoice->subtotal_amount,
                'tax_amount' => (int) $invoice->tax_amount,
                'total_amount' => (int) $invoice->total_amount,
                'hours_worked' => $invoice->hours_worked === null ? null : round((float) $invoice->hours_worked, 4),
                'hours_billed_at_rate' => $invoice->hours_billed_at_rate === null ? null : round((float) $invoice->hours_billed_at_rate, 4),
                'retainer_hours_included' => $invoice->retainer_hours_included === null ? null : round((float) $invoice->retainer_hours_included, 4),
                'lines' => $lines,
            ];
        }

        return $rows;
    }

    private function key(ClientInvoice $invoice): string
    {
        $cycle = $invoice->cycle_start === null || $invoice->cycle_end === null
            ? null
            : $invoice->cycle_start->toDateString().'..'.$invoice->cycle_end->toDateString();

        $period = ($invoice->service_period_start?->toDateString() ?? '?')
            .'..'.($invoice->service_period_end?->toDateString() ?? '?');

        // A cadence invoice is identified by the cycle it sells. An interim is
        // not: several can share one cycle, so without the period they collapse
        // onto a single key and all but one snapshot is silently overwritten -
        // hiding exactly the divergences this exists to find.
        $identity = $invoice->invoiceKindValue() === InvoiceKind::InterimOverage->value
            ? ($cycle ?? '').'@'.$period
            : ($cycle ?? $period);

        return implode('|', [
            (string) $invoice->client_company_id,
            (string) ($invoice->client_agreement_id ?? 'none'),
            $invoice->invoiceKindValue(),
            $identity,
        ]);
    }

    /**
     * The date to replay as.
     *
     * Defaults to the day before the newest cycle history contains. That is the
     * latest date from which generation still produces exactly that cycle and
     * stops: anchoring on the cycle's *end* instead leaves room for the walk to
     * roll forward one more, and the extra invoice reads as a divergence.
     */
    private function asOf(Workspace $workspace): Carbon
    {
        if (is_string($given = $this->option('as-of')) && $given !== '') {
            return Carbon::parse($given)->endOfDay();
        }

        // Two plain aggregates rather than GREATEST, which MySQL has and SQLite
        // does not; the harness has to run wherever the data is.
        $newestCycleStart = ClientInvoice::query()->where('workspace_id', $workspace->id)->max('cycle_start');
        if ($newestCycleStart !== null) {
            return Carbon::parse((string) $newestCycleStart)->subDay()->endOfDay();
        }

        // Older invoices predate the cycle columns and carry only a work period.
        $newestPeriodEnd = ClientInvoice::query()->where('workspace_id', $workspace->id)->max('service_period_end');

        return $newestPeriodEnd === null
            ? Carbon::now()
            : Carbon::parse((string) $newestPeriodEnd)->endOfDay();
    }

    /**
     * Strip every invoice back to an empty draft and release its time.
     *
     * Deliberately not a delete. Deleting cascades to
     * `client_invoice_payments`, and those payments are what
     * `OverpaymentCreditService` derives credit from - so a replay that removed
     * them would regenerate every credit-bearing invoice without its credit and
     * report a false divergence on each one.
     *
     * Blanking in place also matches what regeneration does in production: the
     * row, its number and its payment history survive, and the generator
     * refreshes it. Numbers stay stable, so the counter needs no rewind either.
     *
     * @param  Collection<int, ClientCompany>  $companies
     */
    private function clear(Workspace $workspace, Collection $companies): void
    {
        $invoiceIds = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', $companies->pluck('id'))
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            return;
        }

        $lineIds = DB::table('client_invoice_lines')->whereIn('client_invoice_id', $invoiceIds)->pluck('id');

        $entryIds = DB::table('client_invoice_line_time_entries')
            ->whereIn('client_invoice_line_id', $lineIds)
            ->pluck('client_time_entry_id');

        if ($entryIds->isNotEmpty()) {
            // Invoiced time becomes approved again, or the regenerated run would
            // treat it as already billed and produce empty invoices.
            ClientTimeEntry::query()->whereIn('id', $entryIds)->where('status', 'invoiced')->update(['status' => 'approved']);
        }

        DB::table('client_invoice_line_time_entries')->whereIn('client_invoice_line_id', $lineIds)->delete();
        DB::table('client_tasks')->whereIn('client_invoice_line_id', $lineIds)->update(['client_invoice_line_id' => null]);
        DB::table('client_invoice_lines')->whereIn('id', $lineIds)->delete();

        // Back to draft so the generator is allowed to rewrite them; a settled
        // invoice refuses regeneration, which is the correct rule everywhere
        // except inside this rolled-back sandbox.
        DB::table('client_invoices')->whereIn('id', $invoiceIds)->update([
            'status' => 'draft',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $actual
     * @return list<array<string, mixed>>
     */
    private function compare(array $expected, array $actual): array
    {
        $comparisons = [];

        foreach ($expected as $key => $before) {
            if ($before['invoice_kind'] === InvoiceKind::AdHoc->value) {
                continue;
            }

            $after = $actual[$key] ?? null;

            if ($after === null) {
                $comparisons[] = [
                    'key' => $key,
                    'invoice_number' => $before['invoice_number'],
                    'verdict' => 'missing',
                    'money_delta' => -$before['total_amount'],
                    'notes' => ['the engine did not produce this invoice'],
                ];

                continue;
            }

            $notes = [];
            $moneyDelta = $after['total_amount'] - $before['total_amount'];

            if ($after['currency'] !== $before['currency']) {
                // The same integer in two currencies is not the same money, and
                // the delta alone would read as an exact match.
                $notes[] = sprintf('currency %s -> %s', $before['currency'], $after['currency']);
            }

            if ($after['subtotal_amount'] !== $before['subtotal_amount']) {
                $notes[] = sprintf('subtotal %d -> %d', $before['subtotal_amount'], $after['subtotal_amount']);
            }
            if ($after['tax_amount'] !== $before['tax_amount']) {
                $notes[] = sprintf('tax %d -> %d', $before['tax_amount'], $after['tax_amount']);
            }
            if (count($after['lines']) !== count($before['lines'])) {
                $notes[] = sprintf('line count %d -> %d', count($before['lines']), count($after['lines']));
            }

            foreach ($this->lineDifferences($before['lines'], $after['lines']) as $note) {
                $notes[] = $note;
            }

            $hourNotes = [];
            foreach (['hours_worked', 'hours_billed_at_rate', 'retainer_hours_included'] as $field) {
                if ($before[$field] !== $after[$field]) {
                    $hourNotes[] = sprintf('%s %s -> %s', $field, $this->show($before[$field]), $this->show($after[$field]));
                }
            }

            $comparisons[] = [
                'key' => $key,
                'invoice_number' => $before['invoice_number'],
                'verdict' => $moneyDelta === 0 && $notes === [] ? 'match' : 'money_differs',
                'money_delta' => $moneyDelta,
                'notes' => $notes,
                'hour_notes' => $hourNotes,
            ];
        }

        foreach ($actual as $key => $after) {
            if (! isset($expected[$key]) && $after['invoice_kind'] !== InvoiceKind::AdHoc->value) {
                $comparisons[] = [
                    'key' => $key,
                    'invoice_number' => $after['invoice_number'],
                    'verdict' => 'unexpected',
                    'money_delta' => $after['total_amount'],
                    'notes' => ['the engine produced an invoice with no historical counterpart'],
                ];
            }
        }

        return $comparisons;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<string>
     */
    private function lineDifferences(array $before, array $after): array
    {
        $sum = static function (array $lines): array {
            $totals = [];
            foreach ($lines as $line) {
                $type = (string) $line['type'];
                $totals[$type] = ($totals[$type] ?? 0) + (int) $line['total_amount'];
            }

            return $totals;
        };

        $beforeTotals = $sum($before);
        $afterTotals = $sum($after);

        $notes = [];
        foreach (array_unique([...array_keys($beforeTotals), ...array_keys($afterTotals)]) as $type) {
            $b = $beforeTotals[$type] ?? 0;
            $a = $afterTotals[$type] ?? 0;
            if ($a !== $b) {
                $notes[] = sprintf('%s %d -> %d', $type, $b, $a);
            }
        }

        return $notes;
    }

    private function show(mixed $value): string
    {
        return $value === null ? 'null' : (string) $value;
    }

    /**
     * @param  array{comparisons: list<array<string, mixed>>, generation: list<string>}  $outcome
     */
    private function report(array $outcome): int
    {
        $comparisons = $outcome['comparisons'];

        $matched = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'match');
        $differs = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'money_differs');
        $missing = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'missing');
        $extra = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'unexpected');
        $hourOnly = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'match' && ($c['hour_notes'] ?? []) !== []);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>money identical</>', (string) count($matched));
        $this->components->twoColumnDetail('<fg=red>money differs</>', (string) count($differs));
        $this->components->twoColumnDetail('<fg=red>not generated</>', (string) count($missing));
        $this->components->twoColumnDetail('<fg=red>generated with no counterpart</>', (string) count($extra));
        $this->components->twoColumnDetail('<fg=yellow>hours differ only</>', (string) count($hourOnly));

        $absolute = array_sum(array_map(static fn (array $c): int => abs((int) $c['money_delta']), [...$differs, ...$missing, ...$extra]));
        $this->components->twoColumnDetail('absolute money divergence (minor units)', (string) $absolute);

        foreach ($outcome['generation'] as $failure) {
            $this->components->warn('generation failed - '.$failure);
        }

        if (is_string($path = $this->option('report')) && $path !== '') {
            file_put_contents($path, json_encode($comparisons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->info("Per-invoice detail written to {$path}.");
        } else {
            $this->components->info('Run again with --report=<path> for per-invoice detail.');
        }

        $failed = count($differs) + count($missing) + count($extra);

        if ($failed > 0 || $outcome['generation'] !== []) {
            $this->components->error(sprintf('%d invoice(s) did not reproduce. Money must match exactly.', $failed));

            return self::FAILURE;
        }

        $this->components->info('Every historical invoice reproduced to the cent.');

        return self::SUCCESS;
    }
}
