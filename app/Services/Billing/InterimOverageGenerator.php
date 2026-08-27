<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\Balances\MonthSummary;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\HoursQuantity;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PeriodLabel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
    public function __construct(
        private readonly AgreementSelector $agreementSelector = new AgreementSelector,
        private readonly BillingCycleResolver $billingCycleResolver = new BillingCycleResolver,
        private readonly InvoiceLedgerBuilder $invoiceLedgerBuilder = new InvoiceLedgerBuilder,
        private readonly InvoiceLineComposer $invoiceLineComposer = new InvoiceLineComposer,
        private readonly InvoiceNumberAllocator $invoiceNumberAllocator = new InvoiceNumberAllocator,
        private readonly AllocationService $allocationService = new AllocationService,
    ) {}

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
    ): ?ClientInvoice {
        $periodStart = $monthStart->copy()->startOfMonth()->startOfDay();
        $agreement ??= $this->agreementSelector->agreementCoveringDate($company, $periodStart->toImmutable());

        if (! $agreement instanceof ClientAgreement) {
            throw new RuntimeException('No agreement found for this interim overage period.');
        }

        if ($agreement->effectiveBillingCadence() === BillingCadence::Monthly) {
            throw new RuntimeException('Interim overage invoices only apply to non-monthly billing cadences.');
        }

        if (! (bool) $agreement->bill_overage_interim) {
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
            return null;
        }

        return DB::transaction(function () use ($company, $agreement, $cycle, $periodStart, $periodEnd, $immediateLedger): ?ClientInvoice {
            // Serialize generation for this agreement; the invoice rows this
            // guards against may not exist yet, so the agreement is the lock.
            ClientAgreement::query()->whereKey($agreement->getKey())->lockForUpdate()->first();

            $issuedCycleInvoice = $this->cycleInvoices($company, $agreement, InvoiceKind::CadencePeriod, $cycle)
                ->whereIn('status', InvoiceStatus::charged())
                ->lockForUpdate()
                ->first();

            if ($issuedCycleInvoice instanceof ClientInvoice) {
                throw new RuntimeException("A cadence invoice (#{$issuedCycleInvoice->invoice_number}) already exists for this cycle.");
            }

            $existingInvoice = $this->cycleInvoices($company, $agreement, InvoiceKind::InterimOverage, $cycle)
                ->whereDate('service_period_start', $periodStart->toDateString())
                ->whereDate('service_period_end', $periodEnd->toDateString())
                ->where('status', '!=', 'void')
                ->lockForUpdate()
                ->first();

            if ($existingInvoice instanceof ClientInvoice && $existingInvoice->isImmutable()) {
                throw new RuntimeException("An issued interim invoice (#{$existingInvoice->invoice_number}) already exists for this period and cannot be modified.");
            }

            $immediateLedger ??= $this->invoiceLedgerBuilder->buildAgreementLedgerThrough($company, $agreement, $periodEnd, true);
            $this->assertImmediateLedgerSupportsInterimOverage($agreement, $immediateLedger, $cycle, $periodEnd);

            $cumulativeExcessHours = $this->cumulativeInterimExcessHoursThrough($agreement, $immediateLedger, $cycle, $periodEnd);
            $alreadyBilledHours = (float) $this->cycleInvoices($company, $agreement, InvoiceKind::InterimOverage, $cycle)
                ->whereDate('service_period_end', '<', $periodStart->toDateString())
                ->whereIn('status', InvoiceStatus::charged())
                ->sum('hours_billed_at_rate');

            $targetOverageHours = round(max(0.0, $cumulativeExcessHours - $alreadyBilledHours), 4);
            if ($targetOverageHours <= 0.0) {
                return null;
            }

            // An existing draft still owns its time through the pivot, so
            // selecting unbilled entries before releasing it finds nothing and
            // the refresh silently returns the stale amount. Release first, then
            // look - the same order the cadence generator uses.
            if ($existingInvoice instanceof ClientInvoice) {
                $this->invoiceLineComposer->resetSystemGeneratedLines($existingInvoice);
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
            $rateAmount = (int) ($agreement->hourly_rate_amount ?? 0);
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

            $this->invoiceLineComposer->linkAllFragmentsToLines([$line->id => $billableFragments], $splitter);

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

            (new OverpaymentCreditService)->applyCreditsToDraftInvoice($invoice);
            $invoice->recalculateTotals();

            return $invoice->fresh(['lines']);
        });
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
        $today = Carbon::now()->startOfDay();

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
                $existingInvoice = $this->cycleInvoices($company, $agreement, InvoiceKind::InterimOverage, $cycle)
                    ->whereDate('service_period_start', $periodStart->toDateString())
                    ->whereDate('service_period_end', $periodEnd->toDateString())
                    ->where('status', '!=', 'void')
                    ->first();

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
     * Hours already billed interim inside a cycle, so the cadence invoice can
     * show them as reconciled rather than charge for them again.
     */
    public function interimOverageHoursForCycle(ClientAgreement $agreement, BillingCycle $cycle): float
    {
        return round((float) ClientInvoice::query()
            ->where('workspace_id', $agreement->workspace_id)
            ->where('client_agreement_id', $agreement->id)
            ->where('invoice_kind', InvoiceKind::InterimOverage->value)
            ->whereDate('cycle_start', $cycle->start->toDateString())
            ->whereDate('cycle_end', $cycle->end->toDateString())
            // Only what was actually charged. A draft has billed nothing, so
            // counting it tells the cadence invoice those hours are settled and
            // the client is never charged for them at all.
            ->whereIn('status', InvoiceStatus::charged())
            ->sum('hours_billed_at_rate'), 4);
    }

    /**
     * Invoices of one kind belonging to a specific cycle of one agreement.
     *
     * @return Builder<ClientInvoice>
     */
    private function cycleInvoices(
        ClientCompany $company,
        ClientAgreement $agreement,
        InvoiceKind $kind,
        BillingCycle $cycle,
    ): Builder {
        return ClientInvoice::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('client_agreement_id', $agreement->id)
            ->where('invoice_kind', $kind->value)
            ->whereDate('cycle_start', $cycle->start->toDateString())
            ->whereDate('cycle_end', $cycle->end->toDateString());
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
