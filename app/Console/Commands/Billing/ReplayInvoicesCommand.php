<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\ReplayContractCorrectionClassifier;
use App\Services\Billing\ReplayHistoryBasis;
use App\Services\Billing\ReplayRecurringItemIncidenceRepository;
use App\Support\Billing\CorrectionFacts;
use App\Support\Billing\DeliberateCorrections;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\ReplayHistoricalCycle;
use App\Support\Billing\ReplayHistorySeed;
use App\Support\Billing\ReplayInvoiceSnapshot;
use App\Support\Billing\ReplayOpeningCapacityContext;
use App\Support\Billing\ReplayOpeningCapacityProof;
use App\Support\Billing\ReplayRecurringItemIncidence;
use App\Support\Billing\ReplaySnapshotValue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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
 * Replaying means aligning predecessor history from its first billed service
 * period, deleting every generated invoice and regenerating it, which is
 * obviously unacceptable against real data. So the whole run happens inside
 * one transaction that is rolled back unconditionally - on success exactly as
 * on failure. There is no code path that commits. The generator's own
 * `DB::transaction()` calls nest as savepoints inside it, so its commits are
 * subsumed by the outer rollback too. The stored agreement start is never
 * changed; only the comparison identity, pinned clock and replay-only retainer
 * ledger basis bend to the earlier history.
 *
 * That is what makes this safe to point at production data. Do not add a
 * `--commit` option; if a divergence needs fixing, fix the engine and replay
 * again.
 *
 * ## What counts as a failure
 *
 * Money is exact. An unexplained difference of one minor unit in any line or
 * total is a divergence and the command exits non-zero. A difference is
 * accepted only when the report proves it is required by a named current
 * billing contract, such as whole-minute arithmetic.
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
    /** @var array<string, CorrectionFacts> */
    private array $factCache = [];

    /**
     * Per-run key for description digests.
     *
     * A bare hash of a low-entropy string is not concealment: a reader with the
     * report can hash likely client names and billing labels and test them.
     * Keying it per run keeps the before and after snapshots comparable while
     * making a guess unverifiable off the host.
     */
    private string $digestKey = '';

    /**
     * Agreements whose predecessor history begins before their recorded start.
     *
     * The predecessor labelled the invoice's cycle as the service period it
     * reconciled; the current engine labels the cycle it sells next. For these
     * agreements the service period, not that incompatible cycle label, is the
     * stable identity shared by both histories.
     *
     * @var array<int, true>
     */
    private array $historyBasisAgreementIds = [];

    /** @var array<int, ReplayHistorySeed> */
    private array $historySeeds = [];

    /** @var array<int, ReplayOpeningCapacityContext> */
    private array $openingCapacityContexts = [];

    /** @var array<string, ReplayOpeningCapacityProof> */
    private array $openingCapacityProofs = [];

    /** @var array<string, list<ReplayRecurringItemIncidence>> */
    private array $recurringItemIncidences = [];

    public function handle(): int
    {
        $this->digestKey = bin2hex(random_bytes(32));
        $this->historyBasisAgreementIds = [];
        $this->historySeeds = [];
        $this->openingCapacityContexts = [];
        $this->openingCapacityProofs = [];
        $this->recurringItemIncidences = [];
        $historyBasis = app(ReplayHistoryBasis::class);
        $historyBasis->reset();

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
            $this->components->error('No client companies with invoice history to replay.');

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
            $historySeeds = $this->prepareReplayOnlyHistoryBasis($workspace, $companies);
            $expected = $this->snapshot($workspace, $companies);
            $this->openingCapacityContexts = $this->openingCapacityContexts($expected);
            $this->components->twoColumnDetail('invoices captured', (string) count($expected));

            if ($historySeeds !== []) {
                sort($historySeeds);
                $first = $historySeeds[0];
                $last = $historySeeds[count($historySeeds) - 1];
                $range = $first === $last ? $first : "{$first} to {$last}";
                $this->components->twoColumnDetail(
                    'replay-only historical agreement seed',
                    sprintf(
                        '%d agreement(s), %s; paired and anchored by service period; stored starts unchanged',
                        count($historySeeds),
                        $range,
                    ),
                );
            }

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

            $outcome['comparisons'] = $this->compare(
                $workspace,
                $expected,
                $this->snapshot($workspace, $companies),
                $anchors,
            );

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
            $historyBasis->reset();
            DB::rollBack();
        }

        return $this->report($outcome);
    }

    /**
     * @return Collection<int, ClientCompany>
     */
    private function companies(Workspace $workspace): Collection
    {
        $query = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas(
                'invoices',
                fn (Builder $invoices): Builder => $invoices->where('workspace_id', $workspace->id),
            );

        if (is_string($companyPublicId = $this->option('company')) && $companyPublicId !== '') {
            $query->where('public_id', $companyPublicId);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Let the replay begin where recorded billing history begins.
     *
     * Two source agreements are recorded as starting on 2026-01-01 while live
     * invoice history labels its first billed service period as a 2025-12
     * cycle. The current engine labels the next cycle being sold instead. A
     * cycle-to-cycle comparison therefore aligns different months of work and
     * leaves one false boundary invoice at each end of the chain.
     *
     * History is authoritative only for the replay identity, clock and ledger
     * opening balance. The agreement row is never edited; ordinary generation
     * still begins on its recorded start, while the replay pairs the two
     * histories on the service period they genuinely share.
     *
     * @param  Collection<int, ClientCompany>  $companies
     * @return list<string> seeded dates, for aggregate reporting only
     */
    private function prepareReplayOnlyHistoryBasis(Workspace $workspace, Collection $companies): array
    {
        $historyByAgreement = ClientInvoice::query()
            ->select([
                'id',
                'client_company_id',
                'client_agreement_id',
                'status',
                'invoice_kind',
                'cycle_start',
                'cycle_end',
                'service_period_start',
                'service_period_end',
            ])
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', $companies->pluck('id'))
            ->whereNotNull('client_agreement_id')
            ->where(function (Builder $query): void {
                $query->whereNull('invoice_kind')
                    ->orWhere('invoice_kind', '!=', InvoiceKind::AdHoc->value);
            })
            ->orderBy('client_agreement_id')
            ->orderBy('service_period_start')
            ->orderBy('id')
            ->get()
            ->groupBy('client_agreement_id');

        if ($historyByAgreement->isEmpty()) {
            return [];
        }

        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', $companies->pluck('id'))
            ->whereIn('id', $historyByAgreement->keys())
            ->whereNotNull('starts_on')
            ->get();

        $seeded = [];
        foreach ($agreements as $agreement) {
            $attempts = [];
            foreach ($historyByAgreement->get($agreement->id, collect()) as $invoice) {
                $identity = implode('|', [
                    $invoice->invoiceKindValue(),
                    $invoice->service_period_start?->toDateString() ?? '?',
                    $invoice->service_period_end?->toDateString() ?? '?',
                ]);
                $incumbent = $attempts[$identity] ?? null;
                if (! $incumbent instanceof ClientInvoice
                    || $this->supersedes($invoice, ['id' => $incumbent->id, 'status' => $incumbent->status])) {
                    $attempts[$identity] = $invoice;
                }
            }

            $cycles = array_values(collect($attempts)
                // A void attempt cancels the identity it superseded. Filtering
                // before selecting the winner would resurrect the older live
                // attempt and let a cycle that never happened seed replay.
                ->filter(static fn (ClientInvoice $invoice): bool => in_array(
                    (string) $invoice->status,
                    InvoiceStatus::live(),
                    true,
                ))
                ->sortBy('service_period_start')
                ->map(static fn (ClientInvoice $invoice): ReplayHistoricalCycle => new ReplayHistoricalCycle(
                    invoiceKind: $invoice->invoiceKindValue(),
                    cycleStart: $invoice->cycle_start === null ? null : CarbonImmutable::instance($invoice->cycle_start)->startOfDay(),
                    cycleEnd: $invoice->cycle_end === null ? null : CarbonImmutable::instance($invoice->cycle_end)->startOfDay(),
                    servicePeriodStart: $invoice->service_period_start === null ? null : CarbonImmutable::instance($invoice->service_period_start)->startOfDay(),
                    servicePeriodEnd: $invoice->service_period_end === null ? null : CarbonImmutable::instance($invoice->service_period_end)->startOfDay(),
                ))
                ->values()
                ->all());

            $seed = ReplayHistorySeed::fromHistory(
                workspaceId: (int) $workspace->id,
                companyId: (int) $agreement->client_company_id,
                agreementId: (int) $agreement->id,
                currency: (string) $agreement->currency,
                retainerMinutes: (int) round($agreement->periodRetainerHours() * 60),
                retainerAmount: (int) round($agreement->periodRetainerFee() * 100),
                rolloverMonths: (int) ($agreement->rollover_months ?? 0),
                cadence: $agreement->effectiveBillingCadence(),
                agreementStart: CarbonImmutable::instance($agreement->starts_on)->startOfDay(),
                agreementEnd: $agreement->ends_on === null
                    ? null
                    : CarbonImmutable::instance($agreement->ends_on)->startOfDay(),
                cycles: $cycles,
            );
            if (! $seed instanceof ReplayHistorySeed) {
                continue;
            }

            $this->historyBasisAgreementIds[$agreement->id] = true;
            $this->historySeeds[$agreement->id] = $seed;
            app(ReplayHistoryBasis::class)->seed($agreement, $seed->seedStart);
            $seeded[] = $seed->seedStart->toDateString();
        }

        return $seeded;
    }

    /**
     * Prove the opening month sold a retainer before allowing its capacity to
     * explain a later allocation difference. This reads the already captured
     * snapshots; it performs no classifier-time queries.
     *
     * @param  array<string, array<string, mixed>>  $expected
     * @return array<int, ReplayOpeningCapacityContext>
     */
    private function openingCapacityContexts(array $expected): array
    {
        $contexts = [];
        foreach ($expected as $key => $snapshot) {
            [, $agreementId, $kind] = array_pad(explode('|', $key, 4), 4, '');
            $seed = $this->historySeeds[(int) $agreementId] ?? null;
            if (! $seed instanceof ReplayHistorySeed
                || $kind !== InvoiceKind::CadencePeriod->value
                || ! InvoiceStatus::hasChargedValue($snapshot['status'] ?? null)
                || (string) ($snapshot['service_period_start'] ?? '') !== $seed->seedStart->toDateString()) {
                continue;
            }

            $context = ReplayOpeningCapacityContext::fromOpeningInvoice(
                $seed,
                ReplayInvoiceSnapshot::fromArray($snapshot),
            );
            if ($context instanceof ReplayOpeningCapacityContext) {
                $contexts[$seed->agreementId] = $context;
            }
        }

        return $contexts;
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
            ->with([
                'lines' => fn ($lines) => $lines
                    ->where('workspace_id', $workspace->id)
                    ->with([
                        'timeEntries' => fn ($entries) => $entries
                            ->where('client_time_entries.workspace_id', $workspace->id)
                            ->where('client_invoice_line_time_entries.workspace_id', $workspace->id),
                    ]),
            ])
            ->orderBy('id')
            ->get();

        // Which deliverable each milestone line billed. A task's title and
        // price are both editable, so the wording and the amount can change
        // together and leave nothing linking the two versions - but the claim
        // itself survives, and this command releases and recreates it.
        $claimedBy = ClientTask::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('client_invoice_line_id')
            ->pluck('public_id', 'client_invoice_line_id');

        foreach ($invoices as $invoice) {
            /** @var list<array{type: string, total_amount: int, unit_amount: int, tax_amount: int, quantity: string, line_date: string, recurring_item_id: string, project_id: string, agreement_id: string, claimed_by: string, description_hash: string, identity_hash: string, hours: float|null, source_minutes: int, source_agreement_rate_minutes: int}> $lines */
            $lines = [];
            foreach ($invoice->lines as $line) {
                $lineDate = $line->line_date === null ? '' : substr((string) $line->line_date, 0, 10);
                $recurringItemId = $line->client_agreement_recurring_item_id === null
                    ? ''
                    : (string) (int) $line->client_agreement_recurring_item_id;
                $projectId = $line->client_project_id === null
                    ? ''
                    : (string) (int) $line->client_project_id;
                // Stored per line and restored per source row, so it is not
                // guaranteed to match the invoice's own agreement - a charge
                // reattributed to another agreement changes nothing else.
                $agreementId = $line->client_agreement_id === null
                    ? ''
                    : (string) (int) $line->client_agreement_id;
                $claim = (string) ($claimedBy[$line->id] ?? '');

                $lines[] = [
                    'type' => (string) $line->type,
                    'total_amount' => (int) $line->total_amount,
                    'unit_amount' => (int) $line->unit_amount,
                    'tax_amount' => (int) $line->tax_amount,
                    // Kept as the stored decimal string. Casting to float
                    // collapses values that differ only in the last of four
                    // decimal places, which is exactly where a quantity this
                    // schema allows can differ.
                    'quantity' => self::decimalString($line->quantity),
                    'line_date' => $lineDate,
                    'recurring_item_id' => $recurringItemId,
                    // Which project the charge is attributed to. A subcontractor
                    // line carries one, and moving a charge between projects is
                    // not visible in any other field here.
                    'project_id' => $projectId,
                    'agreement_id' => $agreementId,
                    'claimed_by' => $claim,
                    // A description is the one field here that can carry a
                    // client's name, and this output is meant to be readable
                    // outside the host. Digested under a per-run key, so two
                    // lines that differ only in wording still compare as
                    // different without a guess being verifiable off the host.
                    'description_hash' => substr(hash_hmac('sha256', (string) $line->description, $this->digestKey), 0, 12),
                    // The same description with every number taken out. The
                    // composer writes amounts into its wording - hours and rate
                    // for deferred termination work, applied time for a
                    // retainer draw - so a repriced line gets a new description
                    // too. Identifying a charge by the full text would make it
                    // a different charge, and hide the repricing as a line
                    // removed and another added.
                    'identity_hash' => substr(hash_hmac('sha256', self::withoutAmounts((string) $line->description, (string) $line->type), $this->digestKey), 0, 12),
                    'hours' => $line->hours === null ? null : round((float) $line->hours, 4),
                    // Only the aggregate allocation is retained. It proves a
                    // priced or capacity line is backed by work without moving
                    // descriptions, people, project identifiers, or raw entry
                    // data into the replay report.
                    'source_minutes' => (int) $line->timeEntries->sum('minutes'),
                    // Eligibility is retained only as another aggregate. This
                    // proves agreement-rate/capacity lines are backed entirely
                    // by ordinary or retainer-mode work without exposing a
                    // worker, mode, rate, description, or source identifier.
                    'source_agreement_rate_minutes' => (int) $line->timeEntries
                        ->filter(static fn (ClientTimeEntry $entry): bool => $entry->isAgreementRateBillable())
                        ->sum('minutes'),
                ];
            }

            // Sorted so that a pure ordering difference is not reported as a
            // money difference; sort_order is compared separately by count.
            usort($lines, static fn (array $a, array $b): int => [$a['type'], $a['total_amount'], $a['unit_amount'], $a['quantity'], $a['line_date'], $a['project_id'], $a['description_hash']]
                <=> [$b['type'], $b['total_amount'], $b['unit_amount'], $b['quantity'], $b['line_date'], $b['project_id'], $b['description_hash']]);

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
                // Keep the sold cycle even when replay pairing uses service
                // period as its key. Correction proofs need the real cycle the
                // biller evaluated; parsing the substituted key silently asks
                // them about a different incidence window.
                'cycle_start' => $invoice->cycle_start?->toDateString(),
                'cycle_end' => $invoice->cycle_end?->toDateString(),
                'service_period_start' => $invoice->service_period_start?->toDateString(),
                'service_period_end' => $invoice->service_period_end?->toDateString(),
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

        // Row id, not invoice number. Allocator numbers are zero-padded and
        // imported ones keep the source system's format, so `1042` sorted below
        // `999` as a string. The id is monotonic and says which row was written
        // later, which is what "the most recent attempt" means here.
        return (int) $candidate->id > (int) ($incumbent['id'] ?? 0);
    }

    private function key(ClientInvoice $invoice): string
    {
        $cycle = $invoice->cycle_start === null || $invoice->cycle_end === null
            ? null
            : $invoice->cycle_start->toDateString().'..'.$invoice->cycle_end->toDateString();

        $period = ($invoice->service_period_start?->toDateString() ?? '?')
            .'..'.($invoice->service_period_end?->toDateString() ?? '?');

        // Ordinarily both. The cycle says which retainer was sold; the period
        // says which work was reconciled, and under any cadence but monthly one
        // cycle covers many periods - an annual agreement invoices each month
        // against the same twelve-month cycle. The replay-only exception below
        // handles predecessor history whose cycle label means service period.
        //
        // Keying on the cycle alone collapsed all of them onto one entry and
        // silently kept whichever came last. Of 78 invoices here, 47 shared a
        // key with another and 37 were dropped before anything was compared,
        // which is how an untouched invoice came to be measured against a
        // sibling the harness had just blanked and reported as 125 lines
        // becoming none.
        $identity = $invoice->client_agreement_id !== null
            && isset($this->historyBasisAgreementIds[$invoice->client_agreement_id])
                // Both sides share the work period. Repeating it in the cycle
                // slot keeps correction facts parseable while deliberately
                // setting aside the predecessor's incompatible cycle label.
                ? $period.'@'.$period
                : ($cycle ?? '').'@'.$period;

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
     * Ordinarily defaults to the day before the newest cycle history contains.
     * That is the latest date from which generation produces that cycle and
     * stops. A replay-only history basis instead anchors on its latest service
     * period so the current engine reconciles the same final month of work.
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
        $anchor = null;
        $newestCycleStart = $scope()->max('cycle_start');
        if ($newestCycleStart !== null) {
            $anchor = Carbon::parse((string) $newestCycleStart)->subDay()->endOfDay();
        }

        // A history-basis agreement is paired by service period. Pinning it by
        // the predecessor's shifted cycle label stops one period too early and
        // leaves the final historical invoice as an empty draft. The latest
        // service-period end is the clock from which the current engine sells
        // the next cycle while reconciling that final period.
        if ($company !== null && $this->historyBasisAgreementIds !== []) {
            $newestHistoryPeriodEnd = $scope()
                ->whereIn('client_agreement_id', array_keys($this->historyBasisAgreementIds))
                ->where(function (Builder $q): void {
                    $q->whereNull('invoice_kind')
                        ->orWhere('invoice_kind', '!=', InvoiceKind::AdHoc->value);
                })
                ->max('service_period_end');
            if ($newestHistoryPeriodEnd !== null) {
                $historyAnchor = Carbon::parse((string) $newestHistoryPeriodEnd)->endOfDay();
                if (! $anchor instanceof Carbon || $historyAnchor->gt($anchor)) {
                    $anchor = $historyAnchor;
                }
            }
        }

        if ($anchor instanceof Carbon) {
            return $anchor;
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
        // Machine-generated invoices only. An ad-hoc invoice is excluded from
        // the comparison and never regenerated, so clearing it released its
        // time-entry pivots, milestone claims and recurring incidences to the
        // cadence generator - which then billed work that had already been
        // billed ad hoc and reported the result as the engine inventing a
        // charge. Leave them, and their claims, exactly as they are.
        $invoiceIds = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', $companies->pluck('id'))
            ->where(function ($query): void {
                $query->whereNull('invoice_kind')
                    ->orWhere('invoice_kind', '!=', InvoiceKind::AdHoc->value);
            })
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

        // The payments go with the totals.
        //
        // `OverpaymentCreditService` derives credit from the payment rows
        // against `total_amount`, not from `paid_amount`. Zeroing the column
        // and leaving the rows was therefore no fix at all: every settled
        // invoice in history became a payment against a zero-value invoice,
        // which is the definition of an overpayment. Every regenerated invoice
        // then drew on a credit pool the harness had manufactured, and eight of
        // the divergences this command reported were its own doing.
        //
        // Deleting them makes this a comparison of *gross billing* - what the
        // engine charges, before settlement. That is the question the replay
        // can actually answer. Replaying settlement needs an immutable, dated
        // ledger of payments and consumed credits so that no invoice can be
        // funded by a payment made after it, which is a different harness and
        // is tracked separately. Nothing is lost: the outer transaction is
        // rolled back unconditionally.
        //
        // A historical credit line that was genuinely earned will now show as a
        // divergence rather than being masked by a manufactured one. That is
        // the right direction to fail in.
        DB::table('client_invoice_payments')->whereIn('client_invoice_id', $invoiceIds)->delete();

        // Back to draft so the generator is allowed to rewrite them; a settled
        // invoice refuses regeneration, which is the correct rule everywhere
        // except inside this rolled-back sandbox.
        DB::table('client_invoices')->whereIn('id', $invoiceIds)->update([
            'status' => 'draft',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'balance_amount' => 0,
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $actual
     * @param  array<int, Carbon>  $anchors
     * @return list<array<string, mixed>>
     */
    private function compare(Workspace $workspace, array $expected, array $actual, array $anchors): array
    {
        $comparisons = [];
        $this->recurringItemIncidences = app(ReplayRecurringItemIncidenceRepository::class)
            ->forSnapshots($workspace, $actual, $this->digestKey);
        $cadenceHistoryGapKeys = app(ReplayContractCorrectionClassifier::class)
            ->contractCadenceHistoryGapKeys($workspace, $expected, $actual, $anchors);
        $this->openingCapacityProofs = $this->proveOpeningCapacityChain($expected, $actual);

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
                    'snapshot' => $before,
                ];

                continue;
            }

            $examined = $this->examine($workspace, $before, $after, $key);
            $notes = $examined['notes'];
            $changedTypes = $examined['changed_types'];
            $changedFields = $examined['changed_fields'];
            $lineMoneyDiffers = $examined['line_money_differs'];
            $hourNotes = $examined['hour_notes'];
            $moneyDelta = $after['total_amount'] - $before['total_amount'];

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
                    // Money on the invoice header counts too. The same integer
                    // in two currencies is not the same money, and a subtotal
                    // and tax that move by offsetting amounts are a different
                    // bill for the same total - neither is an arrangement of
                    // the same money.
                    $moneyDelta === 0 && ! $lineMoneyDiffers && ! $examined['metadata_differs'] => 'composition_differs',
                    default => 'money_differs',
                },
                'money_delta' => $moneyDelta,
                'notes' => $notes,
                'hour_notes' => $hourNotes,
                'line_money_differs' => $lineMoneyDiffers,
                'line_repriced' => $examined['line_repriced'],
                'capacity_reallocated_at_same_rate' => $examined['capacity_reallocated_at_same_rate'],
                'exact_minute_arithmetic' => $examined['exact_minute_arithmetic'],
                'opening_recurring_item_incidence' => $examined['opening_recurring_item_incidence'],
                'history_omitted_opening_capacity' => $examined['history_omitted_opening_capacity'],
                'opening_capacity_also_corrects_minute_rounding' => $examined['opening_capacity_also_corrects_minute_rounding'],
                'changed_types' => $changedTypes,
                'attribution_changed_types' => $examined['attribution_changed_types'],
                'changed_fields' => $changedFields,
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
                    'contract_cadence_history_gap' => isset($cadenceHistoryGapKeys[$key]),
                    'snapshot' => $after,
                ];
            }
        }

        return $this->reconcileLegacyPeriods($workspace, $comparisons);
    }

    /**
     * Consume a replay-only opening lot once, in service-period order.
     *
     * The classifier proves one invoice without persistence. This coordinator
     * supplies the remaining immutable context for each step, so the same lot
     * cannot explain more minutes than it granted or survive its configured
     * rollover window.
     *
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $actual
     * @return array<string, ReplayOpeningCapacityProof>
     */
    private function proveOpeningCapacityChain(array $expected, array $actual): array
    {
        $rowsByAgreement = [];
        foreach ($expected as $key => $before) {
            $after = $actual[$key] ?? null;
            if (! is_array($after)) {
                continue;
            }
            [, $agreementId] = array_pad(explode('|', $key, 3), 3, '');
            $context = $this->openingCapacityContexts[(int) $agreementId] ?? null;
            if (! $context instanceof ReplayOpeningCapacityContext) {
                continue;
            }
            $rowsByAgreement[$context->agreementId][] = [
                'key' => $key,
                'before' => ReplayInvoiceSnapshot::fromArray($before),
                'after' => ReplayInvoiceSnapshot::fromArray($after),
            ];
        }

        $proofs = [];
        $classifier = new ReplayContractCorrectionClassifier;
        foreach ($rowsByAgreement as $agreementId => $rows) {
            $context = $this->openingCapacityContexts[$agreementId];
            usort($rows, static fn (array $left, array $right): int => [
                $left['before']->servicePeriodStart?->toDateString() ?? '',
                $left['key'],
            ] <=> [
                $right['before']->servicePeriodStart?->toDateString() ?? '',
                $right['key'],
            ]);

            $remainingMinutes = $context->capacityMinutes;
            foreach ($rows as $row) {
                if ($remainingMinutes <= 0 || ! $context->covers($row['before']->servicePeriodStart)) {
                    continue;
                }

                // The seeded month is the oldest rollover lot. Consume it from
                // every generated capacity draw in chronological order, even
                // when that row is otherwise unchanged. Only subtracting the
                // newly moved minutes lets a shared earlier draw spend the same
                // lot once and a later correction spend it again.
                $drawMinutes = $row['after']->sourceBackedCapacityDrawMinutes($context->agreementId);
                if ($drawMinutes === null) {
                    // An ambiguous generated balance line cannot preserve any
                    // capacity for a later monetary waiver.
                    break;
                }
                $proof = $classifier->historyOmittedOpeningCapacity(
                    $context->forRemainingMinutes($remainingMinutes),
                    $row['before'],
                    $row['after'],
                );
                if ($proof instanceof ReplayOpeningCapacityProof) {
                    $proofs[$row['key']] = $proof;
                }

                $remainingMinutes -= min($remainingMinutes, $drawMinutes);
            }
        }

        return $proofs;
    }

    /**
     * Everything one snapshot says about another, in one place.
     *
     * Both comparison paths ask the same questions, and the legacy pairing has
     * had to be taught each of them separately - line detail, correction facts,
     * hours, and now the invoice fields. Sharing the answer is what stops the
     * two drifting apart again.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{notes: list<string>, changed_types: list<string>, attribution_changed_types: list<string>, changed_fields: list<string>, line_money_differs: bool, metadata_differs: bool, line_repriced: bool, capacity_reallocated_at_same_rate: bool, exact_minute_arithmetic: bool, opening_recurring_item_incidence: bool, history_omitted_opening_capacity: bool, opening_capacity_also_corrects_minute_rounding: bool, hour_notes: list<string>}
     */
    private function examine(Workspace $workspace, array $before, array $after, ?string $key = null): array
    {
        $notes = [];
        // What actually changed, collected as it is found rather than read back
        // out of the notes afterwards. A note is prose for a human; an imported
        // line type can be any string the source used, so recognising types by
        // parsing text meant either losing real ones or mistaking a word of
        // prose for one. Structural markers carry a '#' for the same reason - a
        // source line type could be spelled "subtotal".
        $changedFields = [];

        if ($after['currency'] !== $before['currency']) {
            // The same integer in two currencies is not the same money, and the
            // delta alone would read as an exact match.
            $notes[] = sprintf('currency %s -> %s', $before['currency'], $after['currency']);
            $changedFields[] = 'currency';
        }
        if ($after['subtotal_amount'] !== $before['subtotal_amount']) {
            $notes[] = sprintf('subtotal %d -> %d', $before['subtotal_amount'], $after['subtotal_amount']);
            $changedFields[] = 'subtotal';
        }
        if ($after['tax_amount'] !== $before['tax_amount']) {
            $notes[] = sprintf('tax %d -> %d', $before['tax_amount'], $after['tax_amount']);
            $changedFields[] = 'tax';
        }

        [, $agreementId] = array_pad(explode('|', (string) $key, 3), 3, '');
        $beforeSnapshot = ReplayInvoiceSnapshot::fromArray($before);
        $afterSnapshot = ReplayInvoiceSnapshot::fromArray($after);
        if (isset($this->historyBasisAgreementIds[(int) $agreementId])
            && ! $afterSnapshot->sellsMonthlySuccessorOfServicePeriod()) {
            $notes[] = 'generated sold cycle is not the monthly successor of its service period';
            $changedFields[] = 'cycle';
        }

        /** @var list<array<string, mixed>> $beforeLines */
        $beforeLines = $before['lines'] ?? [];
        /** @var list<array<string, mixed>> $afterLines */
        $afterLines = $after['lines'] ?? [];

        if (count($afterLines) !== count($beforeLines)) {
            $notes[] = sprintf('line count %d -> %d', count($beforeLines), count($afterLines));
        }

        $lineDifferences = $this->lineDifferences($beforeLines, $afterLines);
        foreach ($lineDifferences['notes'] as $note) {
            $notes[] = $note;
        }

        // A line whose price, quantity or tax moved is a money difference even
        // when the invoice total lands in the same place. The agreed bar is
        // that money is exact; a charge the client did not have is not the same
        // money differently arranged.
        $lineComparison = $this->lineMultisetDifferences($beforeLines, $afterLines);
        $changedTypes = array_values(array_unique([...$lineDifferences['changed_types'], ...$lineComparison['changed_types']]));
        $attributionChangedTypes = $changedTypes;
        if ($beforeSnapshot->contractLineMultisetOfType('retainer')
            === $afterSnapshot->contractLineMultisetOfType('retainer')) {
            $attributionChangedTypes = array_values(array_filter(
                $attributionChangedTypes,
                static fn (string $type): bool => $type !== 'retainer',
            ));
        }
        $openingCapacityProof = $key === null ? null : ($this->openingCapacityProofs[$key] ?? null);
        $openingCapacityAlsoCorrectsRounding = $openingCapacityProof instanceof ReplayOpeningCapacityProof
            ? $openingCapacityProof->alsoCorrectsHistoricalMinuteRounding
            : false;

        return [
            'notes' => $notes,
            'changed_types' => $changedTypes,
            // Fixed retainer money still blocks a capacity attribution. A pure
            // wording digest change does not: the immutable contract multiset
            // proves fee, hours, dates, tax and ownership are unchanged.
            'attribution_changed_types' => $attributionChangedTypes,
            'changed_fields' => array_values(array_unique($changedFields)),
            // Strictly about the lines. The summary counts on this to tell an
            // operator a charge moved, and an invoice that only changed
            // currency has no line change to go looking for.
            'line_money_differs' => $lineComparison['money_differs'],
            // Whether anything above the lines moved, which the verdict needs
            // and the summary must not confuse with the above.
            'metadata_differs' => $changedFields !== [],
            'line_repriced' => $lineComparison['repriced'],
            'capacity_reallocated_at_same_rate' => app(ReplayContractCorrectionClassifier::class)
                ->capacityReallocatedAtSameRate($beforeSnapshot, $afterSnapshot),
            'exact_minute_arithmetic' => app(ReplayContractCorrectionClassifier::class)
                ->exactMinuteArithmetic($before, $after),
            'opening_recurring_item_incidence' => $key !== null
                && app(ReplayContractCorrectionClassifier::class)
                    ->openingRecurringItemIncidence(
                        $key,
                        $before,
                        $after,
                        $this->recurringItemIncidences[$key] ?? [],
                    ),
            'history_omitted_opening_capacity' => $openingCapacityProof instanceof ReplayOpeningCapacityProof,
            'opening_capacity_also_corrects_minute_rounding' => $openingCapacityAlsoCorrectsRounding,
            'hour_notes' => $this->hourNotes($this->hourFields($before), $this->hourFields($after)),
        ];
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
    private function reconcileLegacyPeriods(Workspace $workspace, array $comparisons): array
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

            // Pairing on the cycle total alone would call two invoices with
            // equal totals and completely different charges a match. The pair
            // gets the same line comparison an ordinary pair gets.
            /** @var list<array<string, mixed>> $historicalLines */
            $historicalLines = $historical['lines'] ?? [];
            /** @var list<array<string, mixed>> $generatedLines */
            $generatedLines = $generated['lines'] ?? [];
            /** @var array<string, mixed> $historicalSnapshot */
            $historicalSnapshot = $historical['snapshot'] ?? [];
            /** @var array<string, mixed> $generatedSnapshot */
            $generatedSnapshot = $generated['snapshot'] ?? [];
            $examined = $this->examine($workspace, $historicalSnapshot, $generatedSnapshot, (string) $generated['key']);

            $comparisons[$missingIndexes[0]] = [
                'key' => $historical['key'],
                // The pair exists because the two label the period
                // differently. Correction predicates read time entries for the
                // period in the key, so they have to read the engine's - the
                // historical label names the month before the work.
                'facts_key' => $generated['key'],
                'invoice_number' => $historical['invoice_number'],
                // Identical only when the lines say so too. Equal cycle totals
                // with different charges underneath is a composition
                // difference, and counting it green would report the pair as
                // reproducing when it did not.
                // The same three outcomes the ordinary path reaches, asked of
                // the pair. Currency is money whatever the totals say, so it
                // can never be filed as an arrangement.
                'verdict' => match (true) {
                    $delta !== 0 || $examined['line_money_differs'] || $examined['metadata_differs'] => 'money_differs',
                    $examined['notes'] !== [] => 'composition_differs',
                    default => 'match_legacy_period',
                },
                'money_delta' => $delta,
                'line_money_differs' => $examined['line_money_differs'],
                'line_repriced' => $examined['line_repriced'],
                'capacity_reallocated_at_same_rate' => $examined['capacity_reallocated_at_same_rate'],
                'exact_minute_arithmetic' => $examined['exact_minute_arithmetic'],
                'opening_recurring_item_incidence' => $examined['opening_recurring_item_incidence'],
                'history_omitted_opening_capacity' => $examined['history_omitted_opening_capacity'],
                'opening_capacity_also_corrects_minute_rounding' => $examined['opening_capacity_also_corrects_minute_rounding'],
                'changed_types' => $examined['changed_types'],
                'attribution_changed_types' => $examined['attribution_changed_types'],
                'changed_fields' => $examined['changed_fields'],
                'notes' => array_merge(
                    ['paired with the engine\'s invoice for the same cycle; history labels the period under the older period-equals-cycle convention'],
                    $delta === 0 ? [] : ['cycle total '.$this->show(-(int) $historical['money_delta']).' -> '.$this->show((int) $generated['money_delta'])],
                    $examined['notes'],
                ),
                // Hours differ between these two the same way they differ
                // anywhere else - the source stored fractional hours and this
                // schema derives them from whole minutes. Discarding them here
                // reported the pair as identical when it was only equal in
                // money.
                'hour_notes' => $examined['hour_notes'],
            ];

            unset($comparisons[$extra[$cycle][0]]);
        }

        // The snapshots were carried only so the pairing above could compare
        // them; they are not part of what this command reports.
        foreach ($comparisons as $index => $comparison) {
            unset($comparisons[$index]['snapshot']);
        }

        return array_values($comparisons);
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return array{notes: list<string>, changed_types: list<string>}
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
        $changedTypes = [];
        foreach (array_unique([...array_keys($beforeTotals), ...array_keys($afterTotals)]) as $type) {
            $b = $beforeTotals[$type] ?? 0;
            $a = $afterTotals[$type] ?? 0;
            if ($a !== $b) {
                $notes[] = sprintf('%s %d -> %d', $type, $b, $a);
                $changedTypes[] = (string) $type;
            }
        }

        // Per-type totals are the headline, and they are also not a comparison.
        // Two lines of one type moving by equal and opposite amounts sum to no
        // difference, and a unit price that changes while quantity compensates
        // leaves the total alone - both would report as an exact reproduction.
        // So the lines are also compared as a multiset of individual rows.
        foreach ($this->lineMultisetDifferences($before, $after)['notes'] as $note) {
            $notes[] = $note;
        }

        return ['notes' => $notes, 'changed_types' => array_values(array_unique($changedTypes))];
    }

    /**
     * Normalise a decimal without going through a float.
     *
     * decimal(16,4) holds values a binary float cannot separate, and a quantity
     * that differs only in the fourth decimal place is a different charge.
     */
    /**
     * A description with its numbers removed.
     *
     * What a charge is for is in the wording; what it cost is in the amounts,
     * and those are compared separately. Leaving the amounts in would make a
     * repriced line unrecognisable as the same charge - which is exactly the
     * comparison this command exists to make.
     */
    private static function withoutAmounts(string $description, string $type): string
    {
        // Only line types whose description the billing services write. A
        // milestone takes a task's title, a time line takes the entry's, and a
        // recurring item takes its own - all user text, and a title that reads
        // like a generated one must not be treated as one.
        $generated = ['retainer', 'prior_month_retainer', 'prior_month_billable', 'additional_hours', 'subcontractor'];
        if (! in_array($type, $generated, true)) {
            return $description;
        }

        // Anchored to the descriptions the billing services actually generate,
        // rather than to a shape a description happens to have. The one built
        // from user text - "Milestone: <title>" - is deliberately absent, so a
        // milestone called "Package ($100)" keeps the figure that names it even
        // though it ends in a parenthetical like the generated ones do.
        $templates = [
            '/^(Deferred work items applied to retainer) \(.*\)$/',
            '/^(Deferred work items billed on agreement termination) \(.*\)$/',
            // Greedy: a worker's name can carry its own parentheses, and only
            // the last group is the amount suffix the composer appended.
            '/^(Subcontractor: .*) \([^()]*\)$/',
            '/^(Work items applied to(?: .+?)? retainer) \(.*\)$/',
            '/^(Interim overage hours for) .+$/',
        ];

        foreach ($templates as $template) {
            if (preg_match($template, $description, $matches) === 1) {
                return $matches[1].' (#)';
            }
        }

        // The retainer fee line carries its hours in the middle and the cycle
        // dates at the end. The dates name which cycle and stay; only the hours
        // are derived from an amount.
        if (preg_match('/^(.+ Retainer) \([^()]*\)( - .+)$/', $description, $matches) === 1) {
            return $matches[1].' (#)'.$matches[2];
        }

        return $description;
    }

    private static function decimalString(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '0';
        }

        if (! str_contains($text, '.')) {
            return $text;
        }

        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    /**
     * Compare individual lines rather than totals per type.
     *
     * The signature is every field that carries meaning about what was charged
     * - what kind of line, what it was for, when, how many, at what price, and
     * with what tax. Hours are deliberately absent: the source stored fractional
     * hours and this schema derives them from whole minutes, so they are
     * reported at invoice level and never gate.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return array{notes: list<string>, money_differs: bool, repriced: bool, changed_types: list<string>}
     */
    private function lineMultisetDifferences(array $before, array $after): array
    {
        // The key carries every value whole. Shortening anything inside it -
        // a claim's uuid, say - makes two different charges compare equal, so
        // abbreviation belongs in what is printed and nowhere else.
        $signatureOf = static fn (array $line): string => implode('|', [
            (string) $line['type'],
            (int) $line['unit_amount'],
            (string) $line['quantity'],
            (int) $line['tax_amount'],
            (int) $line['total_amount'],
            (string) $line['project_id'],
            (string) $line['agreement_id'],
            (string) $line['line_date'],
            (string) $line['recurring_item_id'],
            (string) $line['claimed_by'],
            (string) $line['description_hash'],
            (int) $line['source_minutes'],
            ReplaySnapshotValue::integer($line['source_agreement_rate_minutes'] ?? null),
        ]);

        $describe = static fn (array $line): string => sprintf(
            '%s unit %d qty %s tax %d total %d project %s agreement %s on %s item %s claim %s desc %s source %d min agreement-rate %d min',
            (string) $line['type'],
            (int) $line['unit_amount'],
            (string) $line['quantity'],
            (int) $line['tax_amount'],
            (int) $line['total_amount'],
            ((string) $line['project_id']) === '' ? 'none' : (string) $line['project_id'],
            ((string) $line['agreement_id']) === '' ? 'none' : (string) $line['agreement_id'],
            ((string) $line['line_date']) === '' ? 'no date' : (string) $line['line_date'],
            ((string) $line['recurring_item_id']) === '' ? 'none' : (string) $line['recurring_item_id'],
            ((string) $line['claimed_by']) === '' ? 'none' : substr((string) $line['claimed_by'], 0, 8),
            (string) $line['description_hash'],
            (int) $line['source_minutes'],
            ReplaySnapshotValue::integer($line['source_agreement_rate_minutes'] ?? null),
        );

        /** @var array<string, string> $described */
        $described = [];
        $tally = static function (array $lines) use ($signatureOf, $describe, &$described): array {
            $counts = [];
            foreach ($lines as $line) {
                $signature = $signatureOf($line);
                $described[$signature] = $describe($line);
                $counts[$signature] = ($counts[$signature] ?? 0) + 1;
            }

            return $counts;
        };

        // What a line is *for*, separately from what it costs. A charge keeps
        // its identity across a repricing and loses it when the line is
        // reclassified, added, or removed - which is the distinction the two
        // sides of the split need.
        // Two ways to say which charge is which, used in that order.
        //
        // Where it was filed, when, and what it is - specific enough to keep
        // two concurrent charges apart. A subcontractor working two projects
        // has one line per project, identically worded, and exchanging their
        // prices moves no total and no count: only the project separates them.
        $filedAs = static fn (array $line): string => implode('|', [
            (string) $line['type'],
            (string) $line['line_date'],
            (string) $line['recurring_item_id'],
            (string) $line['project_id'],
            (string) $line['agreement_id'],
            (string) $line['claimed_by'],
            (string) $line['identity_hash'],
        ]);

        // Just what the charge is - its wording, with the amounts taken out,
        // and nothing else. Used only for what the first pass could not pair,
        // so a charge that reprices while its filing moves still finds its
        // counterpart instead of vanishing into the move. The recurring item a
        // line belongs to is filing like any other, and a line that moves
        // between items is exactly the case the recurring-item correction is
        // about - so it must not be able to hide a repricing.
        // Type belongs here, though the rest of the filing does not. Where a
        // line moves project or date it is the same charge filed elsewhere;
        // where its type changes it is a different kind of charge, and
        // reclassifying between the capacity types is one of the four things
        // this port does on purpose. Pairing across a type change would call
        // that correction's own work a repricing and refuse to explain it.
        $identity = static fn (array $line): string => implode('|', [
            (string) $line['type'],
            (string) $line['identity_hash'],
        ]);

        $priceTuple = static fn (array $line): string => sprintf(
            'unit %d qty %s tax %d total %d',
            (int) $line['unit_amount'],
            (string) $line['quantity'],
            (int) $line['tax_amount'],
            (int) $line['total_amount'],
        );

        // Prices seen for each identity, as a set rather than a multiset. Two
        // of the same charge becoming one is a line removed, which is exactly
        // what a deliberate correction is entitled to explain; the same charge
        // at a different price is not, and never becomes explainable.
        // Counted, not merely seen. Two charges under one key at the same price,
        // one of which is later repriced, leaves that price present on both
        // sides - a set says nothing changed, a count says one of them left.
        $pricesBy = static function (array $lines, callable $key) use ($priceTuple): array {
            $map = [];
            foreach ($lines as $line) {
                $tuple = $priceTuple($line);
                $map[$key($line)][$tuple] = ($map[$key($line)][$tuple] ?? 0) + 1;
            }
            foreach ($map as $k => $prices) {
                ksort($prices);
                $map[$k] = $prices;
            }

            return $map;
        };

        // Prices that appear on both sides under one key are the same charges;
        // what is left over on each side is what actually differs. Comparing
        // the sets whole would call a charge that merely moved onto an occupied
        // key a repricing, because the destination set gains a price without
        // anything being repriced.
        // Which amounts charge somebody. A line whose total is zero charges
        // nobody - the reconciliation line mirrors interim hours in its
        // quantity at a unit and total of zero - so a change between two such
        // amounts is representation, which this command reports and never
        // gates on. A change from one to the other is not: money either
        // arrived or left.
        $charged = [];
        foreach ([...$before, ...$after] as $line) {
            if ((int) $line['total_amount'] !== 0) {
                $charged[$priceTuple($line)] = true;
            }
        }

        $comparePrices = static function (array $beforeMap, array $afterMap) use ($charged): bool {
            foreach ($beforeMap as $key => $prices) {
                if (! isset($afterMap[$key])) {
                    // A key on one side alone is a line added or removed -
                    // composition, not a repricing.
                    continue;
                }

                // One price losing an occurrence while another gains one, under
                // a single key: the same charge at a different price. Counts
                // that only fall are a line removed from under that key, and
                // counts that only rise are one added. At least one of the
                // amounts involved has to charge somebody, or this is two ways
                // of writing nothing.
                $rose = false;
                $fell = false;
                $money = false;
                foreach (array_unique([...array_keys($prices), ...array_keys($afterMap[$key])]) as $tuple) {
                    $delta = ($afterMap[$key][$tuple] ?? 0) - ($prices[$tuple] ?? 0);
                    $rose = $rose || $delta > 0;
                    $fell = $fell || $delta < 0;
                    $money = $money || ($delta !== 0 && isset($charged[$tuple]));
                }

                if ($rose && $fell && $money) {
                    return true;
                }

                // One occurrence repriced while another arrives at the price it
                // left leaves that price's count where it was, so only the new
                // one rises and nothing looks like a substitution. It is
                // indistinguishable from a line simply being added - so where a
                // shared key holds charged lines on both sides and their prices
                // are not the same set, this refuses to certify rather than
                // read it the generous way.
                //
                // A judgement call, and it costs a report on a correction that
                // adds or removes a charge beside one that is already there.
                // The other way round hides a repricing, and money exact is the
                // rule this command is held to.
                $chargedBefore = array_filter($prices, static fn (string $tuple): bool => isset($charged[$tuple]), ARRAY_FILTER_USE_KEY);
                $chargedAfter = array_filter($afterMap[$key], static fn (string $tuple): bool => isset($charged[$tuple]), ARRAY_FILTER_USE_KEY);

                // Every price that was here is still here, and a new one has
                // joined them. That is a line added - and equally, one of the
                // originals repriced onto the new price while another arrived
                // at the price it left. The two are the same data.
                //
                // Deliberately one-directional: a price *leaving* while the
                // others stay is a line removed, which is what the
                // recurring-item correction does, and refusing to explain that
                // would deny the correction its own work.
                if ($chargedBefore !== []
                    && array_diff_key($chargedBefore, $chargedAfter) === []
                    && array_diff_key($chargedAfter, $chargedBefore) !== []) {
                    return true;
                }
            }

            return false;
        };

        $beforeFiled = $pricesBy($before, $filedAs);
        $afterFiled = $pricesBy($after, $filedAs);
        $repriced = $comparePrices($beforeFiled, $afterFiled);

        if (! $repriced) {
            // Whatever the first pass could not pair, tried again on what the
            // charge is alone. A line that both moved and was repriced is
            // unpaired above and paired here.
            // One occurrence consumed per match, not every line sharing a key.
            // A charge misfiled onto a key the other side already uses would
            // otherwise take that side's genuine line out of the residual with
            // it, and both would disappear from the comparison together.
            // Consumed in two rounds, exact first. A charge that matches the
            // other side in filing *and* price is unambiguously the same charge
            // unchanged; taking those out first stops a moved line being paired
            // against its unmoved neighbour and reported as a repricing.
            $residual = static function (array $lines, array $otherLines) use ($filedAs, $priceTuple): array {
                $exact = [];
                $filed = [];
                foreach ($otherLines as $other) {
                    $key = $filedAs($other);
                    $exact[$key."\0".$priceTuple($other)] = ($exact[$key."\0".$priceTuple($other)] ?? 0) + 1;
                    $filed[$key] = ($filed[$key] ?? 0) + 1;
                }

                $remaining = [];
                foreach ($lines as $line) {
                    $key = $filedAs($line);
                    $exactKey = $key."\0".$priceTuple($line);
                    if (($exact[$exactKey] ?? 0) > 0) {
                        $exact[$exactKey]--;
                        $filed[$key]--;

                        continue;
                    }

                    $remaining[] = $line;
                }

                $left = [];
                foreach ($remaining as $line) {
                    $key = $filedAs($line);
                    if (($filed[$key] ?? 0) > 0) {
                        $filed[$key]--;

                        continue;
                    }

                    $left[] = $line;
                }

                return $left;
            };

            $beforeLeft = $residual($before, $after);
            $afterLeft = $residual($after, $before);

            // A recurring item's id survives its description being rewritten,
            // which wording cannot. Where both sides still carry the same item,
            // that is an unambiguous link and worth asking before falling back
            // to what the charge says it is.
            // The two stable links a charge can carry: the recurring item it
            // bills, or the deliverable that claimed it. Both survive their
            // wording and their price being rewritten together, which is
            // exactly when nothing else can pair the two versions.
            $stableLink = static fn (array $line): string => (string) $line['recurring_item_id'] !== ''
                ? 'item:'.$line['recurring_item_id']
                : 'task:'.$line['claimed_by'];
            $itemOf = static fn (array $line): string => $stableLink($line);
            $carriesItem = static fn (array $lines): array => array_values(array_filter(
                $lines,
                static fn (array $line): bool => (string) $line['recurring_item_id'] !== '' || (string) $line['claimed_by'] !== '',
            ));

            $beforeItems = $pricesBy($carriesItem($beforeLeft), $itemOf);
            $afterItems = $pricesBy($carriesItem($afterLeft), $itemOf);
            $repriced = $comparePrices($beforeItems, $afterItems);

            // Whatever the item id could not pair - a line carrying none, or
            // one whose item the other side no longer has - falls through to
            // the wording.
            $unpairedByItem = static fn (array $lines, array $otherItems): array => array_values(array_filter(
                $lines,
                static fn (array $line): bool => ((string) $line['recurring_item_id'] === '' && (string) $line['claimed_by'] === '')
                    || ! isset($otherItems[$stableLink($line)]),
            ));

            $beforeResidual = $pricesBy($unpairedByItem($beforeLeft, $afterItems), $identity);
            $afterResidual = $pricesBy($unpairedByItem($afterLeft, $beforeItems), $identity);
            $repriced = $repriced || $comparePrices($beforeResidual, $afterResidual);

            // Fail closed where pairing cannot decide. If several charges share
            // this looser identity and carry different prices, their filing has
            // all changed and nothing links which became which - two of them
            // exchanging prices is indistinguishable from each keeping its own.
            // Certifying that as unchanged would pass a repricing; refusing
            // costs a report on the narrow case where several identically
            // worded charges move at once.
            if (! $repriced) {
                foreach ($beforeResidual as $key => $prices) {
                    // Only where both sides still hold something under this
                    // identity. A group on one side alone is unambiguously
                    // lines added or removed, and refusing to certify that
                    // would deny the corrections that add and remove lines.
                    if (! isset($afterResidual[$key])) {
                        continue;
                    }

                    if (count($prices) > 1 || count($afterResidual[$key]) > 1) {
                        $repriced = true;

                        break;
                    }
                }
            }
        }

        // Pairing by identity cannot see an amount that exists on one side and
        // nowhere on the other - a charge whose wording changed with its price,
        // say. Comparing the distinct amounts the invoice states catches that
        // without counting them, so a line merely added or removed at an amount
        // still present elsewhere stays composition.
        // Two questions, and conflating them is what made every earlier shape of
        // this wrong in one direction or the other.
        //
        // Did the money move? That is the multiset of amounts the two invoices
        // state, counts included - a charge repriced onto an amount another
        // line already carries changes only a count. It decides the verdict.
        //
        // Was an existing charge repriced? That is the pairing above and only
        // it. A line removed and another added is composition however it looks
        // in aggregate, and composition is what the four corrections exist to
        // explain, so only a repricing refuses attribution.
        $amounts = static function (array $lines) use ($priceTuple): array {
            $counts = [];
            foreach ($lines as $line) {
                $tuple = $priceTuple($line);
                $counts[$tuple] = ($counts[$tuple] ?? 0) + 1;
            }
            ksort($counts);

            return $counts;
        };

        // A substitution at the level of amounts: one goes down in count while
        // another goes up. That is a charge repriced onto an amount the invoice
        // already stated, which the pairing above cannot see when the wording
        // moved with it. Counts that only fall are lines removed, counts that
        // only rise are lines added - composition, and explainable.
        // Which amounts are actually charged for. A line whose total is zero
        // costs the client nothing, so it appearing or disappearing is
        // arrangement; a line with a total is a charge, and one arriving or
        // leaving is money whether or not something offsets it.
        $beforeAmounts = $amounts($before);
        $afterAmounts = $amounts($after);
        $chargeCountMoved = false;
        foreach (array_unique([...array_keys($beforeAmounts), ...array_keys($afterAmounts)]) as $tuple) {
            if (($afterAmounts[$tuple] ?? 0) !== ($beforeAmounts[$tuple] ?? 0) && isset($charged[$tuple])) {
                $chargeCountMoved = true;

                break;
            }
        }

        // An amount that charges somebody, stated a different number of times.
        // That covers a charge repriced onto an amount already present, and two
        // charges dropped whose totals cancel - neither of which moves a total,
        // a line count, or the set of amounts on the invoice.
        $moneyMoved = $repriced || $chargeCountMoved;

        $beforeCounts = $tally($before);
        $afterCounts = $tally($after);

        $notes = [];
        $signatures = array_unique([...array_keys($beforeCounts), ...array_keys($afterCounts)]);
        sort($signatures);

        foreach ($signatures as $signature) {
            $b = $beforeCounts[$signature] ?? 0;
            $a = $afterCounts[$signature] ?? 0;
            if ($a === $b) {
                continue;
            }

            $notes[] = sprintf('%s [%+d]', $described[$signature] ?? $signature, $a - $b);
        }

        // The types of the lines that actually differ, which is not the same as
        // the types whose net total moved: two removed lines of one type whose
        // totals cancel leave that net where it was, and attribution would then
        // never hear that the type was involved at all.
        $typeOf = [];
        foreach ([...$before, ...$after] as $line) {
            $typeOf[$signatureOf($line)] = (string) $line['type'];
        }

        $changedTypes = [];
        foreach ($signatures as $signature) {
            if (($beforeCounts[$signature] ?? 0) !== ($afterCounts[$signature] ?? 0) && isset($typeOf[$signature])) {
                $changedTypes[] = $typeOf[$signature];
            }
        }

        return [
            'notes' => $notes,
            'money_differs' => $moneyMoved,
            'repriced' => $repriced,
            'changed_types' => array_values(array_unique($changedTypes)),
        ];
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
        if ($comparison['verdict'] === 'unexpected'
            && ($comparison['contract_cadence_history_gap'] ?? false) === true) {
            $comparison['explained_by'] = [[
                'key' => 'configured_cadence_absent_from_history',
                'summary' => 'Predecessor history contains only ad-hoc invoices; SVC generated the complete configured retainer cadence from the agreement start.',
            ]];

            return $comparison;
        }

        if ($comparison['verdict'] !== 'money_differs') {
            return $comparison;
        }

        /** @var list<string> $changedTypes */
        $changedTypes = array_values(array_unique((array) (
            $comparison['attribution_changed_types'] ?? $comparison['changed_types'] ?? []
        )));
        /** @var list<string> $changedFields */
        $changedFields = array_values(array_unique((array) ($comparison['changed_fields'] ?? [])));

        if (($comparison['exact_minute_arithmetic'] ?? false) === true) {
            $comparison['explained_by'] = self::correctionScopeIsComplete(
                $changedTypes,
                $changedFields,
                ['additional_hours'],
                ['subtotal'],
            ) ? [[
                'key' => 'hourly_lines_use_exact_minutes',
                'summary' => 'Hourly totals use whole minutes; the source\'s decimal-hour arithmetic rounded differently.',
            ]] : null;

            return $comparison;
        }

        if (($comparison['opening_recurring_item_incidence'] ?? false) === true) {
            $comparison['explained_by'] = self::correctionScopeIsComplete(
                $changedTypes,
                $changedFields,
                ['recurring_item'],
                ['subtotal'],
            ) ? [[
                'key' => 'recurring_item_bills_on_configured_start',
                'summary' => 'An active recurring item bills its first incidence on its configured start date inside the sold cycle.',
            ]] : null;

            return $comparison;
        }

        if (($comparison['history_omitted_opening_capacity'] ?? false) === true) {
            if (! self::correctionScopeIsComplete(
                $changedTypes,
                $changedFields,
                ['additional_hours', 'prior_month_retainer'],
                ['subtotal'],
            )) {
                $comparison['explained_by'] = null;

                return $comparison;
            }

            $comparison['explained_by'] = [[
                'key' => 'historical_replay_start_omitted_capacity',
                'summary' => 'History charged the replay-only opening retainer but omitted that capacity from its later allocation.',
            ]];
            if (($comparison['opening_capacity_also_corrects_minute_rounding'] ?? false) === true) {
                $comparison['explained_by'][] = [
                    'key' => 'hourly_lines_use_exact_minutes',
                    'summary' => 'Hourly totals use whole minutes; the source\'s decimal-hour arithmetic rounded differently.',
                ];
            }

            return $comparison;
        }

        // A correction is a claim about which line types a period should carry,
        // never about changing a rate. Attribution works on type names, so a
        // repriced additional_hours line must normally gate. The one exception
        // is a complete DTO proof that the same rate merely moved minutes from
        // priced overage to zero-value capacity lines.
        if (($comparison['line_repriced'] ?? false) === true
            && ($comparison['capacity_reallocated_at_same_rate'] ?? false) !== true) {
            $comparison['explained_by'] = null;

            return $comparison;
        }

        // Notes carry prose as well as line detail - a legacy pair explains its
        // pairing in words - and every lowercase first word used to be read as
        // a changed line type. One stray token makes confinedTo() reject every
        // correction, so only real line types are taken.
        $comparison['explained_by'] = DeliberateCorrections::explaining(
            $changedTypes,
            $changedFields,
            $this->facts((string) ($comparison['facts_key'] ?? $comparison['key'])),
        );

        return $comparison;
    }

    /**
     * @param  list<string>  $changedTypes
     * @param  list<string>  $changedFields
     * @param  list<string>  $allowedTypes
     * @param  list<string>  $allowedFields
     */
    private static function correctionScopeIsComplete(
        array $changedTypes,
        array $changedFields,
        array $allowedTypes,
        array $allowedFields,
    ): bool {
        return $changedTypes !== []
            && $changedFields !== []
            && array_diff($changedTypes, $allowedTypes) === []
            && array_diff($changedFields, $allowedFields) === [];
    }

    /**
     * What is true of the agreement and period behind a comparison key.
     *
     * Built with the same scopes the billing engine applies to the same
     * question. This query used to select every time entry the company owned,
     * so a draft entry, a non-billable one, a flat-hourly subcontractor entry
     * or one belonging to a different workspace could set `deferredWork` or
     * `otherProjectWork` and waive a divergence that no correction had touched.
     */
    private function facts(string $key): CorrectionFacts
    {
        if (isset($this->factCache[$key])) {
            return $this->factCache[$key];
        }

        [$companyId, $agreementId, , $identity] = array_pad(explode('|', $key, 4), 4, '');
        $agreement = $agreementId === 'none' ? null : ClientAgreement::query()->find((int) $agreementId);

        [$cycle, $period] = array_pad(explode('@', $identity, 2), 2, '');
        [$periodStart, $periodEnd] = array_pad(explode('..', $period, 2), 2, '');
        $cycleStart = explode('..', $cycle, 2)[0];

        // The work that could actually have drawn on this retainer.
        $eligible = ClientTimeEntry::query()
            ->where('client_company_id', (int) $companyId)
            ->when(
                $agreement !== null,
                fn (Builder $q): Builder => $q->where('workspace_id', $agreement->workspace_id),
            )
            ->retainerBillable();

        $inPeriod = (clone $eligible)
            ->when($periodStart !== '' && $periodStart !== '?', fn (Builder $q): Builder => $q->where('worked_on', '>=', $periodStart))
            ->when($periodEnd !== '' && $periodEnd !== '?', fn (Builder $q): Builder => $q->where('worked_on', '<=', $periodEnd));

        $facts = new CorrectionFacts(
            rolloverMonths: (int) ($agreement->rollover_months ?? 0),
            fullyUsedMonthInRolloverWindow: $this->fullyUsedMonthInRolloverWindow($agreement, $eligible, $periodStart),
            projectScoped: $agreement?->client_project_id !== null,
            otherProjectWork: $agreement?->client_project_id !== null
                && (clone $inPeriod)->where('client_project_id', '!=', $agreement->client_project_id)->exists(),
            deferredWork: $agreement === null
                ? (clone $inPeriod)->where('is_deferred', true)->exists()
                : (clone $inPeriod)->forAgreementScope($agreement)->where('is_deferred', true)->exists(),
            cycleOpensMidMonth: $cycleStart !== '' && Carbon::parse($cycleStart)->day !== 1,
            recurringItemAnchoredBeforeCycleOpens: $this->recurringItemAnchoredBeforeCycleOpens($agreement, $cycleStart),
        );

        return $this->factCache[$key] = $facts;
    }

    /**
     * Did a month inside the rollover window consume its entire retainer?
     *
     * This is what the calendar-ageing correction needs to have changed
     * anything. The original aged rollover by walking stored non-zero balances,
     * so a month that used every hour it was given left no balance to walk and
     * became invisible - older lots stayed spendable past their window. A month
     * with hours left over aged correctly under both engines.
     *
     * @param  Builder<ClientTimeEntry>  $eligible
     */
    private function fullyUsedMonthInRolloverWindow(?ClientAgreement $agreement, Builder $eligible, string $periodStart): bool
    {
        $rolloverMonths = (int) ($agreement->rollover_months ?? 0);
        $retainerMinutes = (int) ($agreement->retainer_minutes ?? 0);

        if ($agreement === null || $rolloverMonths <= 0 || $retainerMinutes <= 0) {
            return false;
        }

        if ($periodStart === '' || $periodStart === '?') {
            return false;
        }

        $windowEnd = Carbon::parse($periodStart)->startOfMonth();
        $windowStart = $windowEnd->copy()->subMonths($rolloverMonths + 1);

        $monthlyMinutes = (clone $eligible)
            ->forAgreementScope($agreement)
            ->where('worked_on', '>=', $windowStart->toDateString())
            ->where('worked_on', '<', $windowEnd->toDateString())
            ->get(['worked_on', 'minutes'])
            ->groupBy(fn (ClientTimeEntry $entry): string => Carbon::parse($entry->worked_on)->format('Y-m'))
            ->map(fn (Collection $month): int => (int) $month->sum('minutes'));

        return $monthlyMinutes->contains(fn (int $minutes): bool => $minutes >= $retainerMinutes);
    }

    /**
     * Is there an item whose anchor the previous cycle already covered?
     *
     * The corrected engine bills a recurring item from its start date only in
     * the item's own first month. For that to explain a divergence there has to
     * be an item the original would have re-billed: active, anchored on a day
     * the mid-month cycle has already passed, and running since before this
     * month.
     */
    private function recurringItemAnchoredBeforeCycleOpens(?ClientAgreement $agreement, string $cycleStart): bool
    {
        if ($agreement === null || $cycleStart === '') {
            return false;
        }

        $opens = Carbon::parse($cycleStart);

        return $agreement->recurringItems()
            ->where('is_active', true)
            ->whereNotNull('anchor_day')
            ->where('anchor_day', '<', $opens->day)
            ->whereNotNull('effective_on')
            ->where('effective_on', '<', $opens->copy()->startOfMonth()->toDateString())
            ->exists();
    }

    /**
     * The aggregate hour fields, which are reported and never gate.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function hourFields(array $row): array
    {
        $fields = [];
        foreach (['hours_worked', 'hours_billed_at_rate', 'retainer_hours_included'] as $field) {
            $fields[$field] = $row[$field] ?? null;
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function hourNotes(array $before, array $after): array
    {
        $notes = [];
        foreach (['hours_worked', 'hours_billed_at_rate', 'retainer_hours_included'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $notes[] = sprintf('%s %s -> %s', $field, $this->show($before[$field] ?? null), $this->show($after[$field] ?? null));
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

        $comparisons = array_map($this->attribute(...), $comparisons);
        $outcome['comparisons'] = $comparisons;

        $matched = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'match');
        $composition = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'composition_differs');
        $legacyPeriod = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'match_legacy_period');
        $differs = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'money_differs');
        $explainedMoney = array_filter($differs, static fn (array $c): bool => ($c['explained_by'] ?? []) !== []);
        $unexplained = array_filter($differs, static fn (array $c): bool => ($c['explained_by'] ?? []) === []);
        $missing = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'missing');
        $allExtra = array_filter($comparisons, static fn (array $c): bool => $c['verdict'] === 'unexpected');
        $explainedExtra = array_filter($allExtra, static fn (array $c): bool => ($c['explained_by'] ?? []) !== []);
        $extra = array_filter($allExtra, static fn (array $c): bool => ($c['explained_by'] ?? []) === []);
        $explained = [...$explainedMoney, ...$explainedExtra];
        $hourOnly = array_filter($comparisons, static fn (array $c): bool => in_array($c['verdict'], ['match', 'match_legacy_period', 'composition_differs'], true) && ($c['hour_notes'] ?? []) !== []);

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
            '<fg=yellow>differs, explained by the current billing contract</>',
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
        $this->components->twoColumnDetail(
            '<fg=yellow>generated with no counterpart, contractually due</>',
            (string) count($explainedExtra),
        );
        $this->components->twoColumnDetail('<fg=red>generated with no counterpart</>', (string) count($extra));
        $this->components->twoColumnDetail('<fg=yellow>hours differ only</>', (string) count($hourOnly));

        $absolute = array_sum(array_map(static fn (array $c): int => abs((int) $c['money_delta']), [...$unexplained, ...$missing, ...$extra]));
        $this->components->twoColumnDetail('unexplained money divergence (minor units)', (string) $absolute);

        // A line repriced with a compensating quantity moves no net total, so
        // the figure above can read zero on a run that failed. Saying so here
        // keeps the summary honest without opening the detail report.
        $sameTotal = count(array_filter(
            $unexplained,
            static fn (array $c): bool => (int) $c['money_delta'] === 0 && ($c['line_money_differs'] ?? false) === true,
        ));
        if ($sameTotal > 0) {
            $this->components->twoColumnDetail(
                '<fg=red>of which charge the same total by different lines</>',
                (string) $sameTotal,
            );
        }

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

        $this->components->info('No unexplained historical billing divergence remains.');

        return self::SUCCESS;
    }
}
