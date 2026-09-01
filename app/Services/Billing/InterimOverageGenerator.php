<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\Balances\MonthSummary;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\HoursQuantity;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PeriodLabel;
use App\Support\Billing\Unattributable;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Intra-cycle overage invoices for agreements that bill excess as it accrues.
 *
 * A quarterly or annual agreement normally reconciles once, at the end of the
 * cycle. An agreement with `bill_overage_interim` set instead invoices the
 * excess at each completed month boundary inside the cycle, so a client working
 * far beyond their retainer is not handed one large bill months later.
 *
 * Two properties matter and both are load-bearing:
 *
 * - The final month of a cycle is never billed interim. That month is the
 *   cadence invoice's own reconciliation, and billing it twice is the failure
 *   this whole class has to avoid.
 * - Overage is measured cumulatively across the cycle and then reduced by what
 *   earlier interim invoices already billed. Measuring month by month would
 *   double-bill an overage that a later month's unused capacity absorbs.
 *
 * Ported from the predecessor with the schema adapted. Worth knowing before
 * changing it: production has issued 75 cadence-period invoices and 3 ad-hoc
 * ones, and has never produced an interim overage invoice. The behaviour is
 * carried across for completeness, and the tests are the only thing that has
 * ever exercised it.
 */
final class InterimOverageGenerator
{
    private readonly ClientActivityRecorder $activities;

    public function __construct(
        private readonly AgreementSelector $agreementSelector = new AgreementSelector,
        private readonly BillingCycleResolver $billingCycleResolver = new BillingCycleResolver,
        private readonly InvoiceLedgerBuilder $invoiceLedgerBuilder = new InvoiceLedgerBuilder,
        private readonly InvoiceLineComposer $invoiceLineComposer = new InvoiceLineComposer,
        private readonly InvoiceNumberAllocator $invoiceNumberAllocator = new InvoiceNumberAllocator,
        private readonly AllocationService $allocationService = new AllocationService,
        private readonly TimeEntryProjectChainGuard $projectChainGuard = new TimeEntryProjectChainGuard,
        private readonly OverpaymentCreditService $overpaymentCreditService = new OverpaymentCreditService,
        ?ClientActivityRecorder $activities = null,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {
        $this->activities = $activities ?? app(ClientActivityRecorder::class);
    }

    /**
     * Generate or refresh one month's interim overage invoice inside a cycle.
     *
     * Returns null - rather than an empty invoice - whenever the month has no
     * billable overage, so no invoice number is consumed and the operator's
     * invoice list is not padded with zero-value rows.
     *
     * @param  array<int, MonthSummary>|null  $immediateLedger
     */
    public function generateInterimOverageInvoice(
        ClientCompany $company,
        Carbon $monthStart,
        ?ClientAgreement $agreement = null,
        ?array $immediateLedger = null,
        ?ClientInvoice $refreshInvoice = null,
    ): ?ClientInvoice {
        $periodStart = $monthStart->copy()->startOfMonth()->startOfDay();
        $agreement ??= $this->agreementSelector->agreementCoveringDate($company, $periodStart->toImmutable());

        if (! $agreement instanceof ClientAgreement) {
            throw new RuntimeException('No agreement found for this interim overage period.');
        }

        // An agreement handed in by a caller is not automatically this
        // company's. Without both keys checked, this path would raise an
        // invoice owned by one company carrying another's agreement id,
        // currency and rate - the cadence path was hardened against exactly
        // that and this delegated one was not.
        if ((int) $agreement->client_company_id !== (int) $company->id
            || (int) $agreement->workspace_id !== (int) $company->workspace_id) {
            throw new RuntimeException('That agreement belongs to a different client company.');
        }

        if ($agreement->effectiveBillingCadence() === BillingCadence::Monthly) {
            throw new RuntimeException('Interim overage invoices only apply to non-monthly billing cadences.');
        }

        if (! (bool) $agreement->bill_overage_interim) {
            if ($refreshInvoice instanceof ClientInvoice) {
                throw new RuntimeException('Interim overage billing is disabled for this agreement.');
            }

            return null;
        }

        $activeDate = Carbon::parse((string) $agreement->starts_on)->startOfDay();
        $terminationDate = $agreement->ends_on === null
            ? null
            : Carbon::parse((string) $agreement->ends_on)->startOfDay();

        $cycleProbe = $periodStart->lt($activeDate) ? $activeDate->copy() : $periodStart->copy();
        $cycle = $this->billingCycleResolver->cycleContaining($agreement, $cycleProbe);

        if ($periodStart->lt($cycle->start)) {
            $periodStart = $cycle->start->copy();
        }
        if ($periodStart->lt($activeDate)) {
            $periodStart = $activeDate->copy();
        }

        $periodEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        if ($periodEnd->gt($cycle->end)) {
            $periodEnd = $cycle->end->copy();
        }
        if ($terminationDate instanceof Carbon && $periodEnd->gt($terminationDate)) {
            $periodEnd = $terminationDate->copy();
        }

        // The closing month of a cycle belongs to the cadence invoice.
        if ($periodEnd->gte($cycle->end)) {
            if ($refreshInvoice instanceof ClientInvoice) {
                throw new RuntimeException('An interim draft cannot cover the closing month of its cadence cycle.');
            }

            return null;
        }

        return DB::transaction(function () use ($company, $agreement, $cycle, $periodStart, $periodEnd, $immediateLedger, $refreshInvoice): ?ClientInvoice {
            // Serialize generation for this agreement; the invoice rows this
            // guards against may not exist yet, so the agreement is the lock.
            ClientAgreement::query()->whereKey($agreement->getKey())->lockForUpdate()->first();

            // Callers may supply a cached ledger, bypassing its own integrity
            // check. Keep the assertion at this public write boundary so no
            // direct or delegated interim path can invoice a broken chain.
            $this->projectChainGuard->assertCompanyProjectChainsAgree($company);

            $issuedCycleInvoice = $this->cycleInvoices($company, $agreement, InvoiceKind::CadencePeriod, $cycle, Unattributable::Include)
                ->whereIn('status', InvoiceStatus::charged())
                ->lockForUpdate()
                ->first();

            if ($issuedCycleInvoice instanceof ClientInvoice) {
                throw new RuntimeException("A cadence invoice (#{$issuedCycleInvoice->invoice_number}) already exists for this cycle.");
            }

            $existingInvoice = $this->selectSingleInterimInvoice(
                $this->interimInvoiceCandidates($company, $agreement, $cycle, $periodStart, $periodEnd)
                    ->lockForUpdate()
                    ->get(),
                $refreshInvoice,
            );

            if ($existingInvoice instanceof ClientInvoice && $existingInvoice->isImmutable()) {
                throw new RuntimeException("An issued interim invoice (#{$existingInvoice->invoice_number}) already exists for this period and cannot be modified.");
            }

            $immediateLedger ??= $this->invoiceLedgerBuilder->buildAgreementLedgerThrough($company, $agreement, $periodEnd, true);
            $this->assertImmediateLedgerSupportsInterimOverage($agreement, $immediateLedger, $cycle, $periodEnd);

            $cumulativeExcessHours = $this->cumulativeInterimExcessHoursThrough($agreement, $immediateLedger, $cycle, $periodEnd);
            $alreadyBilledHours = $this->alreadyBilledInterimHoursBeforePeriod(
                $company,
                $agreement,
                $cycle,
                $periodStart,
            );

            // An existing draft still owns its time through the pivot, so
            // selecting unbilled entries before releasing it finds nothing and
            // the refresh silently returns the stale amount. Release first, then
            // look - the same order the cadence generator uses.
            //
            // This runs before the target check, not after it. When the
            // underlying time is reduced, deleted or made non-billable the
            // target falls to zero, and returning at that point left the stale
            // charge and its pivots on the draft - so the cadence invoice then
            // skipped work that an interim overage no longer claimed.
            $wasCreated = ! $existingInvoice instanceof ClientInvoice;
            if ($existingInvoice instanceof ClientInvoice) {
                $this->invoiceLineComposer->resetSystemGeneratedLines($existingInvoice);
            }

            $targetOverageHours = round(max(0.0, $cumulativeExcessHours - $alreadyBilledHours), 4);
            if ($targetOverageHours <= 0.0) {
                $this->finishEmptiedDraft($existingInvoice);

                return null;
            }

            $this->allocationService->recombineUnlinkedFragments($company->workspace, $company);

            $entries = ClientTimeEntry::query()
                ->where('workspace_id', $company->workspace_id)
                ->where('client_company_id', $company->id)
                ->unbilled()
                ->where('is_billable', true)
                ->where('is_deferred', false)
                ->retainerBillable()
                // Missing here until now: an interim overage drew on every
                // project's time even when the agreement covered only one.
                ->forAgreementScope($agreement)
                ->whereBetween('worked_on', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->orderBy('worked_on')
                ->orderBy('id')
                ->get();

            $entryHours = round(((int) $entries->sum('minutes')) / 60, 4);
            $overageHours = round(min($targetOverageHours, $entryHours), 4);
            if ($overageHours <= 0.0) {
                $this->finishEmptiedDraft($existingInvoice);

                return null;
            }

            if ($existingInvoice instanceof ClientInvoice) {
                $invoice = $existingInvoice;
                $invoice->update([
                    'service_period_start' => $periodStart,
                    'service_period_end' => $periodEnd,
                    'cycle_start' => $cycle->start,
                    'cycle_end' => $cycle->end,
                    'invoice_kind' => InvoiceKind::InterimOverage->value,
                    'status' => 'draft',
                ]);
                // Lines were already released above, before entries were selected.
            } else {
                // Allocated only on create. The predecessor re-derived the number
                // on every refresh; this counter is monotonic per workspace, so
                // doing that here would burn a number on each regeneration.
                $invoice = ClientInvoice::query()->create([
                    'workspace_id' => $company->workspace_id,
                    'client_company_id' => $company->id,
                    'client_agreement_id' => $agreement->id,
                    'service_period_start' => $periodStart,
                    'service_period_end' => $periodEnd,
                    'invoice_number' => $this->invoiceNumberAllocator->next($company->workspace),
                    'currency' => (string) $agreement->currency,
                    'subtotal_amount' => 0,
                    'tax_amount' => 0,
                    'total_amount' => 0,
                    'status' => 'draft',
                    'invoice_kind' => InvoiceKind::InterimOverage->value,
                    'cycle_start' => $cycle->start,
                    'cycle_end' => $cycle->end,
                ]);
            }

            // Capacity is set so the splitter treats everything above the
            // non-overage remainder as billable; the retainer itself is
            // reconciled by the cadence invoice, not here.
            $splitter = new TimeEntrySplitter;
            $plan = $splitter->allocateTimeEntries($entries, max(0.0, $entryHours - $overageHours), 0.0, 0.0);

            $billableFragments = array_merge($plan->catchUpFragments, $plan->billableCatchupFragments);
            // A null rate refuses rather than billing at zero; see
            // {@see ClientAgreement::hourlyRateAmountOrFail()}.
            $rateAmount = $agreement->hourlyRateAmountOrFail();
            $overageMinutes = (int) round($overageHours * 60);

            $line = ClientInvoiceLine::query()->create([
                'workspace_id' => $company->workspace_id,
                'client_invoice_id' => $invoice->id,
                'client_agreement_id' => $agreement->id,
                'description' => 'Interim overage hours for '.$periodStart->format('F Y'),
                'quantity' => HoursQuantity::decimal($overageHours),
                'unit_amount' => $rateAmount,
                'tax_amount' => 0,
                'total_amount' => MoneyService::hourlyAmount($overageMinutes, $rateAmount),
                'type' => InvoiceLineType::AdditionalHours->value,
                'hours' => $overageHours,
                'line_date' => $periodEnd,
                'sort_order' => 1,
            ]);

            $this->invoiceLineComposer->linkAllFragmentsToLines($company, [$line->id => $billableFragments], $splitter);

            $monthSummary = $this->invoiceLedgerBuilder->findLedgerMonth(
                $immediateLedger,
                $periodEnd->format('Y-m'),
                $cycle->start->format('Y-m-d'),
            );

            $invoice->update([
                'retainer_hours_included' => 0,
                'hours_worked' => $entryHours,
                'rollover_hours_used' => $monthSummary?->closing->hoursUsedFromRollover ?? 0,
                'unused_hours_balance' => $monthSummary === null
                    ? 0
                    : $monthSummary->closing->unusedHours + $monthSummary->closing->remainingRollover,
                'negative_hours_balance' => 0,
                'starting_unused_hours' => $monthSummary?->opening->rolloverHours ?? 0,
                'starting_negative_hours' => $monthSummary === null
                    ? 0
                    : $monthSummary->opening->negativeOffset + $monthSummary->opening->remainingNegativeBalance,
                'hours_billed_at_rate' => $overageHours,
            ]);

            $this->overpaymentCreditService->applyCreditsToDraftInvoice($invoice);
            $invoice->recalculateTotals();
            $this->recordInvoiceActivity($company, $invoice, $wasCreated);

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * Finish a refresh that removed the last generated overage line.
     *
     * `resetSystemGeneratedLines()` releases the pivots and deletes the line,
     * but the invoice's cached money and overage-hour columns otherwise keep
     * describing the charge that just disappeared. Issuance trusts those
     * columns, so every early return after the reset must close the row too.
     * Manual adjustment lines, if any, remain and are included in the total.
     */
    private function finishEmptiedDraft(?ClientInvoice $invoice): void
    {
        if (! $invoice instanceof ClientInvoice) {
            return;
        }

        $invoice->update(['hours_billed_at_rate' => 0]);
        $this->overpaymentCreditService->applyCreditsToDraftInvoice($invoice->refresh());
        $this->recordInvoiceActivity($invoice->clientCompany, $invoice->refresh(), false);
    }

    /**
     * Fill in every interim invoice a cycle should already have.
     *
     * Walks the completed month boundaries inside the cycle. Months whose
     * interim invoice has already been issued or paid are left alone; drafts are
     * refreshed in place.
     *
     * @param  array<int, MonthSummary>|null  $immediateLedger
     * @return array{generated: list<array<string, mixed>>, updated: list<array<string, mixed>>}
     */
    public function ensureInterimOveragesForCycle(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $cycle,
        ?array $immediateLedger = null,
    ): array {
        $results = ['generated' => [], 'updated' => []];

        if ($agreement->effectiveBillingCadence() === BillingCadence::Monthly || ! (bool) $agreement->bill_overage_interim) {
            return $results;
        }

        $cursor = $cycle->start->copy()->startOfMonth();
        $today = Carbon::instance($this->clock->today($company->workspace));

        while ($cursor->lte($cycle->end)) {
            $periodStart = $cursor->copy()->startOfMonth();
            if ($periodStart->lt($cycle->start)) {
                $periodStart = $cycle->start->copy();
            }

            $periodEnd = $cursor->copy()->endOfMonth()->startOfDay();
            if ($periodEnd->gt($cycle->end)) {
                $periodEnd = $cycle->end->copy();
            }

            // Only completed months, and never the cycle's own closing month.
            if ($periodEnd->lt($cycle->end) && $periodEnd->lte($today)) {
                $existingInvoice = $this->selectSingleInterimInvoice(
                    $this->interimInvoiceCandidates($company, $agreement, $cycle, $periodStart, $periodEnd)->get(),
                );

                if ($existingInvoice instanceof ClientInvoice
                    && InvoiceStatus::hasChargedValue($existingInvoice->status)) {
                    $cursor->addMonth()->startOfMonth();

                    continue;
                }

                $invoice = $this->generateInterimOverageInvoice($company, $periodStart, $agreement, $immediateLedger);
                if ($invoice instanceof ClientInvoice) {
                    $result = [
                        'period' => PeriodLabel::for($periodStart, $periodEnd),
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_kind' => $invoice->invoiceKindValue(),
                    ];

                    $results[$existingInvoice instanceof ClientInvoice ? 'updated' : 'generated'][] = $result;
                }
            }

            $cursor->addMonth()->startOfMonth();
        }

        return $results;
    }

    /**
     * Give back the work an uncharged interim draft is holding.
     *
     * An interim draft pivot-links its overage entries the moment it is
     * created, but {@see interimOverageHoursForCycle()} counts only invoices
     * that actually charged someone. So a draft interim held work the cadence
     * selector could no longer see - `unbilled()` skips a linked entry - while
     * contributing nothing to the reconciliation that would have billed it.
     * The hours belonged to neither invoice and the client was charged for
     * them by neither.
     *
     * A draft has charged nobody, which is the rule everywhere else here, so
     * the cadence invoice takes the work. Anything issued keeps its claim.
     */
    public function releaseUnchargedInterimClaims(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $cycle,
    ): int {
        // Named, not "everything that is not settled". `NOT IN` reads a status
        // this code does not recognise as releasable, which is the opposite of
        // the rule everywhere else here: an unrecognised status is one this
        // code cannot show is safe to rewrite, and it may already have charged
        // someone.
        $drafts = $this->cycleInvoices($company, $agreement, InvoiceKind::InterimOverage, $cycle, Unattributable::Exclude)
            ->where('status', InvoiceStatus::Draft->value)
            // Locked and rechecked. The cadence path holds the agreement, and
            // `InvoiceLifecycleService::issue()` locks the invoice and the
            // company - so nothing stops an operator issuing this draft between
            // the read and the delete, and the lines would then be stripped from
            // an invoice that had just been sent.
            ->lockForUpdate()
            ->get();

        $released = 0;

        foreach ($drafts as $draft) {
            $draft->refresh();

            if (InvoiceStatus::isSettledValue($draft->status)) {
                continue;
            }

            $this->invoiceLineComposer->resetSystemGeneratedLines($draft);
            $draft->update(['hours_billed_at_rate' => 0]);
            // The lines are gone, so the stored totals describe a charge that no
            // longer exists - and `issue()` would send that number.
            $draft->refresh()->recalculateTotals();
            $this->recordInvoiceActivity($company, $draft->refresh(), false);
            $released++;
        }

        return $released;
    }

    private function recordInvoiceActivity(ClientCompany $company, ClientInvoice $invoice, bool $wasCreated): void
    {
        $this->activities->record(
            $company->workspace,
            $company,
            $wasCreated ? 'invoice.generated' : 'invoice.updated',
            $invoice,
            [
                'invoice_kind' => $invoice->invoiceKindValue(),
                'status' => $invoice->status,
                'total_amount' => $invoice->total_amount,
                'currency' => $invoice->currency,
            ],
            occurrence: $wasCreated ? null : 'generation-'.$invoice->lock_version,
        );
    }

    /**
     * Hours already billed interim inside a cycle, so the cadence invoice can
     * show them as reconciled rather than charge for them again.
     */
    public function interimOverageHoursForCycle(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $cycle,
    ): float {
        $candidates = $this->cycleInvoices(
            $company,
            $agreement,
            InvoiceKind::InterimOverage,
            $cycle,
            Unattributable::Include,
        )
            // Only what was actually charged. A draft has billed nothing, so
            // counting it tells the cadence invoice those hours are settled and
            // the client is never charged for them at all.
            ->whereIn('status', InvoiceStatus::charged())
            ->get();

        return $this->attributableInterimHours($candidates, $cycle);
    }

    /**
     * Invoices of one kind belonging to a specific cycle of one agreement.
     *
     * `cycle_start` and `cycle_end` are nullable. A comparison with SQL `NULL`
     * produces UNKNOWN, which a `WHERE` clause excludes, so an invoice missing
     * either was invisible to every caller here (#141). Generated rows always
     * carry both - the generator writes them - so the exposure is imported and
     * hand-edited data, which `ExternalImportService` passes through unchanged.
     *
     * Whether that invisibility is safe depends entirely on what the caller is
     * asking, which is why this takes the answer rather than picking one. A
     * blanket `orWhereNull` would be wrong in one direction and a blanket
     * exclusion is wrong in the other:
     *
     * - **Guards and sums** pass `Unattributable::Include`. A row that cannot be
     *   placed *might* be this cycle's, and for a duplicate guard the cost of
     *   assuming it is is a refusal an operator can look at, while the cost of
     *   assuming it is not is a second invoice for a cycle already billed. For
     *   a sum of what has already been charged it is the #135 answer: dropping
     *   the row bills its hours again.
     * - **Anything that rewrites what it selects** passes
     *   `Unattributable::Exclude`. `releaseUnchargedInterimClaims()`
     *   strips a draft's system-generated lines and zeroes its charge, so
     *   including a row that cannot be placed wipes a claim that was not this
     *   cycle's to wipe - a worse error than leaving it, which merely leaves a
     *   draft for someone to look at.
     *
     * `invoice_kind` is nullable too and is deliberately left strict here. A
     * null-kind row matching an interim lookup would let a migrated cadence
     * invoice be picked up and rewritten as an interim draft, which is a
     * different and worse failure than the one this addresses.
     *
     * @return Builder<ClientInvoice>
     */
    private function cycleInvoices(
        ClientCompany $company,
        ClientAgreement $agreement,
        InvoiceKind $kind,
        BillingCycle $cycle,
        Unattributable $unattributable,
    ): Builder {
        return ClientInvoice::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('client_agreement_id', $agreement->id)
            ->where('invoice_kind', $kind->value)
            // Per boundary, not across both. Widening as
            // `(start = X AND end = Y) OR start IS NULL OR end IS NULL` discards
            // the boundary a half-dated row *does* have: an invoice with a null
            // start and a known end of 31 March would satisfy the null branch
            // for every cycle, blocking interim billing for April onward and
            // subtracting its hours from cycles it has nothing to do with. A row
            // stays fail-closed only for the cycles its known data cannot rule
            // out, which for a fully undated row is still all of them.
            ->where(function (Builder $cycleWindow) use ($cycle, $unattributable): void {
                $this->boundary($cycleWindow, 'cycle_start', $cycle->start->toDateString(), $unattributable);
                $this->boundary($cycleWindow, 'cycle_end', $cycle->end->toDateString(), $unattributable);
            });
    }

    /**
     * One cycle boundary: the stated date, or - when an unplaceable row counts -
     * no date at all.
     *
     * @param  Builder<ClientInvoice>  $query
     */
    private function boundary(Builder $query, string $column, string $date, Unattributable $unattributable): void
    {
        $query->where(function (Builder $side) use ($column, $date, $unattributable): void {
            $side->whereDate($column, $date);

            if ($unattributable === Unattributable::Include) {
                $side->orWhereNull($column);
            }
        });
    }

    /** @return Builder<ClientInvoice> */
    private function interimInvoiceCandidates(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $cycle,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): Builder {
        return $this->cycleInvoices($company, $agreement, InvoiceKind::InterimOverage, $cycle, Unattributable::Include)
            ->whereDate('service_period_start', $periodStart->toDateString())
            ->whereDate('service_period_end', $periodEnd->toDateString())
            ->whereIn('status', InvoiceStatus::live());
    }

    /**
     * Choose a draft only after the complete matching set has been inspected.
     *
     * A legacy null-cycle draft and a later exact-cycle invoice can both match
     * the widened query. Selecting an unordered `first()` lets the draft win
     * and be reset even when another candidate is immutable. Duplicate mutable
     * drafts are no safer to choose between, so every multi-row result requires
     * repair before generation continues.
     *
     * @param  Collection<int, ClientInvoice>  $candidates
     */
    private function selectSingleInterimInvoice(
        Collection $candidates,
        ?ClientInvoice $refreshInvoice = null,
    ): ?ClientInvoice {
        if ($candidates->count() > 1) {
            throw new RuntimeException('Multiple live interim invoices match this period; repair the duplicate rows before generation.');
        }

        $candidate = $candidates->first();

        if ($refreshInvoice instanceof ClientInvoice
            && (! $candidate instanceof ClientInvoice || $candidate->id !== $refreshInvoice->id)) {
            throw new RuntimeException('The interim draft no longer matches the agreement period and cycle it would regenerate.');
        }

        return $candidate;
    }

    /**
     * Hours charged by earlier interim invoices that can belong to this cycle.
     *
     * A row with both cycle boundaries missing is not a wildcard forever. Its
     * service-period dates are the remaining evidence: a Q1 period rules the
     * row out of Q2, while a one-sided period remains fail-closed only for the
     * cycles that boundary cannot exclude. If neither pair can place the row,
     * continuing would silently subtract it from every future cycle, so billing
     * stops for repair.
     */
    private function alreadyBilledInterimHoursBeforePeriod(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $cycle,
        Carbon $periodStart,
    ): float {
        $candidates = $this->cycleInvoices($company, $agreement, InvoiceKind::InterimOverage, $cycle, Unattributable::Include)
            ->where(function (Builder $window) use ($periodStart): void {
                $window
                    ->whereDate('service_period_start', '<=', $periodStart->toDateString())
                    ->orWhereNull('service_period_start');
            })
            ->where(function (Builder $window) use ($periodStart): void {
                $window
                    ->whereDate('service_period_end', '<', $periodStart->toDateString())
                    ->orWhereNull('service_period_end');
            })
            ->whereIn('status', InvoiceStatus::charged())
            ->lockForUpdate()
            ->get();

        return $this->attributableInterimHours($candidates, $cycle);
    }

    /**
     * @param  Collection<int, ClientInvoice>  $candidates
     */
    private function attributableInterimHours(Collection $candidates, BillingCycle $cycle): float
    {
        $hours = 0.0;

        foreach ($candidates as $invoice) {
            $canBelongToCycle = true;

            if ($invoice->cycle_start === null && $invoice->cycle_end === null) {
                if ($invoice->service_period_start === null && $invoice->service_period_end === null) {
                    throw new RuntimeException(
                        "Interim invoice (#{$invoice->invoice_number}) has neither cycle nor service-period dates and must be repaired before billing can continue.",
                    );
                }

                $canBelongToCycle = $invoice->service_period_start?->gt($cycle->end) !== true
                    && $invoice->service_period_end?->lt($cycle->start) !== true;
            }

            if ($canBelongToCycle) {
                $hours += (float) ($invoice->hours_billed_at_rate ?? 0);
            }
        }

        return round($hours, 4);
    }

    /**
     * The interim path reads `closing->excessHours` as "billable now", which is
     * only true of a ledger built to bill excess immediately. Reading an
     * ordinary ledger here would silently bill hours the retainer still covers,
     * so this refuses rather than guesses.
     *
     * @param  array<int, MonthSummary>  $immediateLedger
     */
    private function assertImmediateLedgerSupportsInterimOverage(
        ClientAgreement $agreement,
        array $immediateLedger,
        BillingCycle $cycle,
        Carbon $periodEnd,
    ): void {
        $cycleMonthStart = $this->invoiceLedgerBuilder->cycleMonthStartForLegacyMonthlyLedger($agreement, $cycle);
        $periodMonthEnd = $this->invoiceLedgerBuilder->cycleMonthEndForLegacyMonthlyLedger($agreement, $cycle, $periodEnd);
        $cycleStartKey = $cycle->start->format('Y-m-d');

        foreach ($immediateLedger as $summary) {
            if (! $this->invoiceLedgerBuilder->ledgerRowBelongsToCycleThrough($summary, $cycleStartKey, $cycleMonthStart, $periodMonthEnd)) {
                continue;
            }

            if (! $summary->billExcessImmediately) {
                throw new LogicException('Interim overage invoices require a ledger built with billExcessImmediately=true.');
            }
        }
    }

    /**
     * Overage accrued from the start of the cycle through this month.
     *
     * Cumulative on purpose: a month of heavy overage followed by a quiet month
     * nets out, and billing each month in isolation would charge for the first
     * without ever crediting the second.
     *
     * @param  array<int, MonthSummary>  $immediateLedger
     */
    private function cumulativeInterimExcessHoursThrough(
        ClientAgreement $agreement,
        array $immediateLedger,
        BillingCycle $cycle,
        Carbon $periodEnd,
    ): float {
        $cycleMonthStart = $this->invoiceLedgerBuilder->cycleMonthStartForLegacyMonthlyLedger($agreement, $cycle);
        $periodMonthEnd = $this->invoiceLedgerBuilder->cycleMonthEndForLegacyMonthlyLedger($agreement, $cycle, $periodEnd);
        $cycleStartKey = $cycle->start->format('Y-m-d');

        $total = 0.0;
        foreach ($immediateLedger as $summary) {
            if ($this->invoiceLedgerBuilder->ledgerRowBelongsToCycleThrough($summary, $cycleStartKey, $cycleMonthStart, $periodMonthEnd)) {
                $total += $summary->closing->excessHours;
            }
        }

        return round($total, 4);
    }
}
