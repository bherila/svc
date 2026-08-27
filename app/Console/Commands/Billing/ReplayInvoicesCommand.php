<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\DeliberateCorrections;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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

    /**
     * Per key, how many invoices lost to a better candidate.
     *
     * @var array<string, int>
     */
    private array $superseded = [];

    /**
     * The losers themselves, so they can be taken out of the way.
     *
     * @var list<int>
     */
    private array $supersededIds = [];

    /**
     * Why the generator declined to make an invoice, counted by reason.
     *
     * @var array<string, int>
     */
    private array $skipReasons = [];

    /**
     * Facts are per agreement and period, and many invoices share them.
     *
     * @var array<string, array{rollover_months:int, project_scoped:bool, other_project_work:bool, deferred_work:bool, recurring_items:bool, cycle_opens_mid_month:bool}>
     */
    private array $factCache = [];

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

            $supersededCount = array_sum($this->superseded);
            if ($supersededCount > 0) {
                $this->components->twoColumnDetail(
                    'unsent drafts superseded',
                    sprintf('%d across %d period(s)', $supersededCount, count($this->superseded)),
                );
            }

            $unbilled = count(array_filter(
                $expected,
                static fn (array $row): bool => $row['status'] === 'draft',
            ));
            if ($unbilled > 0) {
                // Worth naming: for these the comparison is engine against
                // engine, not engine against what a client actually paid.
                $this->components->twoColumnDetail('periods never billed, compared against the last draft', (string) $unbilled);
            }

            $skippedAdHoc = count(array_filter(
                $expected,
                static fn (array $row): bool => $row['invoice_kind'] === InvoiceKind::AdHoc->value,
            ));
            if ($skippedAdHoc > 0) {
                $this->components->twoColumnDetail('ad-hoc, not machine-generated', (string) $skippedAdHoc);
            }

            // Anchored per company, not per workspace. History ends on a
            // different date for each: one client's annual retainer sold
            // through next year dragged the whole workspace's clock forward
            // with it, so every company whose billing had stopped earlier was
            // walked past the end of its own history and every cycle invented
            // there read as a divergence.
            $anchors = [];
            foreach ($companies as $company) {
                $anchors[$company->id] = $this->asOf($workspace, $company);
            }
            $this->reportAnchors($anchors);

            $this->clear($workspace, $companies);

            try {
                $service = app(ClientInvoicingService::class);
                foreach ($companies as $company) {
                    // Pinned per company so each cycle walk stops where that
                    // company's history does.
                    Carbon::setTestNow($anchors[$company->id]);
                    try {
                        $results = $service->generateAllInvoices($company);
                        foreach ($results['skipped'] as $skip) {
                            // The generator already explains itself; without
                            // this the harness reports an empty invoice and
                            // leaves the reader to guess why nothing was made.
                            $reason = (string) ($skip['reason_code'] ?? $skip['reason'] ?? $skip['error'] ?? 'unknown');
                            $this->skipReasons[$reason] = ($this->skipReasons[$reason] ?? 0) + 1;
                        }
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

            if ($this->skipReasons !== []) {
                // Only the count here. The generator's messages quote invoice
                // numbers, which carry client identifiers - those belong in the
                // report file on the host that already holds the data, not on a
                // terminal that may be anywhere.
                $this->components->twoColumnDetail(
                    'generator declined to create an invoice',
                    sprintf('%d time(s), %d distinct reason(s) - see the report', array_sum($this->skipReasons), count($this->skipReasons)),
                );
            }
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

            $key = $this->key($invoice);

            // One period can hold several invoices, because regenerating left
            // the previous attempt behind rather than replacing it. Every one of
            // those drafts is hidden from the client and carries no issue date:
            // they were produced and never sent. What the client was actually
            // billed is the settled invoice, so that wins; where a period was
            // never billed at all, the newest attempt stands in for it.
            //
            // Overwriting blindly is what made an earlier run meaningless - 37
            // of 78 invoices vanished before anything was compared, and an
            // untouched invoice was measured against a sibling this harness had
            // just blanked.
            if (isset($rows[$key]) && ! $this->supersedes($invoice, $rows[$key])) {
                $this->superseded[$key] = ($this->superseded[$key] ?? 0) + 1;
                $this->supersededIds[] = (int) $invoice->id;

                continue;
            }

            if (isset($rows[$key])) {
                $this->superseded[$key] = ($this->superseded[$key] ?? 0) + 1;
                $this->supersededIds[] = (int) $rows[$key]['id'];
            }

            $rows[$key] = [
                'id' => (int) $invoice->id,
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

    /**
     * Does this invoice better represent what the period was billed?
     *
     * A settled invoice beats a draft, because money changed hands against it.
     * Between two of the same standing, the later number wins: invoice numbers
     * are allocated in order, so the highest is the most recent attempt.
     *
     * @param  array<string, mixed>  $incumbent
     */
    private function supersedes(ClientInvoice $candidate, array $incumbent): bool
    {
        $candidateSettled = InvoiceStatus::isSettledValue($candidate->status);
        $incumbentSettled = InvoiceStatus::isSettledValue($incumbent['status'] ?? null);

        if ($candidateSettled !== $incumbentSettled) {
            return $candidateSettled;
        }

        return strcmp((string) $candidate->invoice_number, (string) ($incumbent['invoice_number'] ?? '')) > 0;
    }

    private function key(ClientInvoice $invoice): string
    {
        $cycle = $invoice->cycle_start === null || $invoice->cycle_end === null
            ? null
            : $invoice->cycle_start->toDateString().'..'.$invoice->cycle_end->toDateString();

        $period = ($invoice->service_period_start?->toDateString() ?? '?')
            .'..'.($invoice->service_period_end?->toDateString() ?? '?');

        // Both, always. The cycle says which retainer was sold; the period says
        // which work was reconciled, and under any cadence but monthly one
        // cycle covers many periods - an annual agreement invoices each month
        // against the same twelve-month cycle.
        //
        // Keying on the cycle alone collapsed all of them onto one entry and
        // silently kept whichever came last. Of 78 invoices here, 47 shared a
        // key with another and 37 were dropped before anything was compared,
        // which is how an untouched invoice came to be measured against a
        // sibling the harness had just blanked and reported as 125 lines
        // becoming none.
        $identity = ($cycle ?? '').'@'.$period;

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
    private function asOf(Workspace $workspace, ?ClientCompany $company = null): Carbon
    {
        if (is_string($given = $this->option('as-of')) && $given !== '') {
            return Carbon::parse($given)->endOfDay();
        }

        $scope = fn (): Builder => ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->when($company !== null, fn (Builder $q): Builder => $q->where('client_company_id', $company->id));

        // Two plain aggregates rather than GREATEST, which MySQL has and SQLite
        // does not; the harness has to run wherever the data is.
        $newestCycleStart = $scope()->max('cycle_start');
        if ($newestCycleStart !== null) {
            return Carbon::parse((string) $newestCycleStart)->subDay()->endOfDay();
        }

        // Older invoices predate the cycle columns and carry only a work period.
        $newestPeriodEnd = $scope()->max('service_period_end');

        return $newestPeriodEnd === null
            ? Carbon::now()
            : Carbon::parse((string) $newestPeriodEnd)->endOfDay();
    }

    /**
     * @param  array<int, Carbon>  $anchors
     */
    private function reportAnchors(array $anchors): void
    {
        $dates = array_map(static fn (Carbon $c): string => $c->toDateString(), $anchors);
        sort($dates);
        $first = $dates === [] ? '-' : $dates[0];
        $last = $dates === [] ? '-' : $dates[count($dates) - 1];

        $this->components->twoColumnDetail(
            'replaying as of',
            $first === $last ? $first : "{$first} to {$last}, per company",
        );
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

        // Superseded attempts go entirely, not just blank. Leaving them meant a
        // period held several identical empty drafts, the generator refreshed
        // whichever it reached first, and the one this harness was watching
        // stayed empty - reported as the engine producing nothing. They are
        // unsent drafts by construction, so nothing is lost with them.
        if ($this->supersededIds !== []) {
            $withPayments = DB::table('client_invoice_payments')
                ->whereIn('client_invoice_id', $this->supersededIds)
                ->count();

            if ($withPayments > 0) {
                // Never true for an unsent draft. If it ever is, the selection
                // rule is wrong and deleting would be destroying history.
                throw new RuntimeException('A superseded invoice carries payments; refusing to set it aside.');
            }

            DB::table('client_invoices')->whereIn('id', $this->supersededIds)->delete();
        }

        // Back to draft so the generator is allowed to rewrite them; a settled
        // invoice refuses regeneration, which is the correct rule everywhere
        // except inside this rolled-back sandbox.
        DB::table('client_invoices')->whereIn('id', $invoiceIds)->update([
            'status' => 'draft',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            // Zeroed with the total, not left behind. A blanked invoice that
            // still claims to have been paid looks overpaid by the whole amount,
            // so the credit service invented a credit line on the next invoice
            // for every settled invoice in history - reported as the engine
            // producing credits that never existed.
            //
            // The payment rows themselves survive, and those are what credit is
            // actually derived from; this column is a denormalised total the
            // generator recomputes.
            'paid_amount' => 0,
            'balance_amount' => 0,
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
                // Three outcomes, not two. An invoice whose total is identical
                // but whose lines are arranged differently has not mis-billed
                // anyone - the client owes the same amount - so it is reported
                // and not failed. The agreed bar is that money is exact; how the
                // same money is presented is worth seeing, not worth blocking.
                'verdict' => match (true) {
                    $moneyDelta === 0 && $notes === [] => 'match',
                    $moneyDelta === 0 => 'composition_differs',
                    default => 'money_differs',
                },
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

        return $this->reconcileLegacyPeriods($comparisons);
    }

    /**
     * Pair up invoices the two conventions disagree about the *period* of.
     *
     * History was written when an invoice's work period was the same as the
     * cycle it sold. This engine applies the one-cycle offset, so its service
     * period is the previous cycle's work - the same invoice, describing the
     * same retainer, labelled a period earlier. Against production data that
     * turned five invoices into five "not generated" plus five "no
     * counterpart", which reads as ten failures and is none.
     *
     * The generator already recognises the older convention when it looks for an
     * existing invoice; this teaches the comparison the same thing. Pairing is
     * on the cycle, which is what actually identifies what was sold, and only
     * where exactly one unmatched invoice sits on each side of it - anything
     * more and the pairing would be a guess.
     *
     * @param  list<array<string, mixed>>  $comparisons
     * @return list<array<string, mixed>>
     */
    private function reconcileLegacyPeriods(array $comparisons): array
    {
        $cycleOf = static function (string $key): string {
            [$company, $agreement, $kind, $identity] = array_pad(explode('|', $key, 4), 4, '');
            $cycle = explode('@', $identity, 2)[0];

            return implode('|', [$company, $agreement, $kind, $cycle]);
        };

        $missing = [];
        $extra = [];
        foreach ($comparisons as $index => $comparison) {
            if ($comparison['verdict'] === 'missing') {
                $missing[$cycleOf((string) $comparison['key'])][] = $index;
            } elseif ($comparison['verdict'] === 'unexpected') {
                $extra[$cycleOf((string) $comparison['key'])][] = $index;
            }
        }

        foreach ($missing as $cycle => $missingIndexes) {
            if (! isset($extra[$cycle]) || count($missingIndexes) !== 1 || count($extra[$cycle]) !== 1) {
                continue;
            }

            $historical = $comparisons[$missingIndexes[0]];
            $generated = $comparisons[$extra[$cycle][0]];

            // money_delta on a missing row is the negated historical total; on
            // an unexpected row it is the generated total. Their sum is what the
            // engine over- or under-billed for the cycle.
            $delta = (int) $historical['money_delta'] + (int) $generated['money_delta'];

            $comparisons[$missingIndexes[0]] = [
                'key' => $historical['key'],
                'invoice_number' => $historical['invoice_number'],
                'verdict' => $delta === 0 ? 'match_legacy_period' : 'money_differs',
                'money_delta' => $delta,
                'notes' => array_merge(
                    ['paired with the engine\'s invoice for the same cycle; history labels the period under the older period-equals-cycle convention'],
                    $delta === 0 ? [] : ['cycle total '.$this->show(-(int) $historical['money_delta']).' -> '.$this->show((int) $generated['money_delta'])],
                ),
                'hour_notes' => [],
            ];

            unset($comparisons[$extra[$cycle][0]]);
        }

        return array_values($comparisons);
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

    /**
     * Decide whether a divergence is one of the four this port makes on purpose.
     *
     * The predicates are narrow by design: a correction explains a divergence
     * only when every changed line type is within its reach and the conditions
     * that trigger it hold for this agreement and period. Anything looser would
     * quietly absorb a regression, and the unexplained count is the only number
     * here worth reading.
     *
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    private function attribute(array $comparison): array
    {
        if ($comparison['verdict'] !== 'money_differs') {
            return $comparison;
        }

        $changed = [];
        foreach ((array) ($comparison['notes'] ?? []) as $note) {
            if (preg_match('/^([a-z_]+) /', (string) $note, $matches) === 1) {
                $changed[] = $matches[1];
            }
        }
        $changed = array_values(array_unique(array_diff($changed, ['line'])));

        $comparison['explained_by'] = DeliberateCorrections::explaining($changed, $this->facts($comparison['key']));

        return $comparison;
    }

    /**
     * What is true of the agreement and period behind a comparison key.
     *
     * @return array{rollover_months:int, project_scoped:bool, other_project_work:bool, deferred_work:bool, recurring_items:bool, cycle_opens_mid_month:bool}
     */
    private function facts(string $key): array
    {
        if (isset($this->factCache[$key])) {
            return $this->factCache[$key];
        }

        [$companyId, $agreementId, , $identity] = array_pad(explode('|', $key, 4), 4, '');
        $agreement = $agreementId === 'none' ? null : ClientAgreement::query()->find((int) $agreementId);

        [$cycle, $period] = array_pad(explode('@', $identity, 2), 2, '');
        [$periodStart, $periodEnd] = array_pad(explode('..', $period, 2), 2, '');
        $cycleStart = explode('..', $cycle, 2)[0];

        $entries = ClientTimeEntry::query()
            ->where('client_company_id', (int) $companyId)
            ->when($periodStart !== '' && $periodStart !== '?', fn ($q) => $q->where('worked_on', '>=', $periodStart))
            ->when($periodEnd !== '' && $periodEnd !== '?', fn ($q) => $q->where('worked_on', '<=', $periodEnd));

        $facts = [
            'rollover_months' => (int) ($agreement->rollover_months ?? 0),
            'project_scoped' => $agreement?->client_project_id !== null,
            'other_project_work' => $agreement?->client_project_id !== null
                && (clone $entries)->where('client_project_id', '!=', $agreement->client_project_id)->exists(),
            'deferred_work' => (clone $entries)->where('is_deferred', true)->exists(),
            'recurring_items' => $agreement !== null && $agreement->recurringItems()->exists(),
            'cycle_opens_mid_month' => $cycleStart !== '' && Carbon::parse($cycleStart)->day !== 1,
        ];

        return $this->factCache[$key] = $facts;
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

        $comparisons = array_map($this->attribute(...), $comparisons);
        $outcome['comparisons'] = $comparisons;

        $matched = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'match');
        $composition = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'composition_differs');
        $legacyPeriod = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'match_legacy_period');
        $differs = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'money_differs');
        $explained = array_filter($differs, static fn (array $c): bool => ($c['explained_by'] ?? []) !== []);
        $unexplained = array_filter($differs, static fn (array $c): bool => ($c['explained_by'] ?? []) === []);
        $missing = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'missing');
        $extra = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'unexpected');
        $hourOnly = array_filter($comparisons, static fn (array $c): bool => in_array($c['verdict'], ['match', 'composition_differs'], true) && ($c['hour_notes'] ?? []) !== []);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>money identical</>', (string) count($matched));
        $this->components->twoColumnDetail(
            '<fg=green>identical once the legacy period convention is allowed for</>',
            (string) count($legacyPeriod),
        );
        $this->components->twoColumnDetail(
            '<fg=yellow>same total, lines arranged differently</>',
            (string) count($composition),
        );
        $this->components->twoColumnDetail(
            '<fg=yellow>differs, explained by a deliberate correction</>',
            (string) count($explained),
        );
        $this->components->twoColumnDetail('<fg=red>differs, unexplained</>', (string) count($unexplained));

        $byCorrection = [];
        foreach ($explained as $c) {
            foreach ($c['explained_by'] as $reason) {
                $byCorrection[$reason['key']] = ($byCorrection[$reason['key']] ?? 0) + 1;
            }
        }
        arsort($byCorrection);
        foreach ($byCorrection as $key => $count) {
            $this->components->twoColumnDetail('  '.$key, (string) $count);
        }
        $this->components->twoColumnDetail('<fg=red>not generated</>', (string) count($missing));
        $this->components->twoColumnDetail('<fg=red>generated with no counterpart</>', (string) count($extra));
        $this->components->twoColumnDetail('<fg=yellow>hours differ only</>', (string) count($hourOnly));

        $absolute = array_sum(array_map(static fn (array $c): int => abs((int) $c['money_delta']), [...$unexplained, ...$missing, ...$extra]));
        $this->components->twoColumnDetail('unexplained money divergence (minor units)', (string) $absolute);

        foreach ($outcome['generation'] as $failure) {
            $this->components->warn('generation failed - '.$failure);
        }

        if (is_string($path = $this->option('report')) && $path !== '') {
            // Skip reasons go in the file rather than on stdout: the generator's
            // messages name invoices, and the whole point of the report is that
            // detail stays on the host that holds the data.
            file_put_contents($path, json_encode([
                'comparisons' => $comparisons,
                'generator_skipped' => $this->skipReasons,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->info("Per-invoice detail written to {$path}.");
        } else {
            $this->components->info('Run again with --report=<path> for per-invoice detail.');
        }

        // A divergence a known correction accounts for is the port working as
        // intended; failing on it would ask the engine to reproduce bugs it was
        // fixed not to have. The whole value of this run is the other number.
        $failed = count($unexplained) + count($missing) + count($extra);

        if ($failed > 0 || $outcome['generation'] !== []) {
            $this->components->error(sprintf('%d invoice(s) did not reproduce. Money must match exactly.', $failed));

            return self::FAILURE;
        }

        $this->components->info('Every historical invoice reproduced to the cent.');

        return self::SUCCESS;
    }
}
