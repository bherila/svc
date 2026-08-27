<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use App\Services\Billing\Balances\TimeEntryFragment;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\BillingCadenceLabel;
use App\Support\Billing\FirstCycleProration;
use App\Support\Billing\HoursQuantity;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\PeriodLabel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns time, agreements and retainer balances into invoices.
 *
 * This is the top of the billing engine: everything below it - rollover,
 * retainer arithmetic, splitting, deferred allocation, line composition - is
 * called from here, and this is the only place that decides *which* invoices
 * should exist.
 *
 * ## What an invoice covers
 *
 * An invoice reconciles the cycle that just ended while billing the next one in
 * advance. For a monthly agreement generating in February:
 *
 * 1. January work drawn against the retainer, dated 31 Jan, at no charge
 * 2. January work beyond it, charged at the hourly rate
 * 3. The February retainer fee, dated 1 Feb
 * 4. Milestones, recurring items and subcontractor time
 *
 * The two date pairs on the row mean different things and are easy to confuse:
 * `service_period_start`/`_end` are the *work* being reconciled, `cycle_start`/
 * `_end` are the *retainer* being sold. Both are needed - matching only on the
 * work period misses invoices written under the older "period == cycle"
 * convention, which is why the retainer-period guard exists separately.
 *
 * ## Adapting the port
 *
 * The arithmetic is the predecessor's, unchanged. What differs here is the
 * schema: money is integer minor units rather than decimal dollars, a time
 * entry's invoice line is a pivot row rather than a column, and every query is
 * workspace-scoped. Reimbursable expenses are absent because this schema has no
 * expense table and the source had no rows; if that table returns, the hook is
 * one call beside the milestone one.
 *
 * @phpstan-type GenerationResults array{
 *     generated: list<array<string, mixed>>,
 *     updated: list<array<string, mixed>>,
 *     skipped: list<array<string, mixed>>,
 *     summary: array{
 *         generated_count: int,
 *         updated_count: int,
 *         skipped_count: int,
 *         cadence_period_invoices_created: int,
 *         interim_invoices_created: int,
 *         zero_activity_skipped_count: int
 *     }
 * }
 */
final class ClientInvoicingService
{
    /**
     * Recorded when a cycle is suppressed because the agreement carries no
     * retainer and the period saw no billable activity.
     */
    public const SKIP_REASON_ZERO_ACTIVITY = 'zero_activity_non_retainer';

    /**
     * Deferred entries the most recent generation could not fit into remaining
     * retainer capacity.
     *
     * Kept so the invoice UI can tell a client "this work is recorded and will
     * appear on a later invoice" rather than leaving it invisible.
     *
     * @var list<array{id: int, hours: float, date_worked: string, name: string|null}>
     */
    private array $deferredSkipped = [];

    private readonly RetainerCalculator $retainerCalculator;

    private readonly InvoiceLedgerBuilder $invoiceLedgerBuilder;

    private readonly InterimOverageGenerator $interimOverageGenerator;

    public function __construct(
        private readonly RolloverCalculator $rolloverCalculator = new RolloverCalculator,
        private readonly BillingCycleResolver $billingCycleResolver = new BillingCycleResolver,
        private readonly RecurringItemBiller $recurringItemBiller = new RecurringItemBiller,
        private readonly InvoiceNumberAllocator $invoiceNumberAllocator = new InvoiceNumberAllocator,
        private readonly AgreementSelector $agreementSelector = new AgreementSelector,
        private readonly InvoiceLineComposer $invoiceLineComposer = new InvoiceLineComposer,
        private readonly AllocationService $allocationService = new AllocationService,
        private readonly DeferredBillingAllocator $deferredBillingAllocator = new DeferredBillingAllocator,
        private readonly OverpaymentCreditService $overpaymentCreditService = new OverpaymentCreditService,
        private readonly TimeEntrySplitter $timeEntrySplitter = new TimeEntrySplitter,
        ?RetainerCalculator $retainerCalculator = null,
        ?InvoiceLedgerBuilder $invoiceLedgerBuilder = null,
        ?InterimOverageGenerator $interimOverageGenerator = null,
    ) {
        // These three share the collaborators above, so they are wired here
        // rather than defaulted in the signature - a second RolloverCalculator
        // would not be wrong, but it would make the object graph a lie.
        $this->retainerCalculator = $retainerCalculator ?? new RetainerCalculator($this->billingCycleResolver);
        $this->invoiceLedgerBuilder = $invoiceLedgerBuilder ?? new InvoiceLedgerBuilder(
            $this->rolloverCalculator,
            $this->billingCycleResolver,
            $this->retainerCalculator,
        );
        $this->interimOverageGenerator = $interimOverageGenerator ?? new InterimOverageGenerator(
            $this->agreementSelector,
            $this->billingCycleResolver,
            $this->invoiceLedgerBuilder,
            $this->invoiceLineComposer,
            $this->invoiceNumberAllocator,
            $this->allocationService,
        );
    }

    /**
     * Deferred work skipped by the most recent generation call.
     *
     * @return list<array{id: int, hours: float, date_worked: string, name: string|null}>
     */
    public function lastDeferredSkipped(): array
    {
        return $this->deferredSkipped;
    }

    /**
     * Generate every invoice a company is currently owed, across all of its
     * agreement segments.
     *
     * @return GenerationResults
     */
    public function generateAllInvoices(ClientCompany $company): array
    {
        $results = $this->emptyGenerationResults();
        $agreements = $this->agreementSelector->agreementsForInvoiceGeneration($company);

        foreach ($agreements as $agreement) {
            $successorAgreement = $this->agreementSelector->successorAgreementForGeneration($agreements, $agreement);
            $results = $this->mergeGenerationResults(
                $results,
                $this->generateAllInvoicesForAgreement($company, $agreement, $successorAgreement),
            );
        }

        return $results;
    }

    /**
     * Generate every monthly invoice from the agreement's start to now.
     *
     * @return GenerationResults
     */
    public function generateAllMonthlyInvoices(ClientCompany $company): array
    {
        $agreement = $this->agreementSelector->agreementForInvoiceGeneration($company);

        if ($agreement->effectiveBillingCadence() !== BillingCadence::Monthly) {
            throw new RuntimeException(
                'generateAllMonthlyInvoices only supports monthly agreements. Use generateAllInvoices for cadence-aware generation.',
            );
        }

        return $this->generateAllInvoicesForAgreement($company, $agreement);
    }

    /**
     * Generate an invoice for one explicit work period.
     *
     * A period matching the agreement's own cycle is routed through the normal
     * cadence path. A monthly agreement additionally accepts an arbitrary range,
     * which is how an operator issues a correcting invoice; a non-monthly one
     * does not, because a partial cadence cycle has no defined retainer.
     */
    public function generateInvoice(
        ClientCompany $company,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?ClientAgreement $agreement = null,
    ): ClientInvoice {
        $agreement ??= $company->activeAgreement();
        if (! $agreement instanceof ClientAgreement) {
            throw new RuntimeException('No active agreement found for this client company.');
        }

        // A caller-supplied agreement is not automatically this company's. Left
        // unchecked, the invoice is written under one tenant while its terms and
        // rates come from another.
        if ($agreement->workspace_id !== $company->workspace_id || $agreement->client_company_id !== $company->id) {
            throw new RuntimeException('That agreement belongs to a different client company.');
        }

        $workCycle = $this->billingCycleResolver->cycleContaining($agreement, $periodStart);
        $matchesCycle = $periodStart->isSameDay($workCycle->start) && $periodEnd->isSameDay($workCycle->end);

        if (! $matchesCycle) {
            if ($agreement->effectiveBillingCadence() !== BillingCadence::Monthly) {
                throw new RuntimeException(
                    'Manual invoices inside an active '.$agreement->effectiveBillingCadence()->value.
                    ' billing cycle are not supported. Generate the full cadence cycle instead.',
                );
            }

            return $this->generateMonthlyInvoiceForWorkPeriod($company, $periodStart, $periodEnd, $agreement);
        }

        return $this->generateInvoiceForPeriod($company, $agreement, $this->nextBillingCycle($agreement, $workCycle));
    }

    /**
     * Generate or refresh an interim overage invoice for one month.
     *
     * @param  array<int, MonthSummary>|null  $immediateLedger
     */
    public function generateInterimOverageInvoice(
        ClientCompany $company,
        Carbon $monthStart,
        ?ClientAgreement $agreement = null,
        ?array $immediateLedger = null,
    ): ?ClientInvoice {
        return $this->interimOverageGenerator->generateInterimOverageInvoice($company, $monthStart, $agreement, $immediateLedger);
    }

    /**
     * Walk one agreement segment's retainer periods, generating what is missing.
     *
     * @return GenerationResults
     */
    private function generateAllInvoicesForAgreement(
        ClientCompany $company,
        ClientAgreement $agreement,
        ?ClientAgreement $successorAgreement = null,
    ): array {
        $generated = [];
        $updated = [];
        $skipped = [];

        $through = $this->retainerGenerationThroughDate($agreement, $successorAgreement);
        $billExcessImmediately = $agreement->effectiveBillingCadence() !== BillingCadence::Monthly
            && (bool) $agreement->bill_overage_interim;
        $ledger = $this->invoiceLedgerBuilder->buildAgreementLedgerThrough($company, $agreement, $through, $billExcessImmediately);
        $immediateLedger = $billExcessImmediately ? $ledger : null;

        $monthsWithUnbilledPostTermination = null;
        $activeDate = $this->agreementStart($agreement);

        foreach ($this->retainerPeriodsThrough($agreement, $through) as $retainerPeriod) {
            $workCycle = $this->previousBillingCycle($agreement, $retainerPeriod);
            $existingInvoice = $this->findGeneratedInvoiceForWorkCycle($company, $agreement, $workCycle);
            $periodLabel = $this->generationPeriodLabel($agreement, $workCycle, $retainerPeriod);

            if ($this->shouldSkipEmptyPostTerminationWorkCycle(
                $company,
                $agreement,
                $workCycle,
                $existingInvoice,
                $monthsWithUnbilledPostTermination,
            )) {
                continue;
            }

            if ($this->shouldSkipZeroActivityNonRetainerCycle($company, $agreement, $workCycle, $retainerPeriod, $existingInvoice)) {
                $skipped[] = [
                    'period' => $periodLabel,
                    'reason_code' => self::SKIP_REASON_ZERO_ACTIVITY,
                    'reason' => 'No retainer and no billable activity for the period; no invoice created.',
                ];

                continue;
            }

            if ($agreement->effectiveBillingCadence() !== BillingCadence::Monthly && $workCycle->end->gte($activeDate)) {
                $interimResults = $this->interimOverageGenerator->ensureInterimOveragesForCycle(
                    $company,
                    $agreement,
                    $workCycle,
                    $immediateLedger,
                );
                $generated = array_merge($generated, $interimResults['generated']);
                $updated = array_merge($updated, $interimResults['updated']);
            }

            if ($existingInvoice instanceof ClientInvoice && $existingInvoice->isImmutable()) {
                $skipped[] = [
                    'period' => $periodLabel,
                    'invoice_id' => $existingInvoice->id,
                    'status' => $existingInvoice->status,
                    'reason' => 'Invoice already exists with status: '.$existingInvoice->status,
                ];

                continue;
            }

            // A retainer period already invoiced must not be sold twice. Matched
            // on the cycle columns rather than the work period so that invoices
            // written under the older "period == cycle" convention are still
            // recognised. Void counts: a deliberately waived cycle stays waived.
            $existingForRetainer = $this->findExistingInvoiceForRetainerPeriod($company, $agreement, $retainerPeriod);
            if ($existingForRetainer instanceof ClientInvoice
                && (! $existingInvoice instanceof ClientInvoice || $existingInvoice->id !== $existingForRetainer->id)) {
                $skipped[] = [
                    'period' => $periodLabel,
                    'invoice_id' => $existingForRetainer->id,
                    'status' => $existingForRetainer->status,
                    'reason' => 'Retainer period already has an invoice with status: '.$existingForRetainer->status,
                ];

                continue;
            }

            try {
                $invoice = $this->generateInvoiceForPeriod($company, $agreement, $retainerPeriod, false, $ledger, $immediateLedger);
                $result = [
                    'period' => $periodLabel,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_kind' => $invoice->invoiceKindValue(),
                ];

                if ($existingInvoice instanceof ClientInvoice) {
                    $updated[] = $result;
                } else {
                    $generated[] = $result;
                }
            } catch (\Throwable $e) {
                // One unbillable period must not abort the rest of the walk;
                // the operator sees it in the skipped list instead.
                $skipped[] = ['period' => $periodLabel, 'error' => $e->getMessage()];
            }
        }

        return $this->summarizeGenerationResults($generated, $updated, $skipped);
    }

    /**
     * Route one retainer period to the monthly or cadence generator.
     *
     * @param  array<int, MonthSummary>|null  $ledger
     * @param  array<int, MonthSummary>|null  $immediateLedger
     */
    private function generateInvoiceForPeriod(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $retainerPeriod,
        bool $generateMissingInterims = true,
        ?array $ledger = null,
        ?array $immediateLedger = null,
    ): ClientInvoice {
        $workCycle = $this->previousBillingCycle($agreement, $retainerPeriod);

        if ($agreement->effectiveBillingCadence() === BillingCadence::Monthly) {
            return $this->generateMonthlyInvoiceForWorkPeriod(
                $company,
                $workCycle->start->copy()->startOfDay(),
                $workCycle->end->copy()->startOfDay(),
                $agreement,
            );
        }

        return $this->generateNonMonthlyInvoiceForPeriod(
            $company,
            $agreement,
            $retainerPeriod,
            $generateMissingInterims,
            $ledger,
            $immediateLedger,
        );
    }

    /**
     * Generate or refresh a monthly invoice reconciling one work period.
     */
    private function generateMonthlyInvoiceForWorkPeriod(
        ClientCompany $company,
        Carbon $periodStart,
        Carbon $periodEnd,
        ClientAgreement $agreement,
    ): ClientInvoice {
        // Found by the cycle it sells, not by the work period. The period is
        // widened by a carried-forward milestone dated outside the cycle, and
        // looking it up by the widened value finds nothing - then the overlap
        // guard rejects the draft it just failed to find.
        $retainerMonthStart = $periodEnd->copy()->addDay()->startOfMonth();
        $invoice = $this->scopedInvoices($company)
            ->where('client_agreement_id', $agreement->id)
            ->where(function ($query) use ($periodStart, $periodEnd, $retainerMonthStart): void {
                // The exact period, as written.
                $query->where(function ($query) use ($periodStart, $periodEnd): void {
                    $query->whereDate('service_period_start', $periodStart->toDateString())
                        ->whereDate('service_period_end', $periodEnd->toDateString());
                })
                    // Or the same cycle with a period that has since been
                    // widened to cover it. Containment matters: two different
                    // work periods can share a retainer month, and matching on
                    // the cycle alone would treat one as a refresh of the other.
                    ->orWhere(function ($query) use ($periodStart, $periodEnd, $retainerMonthStart): void {
                        $query->whereDate('cycle_start', $retainerMonthStart->toDateString())
                            ->whereDate('service_period_start', '<=', $periodStart->toDateString())
                            ->whereDate('service_period_end', '>=', $periodEnd->toDateString());
                    });
            })
            ->where('status', '!=', 'void')
            ->first();

        if ($invoice instanceof ClientInvoice && $invoice->isImmutable()) {
            throw new RuntimeException(
                "A settled invoice (#{$invoice->invoice_number}) already exists for this period and cannot be modified.",
            );
        }

        $this->assertNoOverlappingInvoice($company, $periodStart, $periodEnd, $invoice);

        return DB::transaction(function () use ($company, $agreement, $periodStart, $periodEnd, $invoice): ClientInvoice {
            ClientAgreement::query()->whereKey($agreement->getKey())->lockForUpdate()->first();

            $terminationDate = $this->agreementEnd($agreement);
            $terminationMonthKey = $terminationDate?->format('Y-m');

            // The retainer month is the month after the work period. Past the
            // termination date there is no retainer left to sell.
            $retainerMonthStart = $periodEnd->copy()->addDay()->startOfMonth();
            $isRetainerMonthPostTermination = $terminationDate instanceof Carbon && $retainerMonthStart->gt($terminationDate);

            $allBalances = $this->monthlyBalances($company, $agreement, $periodEnd, $retainerMonthStart, $terminationMonthKey);

            $workMonthKey = $periodEnd->format('Y-m');
            $workMonthBalance = $this->balanceForMonth($allBalances, $workMonthKey);
            $currentMonthBalance = $this->balanceForMonth($allBalances, $retainerMonthStart->format('Y-m'))
                ?? $this->openingMonthSummary($agreement, $retainerMonthStart->format('Y-m'));

            $cumulativeSnapshot = $this->calculateCumulativeBalanceSnapshot($agreement, $periodEnd, $allBalances);

            // End-of-work-period state, adjusted for any overage already paid.
            $rawWorkPeriodNegative = $workMonthBalance?->closing->negativeBalance ?? 0.0;
            $rawWorkPeriodUnused = $workMonthBalance?->closing->unusedHours ?? 0.0;
            [$netWorkPeriodUnused, $netWorkPeriodNegative] = $this->applyBilledOverages(
                $rawWorkPeriodUnused,
                $rawWorkPeriodNegative,
                $this->totalBilledOveragesThrough($agreement, $periodEnd),
            );

            $invoiceData = [
                'client_agreement_id' => $agreement->id,
                'service_period_start' => $periodStart,
                'service_period_end' => $periodEnd,
                'retainer_hours_included' => $isRetainerMonthPostTermination ? 0.0 : $agreement->monthly_retainer_hours,
                'rollover_hours_used' => $workMonthBalance?->closing->hoursUsedFromRollover ?? 0,
                'unused_hours_balance' => $netWorkPeriodUnused,
                'negative_hours_balance' => $netWorkPeriodNegative,
                'starting_unused_hours' => $cumulativeSnapshot['unused'],
                'starting_negative_hours' => $cumulativeSnapshot['negative'],
                'hours_billed_at_rate' => 0,
                'status' => 'draft',
                'invoice_kind' => InvoiceKind::CadencePeriod->value,
                'cycle_start' => $retainerMonthStart,
                'cycle_end' => $retainerMonthStart->copy()->endOfMonth()->startOfDay(),
            ];

            if ($invoice instanceof ClientInvoice) {
                $invoice->update($invoiceData);
                $this->invoiceLineComposer->resetSystemGeneratedLines($invoice);
            } else {
                $invoice = ClientInvoice::query()->create($invoiceData + [
                    'workspace_id' => $company->workspace_id,
                    'client_company_id' => $company->id,
                    'invoice_number' => $this->invoiceNumberAllocator->next($company->workspace),
                    'currency' => (string) $agreement->currency,
                    'subtotal_amount' => 0,
                    'tax_amount' => 0,
                    'total_amount' => 0,
                ]);
            }

            $sortOrder = 1;

            // Fragments left over from a previous draft are put back together
            // before anything is allocated, or the same work would be measured
            // as several smaller entries.
            $this->allocationService->recombineUnlinkedFragments($company->workspace, $company);

            $priorMonthEntries = $this->unbilledEntriesBetween($company, $agreement, $periodStart, $periodEnd);

            $priorMonthBalance = $this->balanceForMonth($allBalances, $periodEnd->format('Y-m'));
            $priorMonthCapacity = $priorMonthBalance?->opening->totalAvailable ?? 0.0;
            $currentMonthCapacity = $isRetainerMonthPostTermination ? 0.0 : $agreement->monthly_retainer_hours;
            // No minimum availability to maintain once the agreement has ended.
            $catchUpThreshold = $isRetainerMonthPostTermination ? 0.0 : $agreement->catch_up_threshold_hours;

            $invoice->update(['hours_worked' => ((int) $priorMonthEntries->sum('minutes')) / 60]);

            $plan = $this->timeEntrySplitter->allocateTimeEntries(
                $priorMonthEntries,
                $priorMonthCapacity,
                $currentMonthCapacity,
                $catchUpThreshold,
            );

            /** @var array<int, list<TimeEntryFragment>> $fragmentsToLines */
            $fragmentsToLines = [];

            if (count($plan->priorMonthRetainerFragments) > 0) {
                $hours = $plan->totalPriorMonthRetainerHours;
                $line = $this->createRetainerDrawLine(
                    $invoice,
                    $agreement,
                    sprintf(
                        'Work items applied to retainer (%s applied to %s pool)',
                        HoursQuantity::format($hours),
                        $periodEnd->format('F Y'),
                    ),
                    $hours,
                    $periodEnd,
                    $sortOrder,
                );
                $fragmentsToLines[$line->id] = $plan->priorMonthRetainerFragments;
            }

            if (count($plan->currentMonthRetainerFragments) > 0) {
                $hours = $plan->totalCurrentMonthRetainerHours;
                $line = $this->createRetainerDrawLine(
                    $invoice,
                    $agreement,
                    sprintf(
                        'Work items applied to retainer (%s applied to %s pool)',
                        HoursQuantity::format($hours),
                        $retainerMonthStart->format('F Y'),
                    ),
                    $hours,
                    $periodEnd,
                    $sortOrder,
                );
                $fragmentsToLines[$line->id] = $plan->currentMonthRetainerFragments;
            }

            // Catch-up billing pays down the overage and restores the buffer the
            // next period needs. Both parts are one line: to the client they are
            // the same charge, and splitting them invites the question of why one
            // half exists.
            $remainingCapacity = ($priorMonthCapacity - $plan->totalPriorMonthRetainerHours)
                + ($currentMonthCapacity - $plan->totalCurrentMonthRetainerHours);
            $bufferNeeded = max(0.0, $catchUpThreshold - $remainingCapacity);
            $totalCatchupHours = $plan->totalCatchUpHours + $plan->totalBillableCatchupHours + $bufferNeeded;

            if ($totalCatchupHours > 0) {
                $catchUpLine = $this->createHourlyLine(
                    $invoice,
                    $agreement,
                    'Catch-up hours for prior month overage and minimum availability',
                    $totalCatchupHours,
                    $periodStart,
                    $sortOrder,
                );
                $fragmentsToLines[$catchUpLine->id] = array_merge($plan->catchUpFragments, $plan->billableCatchupFragments);

                $invoice->update(['hours_billed_at_rate' => $totalCatchupHours]);

                // The balances above were computed before this charge existed,
                // so they have to be taken again now that the debt is paid.
                $snapshot = $this->calculateCumulativeBalanceSnapshot($agreement, $periodEnd, $allBalances);
                [$netUnused, $netNegative] = $this->applyBilledOverages(
                    $rawWorkPeriodUnused,
                    $rawWorkPeriodNegative,
                    $this->totalBilledOveragesThrough($agreement, $periodEnd),
                );

                $invoice->update([
                    'negative_hours_balance' => $netNegative,
                    'unused_hours_balance' => $netUnused,
                    'starting_unused_hours' => $snapshot['unused'],
                    'starting_negative_hours' => $snapshot['negative'],
                ]);
            }

            $this->invoiceLineComposer->linkAllFragmentsToLines($fragmentsToLines, $this->timeEntrySplitter);

            if (! $isRetainerMonthPostTermination) {
                $retainerMonthEnd = $retainerMonthStart->copy()->endOfMonth();
                // A month the agreement only partly covers is billed for the
                // part it covers. The cadence path already asks the calculator
                // for this; the monthly path used to charge a full retainer for
                // an agreement that started or ended mid-month, overcharging the
                // client and granting a full pool against a partial term.
                $multiplier = $agreement->effectiveFirstCycleProration() === FirstCycleProration::FullPeriod
                    ? 1.0
                    : $this->retainerCalculator->monthRetainerMultiplier(
                        $agreement,
                        $retainerMonthStart->copy()->startOfDay(),
                        $retainerMonthEnd->copy()->startOfDay(),
                    );

                $this->createRetainerFeeLine(
                    $invoice,
                    $agreement,
                    round($agreement->periodRetainerHours() * $multiplier, 4),
                    round($agreement->periodRetainerFee() * $multiplier, 2),
                    $retainerMonthStart,
                    $retainerMonthEnd,
                    $sortOrder,
                );
            }

            $this->invoiceLineComposer->addBillableMilestoneTasks($company, $invoice, $periodEnd, $sortOrder);
            $this->invoiceLineComposer->addRecurringItemLines(
                $invoice,
                $agreement,
                $retainerMonthStart,
                $retainerMonthStart->copy()->endOfMonth()->startOfDay(),
                $sortOrder,
            );
            $this->invoiceLineComposer->addSubcontractorFlatHourlyLines($company, $invoice, $periodStart, $periodEnd, $sortOrder);

            $this->applyDeferredWork(
                $company,
                $invoice,
                $agreement,
                $periodEnd,
                $isRetainerMonthPostTermination,
                ($priorMonthCapacity - $plan->totalPriorMonthRetainerHours)
                    + ($currentMonthCapacity - $plan->totalCurrentMonthRetainerHours),
                $sortOrder,
            );

            // Credit is applied last so it lands against the final figure rather
            // than against a subtotal that later lines then increase.
            $this->overpaymentCreditService->applyCreditsToDraftInvoice($invoice);

            $invoice->recalculateTotals();
            $this->updateInvoicePeriodFromLines($invoice);

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * Generate or refresh one quarterly, semi-annual or annual invoice.
     *
     * @param  array<int, MonthSummary>|null  $ledger
     * @param  array<int, MonthSummary>|null  $immediateLedger
     */
    private function generateNonMonthlyInvoiceForPeriod(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $retainerPeriod,
        bool $generateMissingInterims = true,
        ?array $ledger = null,
        ?array $immediateLedger = null,
    ): ClientInvoice {
        $workCycle = $this->previousBillingCycle($agreement, $retainerPeriod);
        $periodStart = $workCycle->start->copy()->startOfDay();
        $periodEnd = $workCycle->end->copy()->startOfDay();
        $retainerStart = $retainerPeriod->start->copy()->startOfDay();
        $retainerEnd = $retainerPeriod->end->copy()->startOfDay();

        return DB::transaction(function () use (
            $company,
            $agreement,
            $workCycle,
            $retainerPeriod,
            $periodStart,
            $periodEnd,
            $retainerStart,
            $retainerEnd,
            $generateMissingInterims,
            $ledger,
            $immediateLedger,
        ): ClientInvoice {
            // The invoice rows this guards against may not exist yet, so the
            // agreement row is what serializes concurrent generation.
            ClientAgreement::query()->whereKey($agreement->getKey())->lockForUpdate()->first();

            if ((bool) $agreement->bill_overage_interim) {
                $ledger ??= $this->invoiceLedgerBuilder->buildAgreementLedgerThrough($company, $agreement, $periodEnd, true);
                $immediateLedger ??= $ledger;
            }

            $activeDate = $this->agreementStart($agreement);

            if ($generateMissingInterims && $workCycle->end->gte($activeDate)) {
                $this->interimOverageGenerator->ensureInterimOveragesForCycle($company, $agreement, $workCycle, $immediateLedger);
            }

            $invoice = $this->scopedInvoices($company)
                ->where('client_agreement_id', $agreement->id)
                ->where('invoice_kind', InvoiceKind::CadencePeriod->value)
                ->whereDate('service_period_start', $periodStart->toDateString())
                ->whereDate('service_period_end', $periodEnd->toDateString())
                ->where('status', '!=', 'void')
                ->lockForUpdate()
                ->first();

            if ($invoice instanceof ClientInvoice && $invoice->isImmutable()) {
                throw new RuntimeException(
                    "A settled invoice (#{$invoice->invoice_number}) already exists for this cadence cycle and cannot be modified.",
                );
            }

            $this->assertNoOverlappingInvoice($company, $periodStart, $periodEnd, $invoice);

            $agreement->loadMissing('recurringItems');

            if ($invoice instanceof ClientInvoice) {
                $invoice->update([
                    'service_period_start' => $periodStart,
                    'service_period_end' => $periodEnd,
                    'cycle_start' => $retainerStart,
                    'cycle_end' => $retainerEnd,
                    'invoice_kind' => InvoiceKind::CadencePeriod->value,
                    'status' => 'draft',
                ]);
                $this->invoiceLineComposer->resetSystemGeneratedLines($invoice);
            } else {
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
                    'invoice_kind' => InvoiceKind::CadencePeriod->value,
                    'cycle_start' => $retainerStart,
                    'cycle_end' => $retainerEnd,
                ]);
            }

            $sortOrder = 1;
            $this->allocationService->recombineUnlinkedFragments($company->workspace, $company);

            $ledger ??= $this->invoiceLedgerBuilder->buildAgreementLedgerThrough(
                $company,
                $agreement,
                $periodEnd,
                (bool) $agreement->bill_overage_interim,
            );

            // A work cycle entirely before the agreement began has no ledger of
            // its own; an empty summary keeps the arithmetic defined instead of
            // reading another cycle's numbers.
            $ledgerWorkCycle = $this->ledgerCycleForWorkCycle($agreement, $workCycle);
            $cycleLedger = $workCycle->end->lt($activeDate)
                ? $this->emptyCycleLedgerSummary()
                : $this->invoiceLedgerBuilder->summarizeLedgerForCycle($agreement, $ledger, $ledgerWorkCycle);
            $interimBilledHours = $workCycle->end->lt($activeDate)
                ? 0.0
                : $this->interimOverageGenerator->interimOverageHoursForCycle($agreement, $workCycle);

            $entries = $this->unbilledEntriesBetween($company, $agreement, $periodStart, $periodEnd);

            // The retainer being sold is priced from its own cycle's ledger, not
            // the work cycle's - proration depends on where the agreement starts
            // and ends relative to that period.
            $retainerLedgerRows = $this->invoiceLedgerBuilder->buildAgreementLedgerThrough($company, $agreement, $retainerEnd, false);
            $ledgerRetainerPeriod = $this->ledgerCycleForWorkCycle($agreement, $retainerPeriod);
            $retainerLedger = $this->invoiceLedgerBuilder->summarizeLedgerForCycle($agreement, $retainerLedgerRows, $ledgerRetainerPeriod);
            $retainerHours = $this->retainerCalculator->cycleRetainerHours($agreement, $ledgerRetainerPeriod, $retainerLedger);
            $retainerFee = $this->retainerCalculator->cycleRetainerFee($agreement, $ledgerRetainerPeriod, $retainerLedger);

            $plan = $this->timeEntrySplitter->allocateTimeEntries($entries, $cycleLedger['covered_hours'], 0.0, 0.0);

            /** @var array<int, list<TimeEntryFragment>> $fragmentsToLines */
            $fragmentsToLines = [];

            if (count($plan->priorMonthRetainerFragments) > 0) {
                $hours = $plan->totalPriorMonthRetainerHours;
                $line = $this->createRetainerDrawLine(
                    $invoice,
                    $agreement,
                    sprintf(
                        'Work items applied to %s retainer (%s applied to %s cycle)',
                        strtolower(BillingCadenceLabel::for($agreement->effectiveBillingCadence())),
                        HoursQuantity::format($hours),
                        PeriodLabel::for($periodStart, $periodEnd),
                    ),
                    $hours,
                    $periodEnd,
                    $sortOrder,
                );
                $fragmentsToLines[$line->id] = $plan->priorMonthRetainerFragments;
            }

            $overageHours = $plan->totalCatchUpHours + $plan->totalBillableCatchupHours;
            if ($overageHours > 0) {
                $line = $this->createHourlyLine(
                    $invoice,
                    $agreement,
                    'Additional hours beyond cadence retainer',
                    $overageHours,
                    $periodEnd,
                    $sortOrder,
                );
                $fragmentsToLines[$line->id] = array_merge($plan->catchUpFragments, $plan->billableCatchupFragments);
            }

            // A zero-value line, present so the client can see that hours they
            // were already invoiced for inside the cycle are accounted for here
            // and not being charged twice.
            if ($interimBilledHours > 0) {
                ClientInvoiceLine::query()->create([
                    'workspace_id' => $invoice->workspace_id,
                    'client_invoice_id' => $invoice->id,
                    'client_agreement_id' => $agreement->id,
                    'description' => 'Already billed in this cycle via interim overage invoices',
                    'quantity' => HoursQuantity::decimal($interimBilledHours),
                    'unit_amount' => 0,
                    'tax_amount' => 0,
                    'total_amount' => 0,
                    'type' => InvoiceLineType::Reconciliation->value,
                    'hours' => $interimBilledHours,
                    'line_date' => $periodEnd,
                    'sort_order' => $sortOrder++,
                ]);
            }

            $this->invoiceLineComposer->linkAllFragmentsToLines($fragmentsToLines, $this->timeEntrySplitter);

            if ($retainerFee > 0 || $retainerHours > 0) {
                $this->createRetainerFeeLine($invoice, $agreement, $retainerHours, $retainerFee, $retainerStart, $retainerEnd, $sortOrder);
            }

            $this->invoiceLineComposer->addBillableMilestoneTasks($company, $invoice, $periodEnd, $sortOrder);
            $this->invoiceLineComposer->addRecurringItemLines($invoice, $agreement, $retainerStart, $retainerEnd, $sortOrder);
            $this->invoiceLineComposer->addSubcontractorFlatHourlyLines($company, $invoice, $periodStart, $periodEnd, $sortOrder);

            $this->applyDeferredWork(
                $company,
                $invoice,
                $agreement,
                $periodEnd,
                false,
                max(0.0, $cycleLedger['covered_hours'] - $plan->totalPriorMonthRetainerHours),
                $sortOrder,
            );

            $invoice->update([
                'retainer_hours_included' => $retainerHours,
                'hours_worked' => $cycleLedger['hours_worked'],
                'rollover_hours_used' => $cycleLedger['rollover_hours_used'],
                'unused_hours_balance' => $cycleLedger['unused_hours'],
                'negative_hours_balance' => round(max(0.0, $cycleLedger['negative_hours'] - $overageHours - $interimBilledHours), 4),
                'starting_unused_hours' => $cycleLedger['starting_unused_hours'],
                'starting_negative_hours' => $cycleLedger['starting_negative_hours'],
                'hours_billed_at_rate' => $overageHours,
            ]);

            $this->overpaymentCreditService->applyCreditsToDraftInvoice($invoice);
            $invoice->recalculateTotals();

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * Bill deferred work, either against remaining capacity or in full.
     *
     * Deferred entries are never split and never trigger catch-up billing - the
     * client agreed to have them held, not to be charged for holding them. On a
     * post-termination invoice everything outstanding is force-billed instead,
     * so no deferred work is left permanently unbilled.
     */
    private function applyDeferredWork(
        ClientCompany $company,
        ClientInvoice $invoice,
        ClientAgreement $agreement,
        Carbon $periodEnd,
        bool $isPostTermination,
        float $remainingCapacity,
        int &$sortOrder,
    ): void {
        if ($isPostTermination) {
            $deferredToBill = $this->deferredBillingAllocator->collectForTermination($company, $periodEnd);
            if ($deferredToBill->isNotEmpty()) {
                $this->invoiceLineComposer->addDeferredTerminationLine($invoice, $agreement, $deferredToBill, $sortOrder);
            }
            $this->deferredSkipped = [];

            return;
        }

        $result = $this->deferredBillingAllocator->allocate($company, $periodEnd, $remainingCapacity);
        if ($result->hasBilled()) {
            $this->invoiceLineComposer->addDeferredRetainerLine($invoice, $agreement, $result, $periodEnd, $sortOrder);
        }
        $this->deferredSkipped = $result->skipped;
    }

    /**
     * A line for work the retainer already paid for: hours, no money.
     */
    private function createRetainerDrawLine(
        ClientInvoice $invoice,
        ClientAgreement $agreement,
        string $description,
        float $hours,
        Carbon $lineDate,
        int &$sortOrder,
    ): ClientInvoiceLine {
        return ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $agreement->id,
            'description' => $description,
            'quantity' => '0',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'type' => InvoiceLineType::PriorMonthRetainer->value,
            'hours' => $hours,
            'line_date' => $lineDate,
            'sort_order' => $sortOrder++,
        ]);
    }

    /**
     * A line billing hours at the agreement's rate.
     *
     * The amount is derived from whole minutes rather than from decimal hours
     * times a decimal rate, so it is exact and matches what the same hours would
     * cost on any other line.
     */
    private function createHourlyLine(
        ClientInvoice $invoice,
        ClientAgreement $agreement,
        string $description,
        float $hours,
        Carbon $lineDate,
        int &$sortOrder,
    ): ClientInvoiceLine {
        $rateAmount = (int) ($agreement->hourly_rate_amount ?? 0);
        $minutes = (int) round($hours * 60);

        return ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $agreement->id,
            'description' => $description,
            'quantity' => HoursQuantity::decimal($hours),
            'unit_amount' => $rateAmount,
            'tax_amount' => 0,
            'total_amount' => $minutes > 0 ? MoneyService::hourlyAmount($minutes, $rateAmount) : 0,
            'type' => InvoiceLineType::AdditionalHours->value,
            'hours' => $hours,
            'line_date' => $lineDate,
            'sort_order' => $sortOrder++,
        ]);
    }

    /**
     * The retainer being sold for the coming cycle.
     */
    private function createRetainerFeeLine(
        ClientInvoice $invoice,
        ClientAgreement $agreement,
        float $retainerHours,
        float $retainerFee,
        Carbon $cycleStart,
        Carbon $cycleEnd,
        int &$sortOrder,
    ): ClientInvoiceLine {
        // The calculator works in whole currency units; the column is minor units.
        $feeAmount = (int) round($retainerFee * 100);

        return ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $agreement->id,
            'description' => sprintf(
                '%s Retainer (%s hours) - %s through %s',
                BillingCadenceLabel::for($agreement->effectiveBillingCadence()),
                HoursQuantity::format($retainerHours),
                $cycleStart->format('M j, Y'),
                $cycleEnd->format('M j, Y'),
            ),
            'quantity' => '1',
            'unit_amount' => $feeAmount,
            'tax_amount' => 0,
            'total_amount' => $feeAmount,
            'type' => InvoiceLineType::Retainer->value,
            'hours' => $retainerHours,
            'line_date' => $cycleStart,
            'sort_order' => $sortOrder++,
        ]);
    }

    /**
     * Refuse to bill a period another invoice already covers.
     *
     * Interim and ad-hoc invoices are excluded: neither is tied to an agreement
     * cycle, and an ad-hoc invoice raised mid-quarter must not block that
     * quarter's cadence invoice.
     */
    private function assertNoOverlappingInvoice(
        ClientCompany $company,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?ClientInvoice $invoice,
    ): void {
        $overlapping = $this->scopedInvoices($company)
            ->where('status', '!=', 'void')
            ->where(function ($query): void {
                $query->whereNull('invoice_kind')
                    ->orWhereNotIn('invoice_kind', InvoiceKind::cycleGuardExclusions());
            })
            ->where(function ($query) use ($periodStart, $periodEnd): void {
                // Inclusive on both ends, matching how entries are selected:
                // a strict comparison lets a new period start on an existing
                // invoice's last billed day, so that day belongs to two.
                $query->where('service_period_start', '<=', $periodEnd->toDateString())
                    ->where('service_period_end', '>=', $periodStart->toDateString());
            })
            ->when($invoice instanceof ClientInvoice, function ($query) use ($invoice): void {
                $query->whereKeyNot($invoice->id);
            })
            ->first();

        if ($overlapping instanceof ClientInvoice) {
            throw new RuntimeException(sprintf(
                'An invoice (#%s) already exists for an overlapping period (%s - %s). '.
                'Please choose a different date range or void the existing invoice first.',
                $overlapping->invoice_number,
                $overlapping->service_period_start?->format('M d, Y') ?? '?',
                $overlapping->service_period_end?->format('M d, Y') ?? '?',
            ));
        }
    }

    /**
     * Month-by-month retainer balances from the agreement's start to the cycle
     * being billed.
     *
     * The window opens at the earlier of the agreement start and the first
     * billable entry, because work recorded before the agreement was signed
     * still consumed nothing and must not appear as a debt.
     *
     * @return array<int, MonthSummary>
     */
    private function monthlyBalances(
        ClientCompany $company,
        ClientAgreement $agreement,
        Carbon $periodEnd,
        Carbon $retainerMonthStart,
        ?string $terminationMonthKey,
    ): array {
        $agreementStart = $this->agreementStart($agreement)->copy()->startOfMonth();

        $earliestEntryDate = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_billable', true)
            ->where('is_deferred', false)
            ->retainerBillable()
            ->min('worked_on');

        $calculationStart = $earliestEntryDate === null
            ? $agreementStart
            : min($agreementStart, Carbon::parse((string) $earliestEntryDate)->startOfMonth());

        $minutesByMonth = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_billable', true)
            // Same rule as InvoiceLedgerBuilder: deferred work draws on the pool
            // only once the allocator bills it. This path is the monthly
            // equivalent of that ledger and was missed when the other was fixed.
            ->where('is_deferred', false)
            ->retainerBillable()
            ->where('worked_on', '<=', $periodEnd->toDateString())
            ->get()
            ->groupBy(fn (ClientTimeEntry $entry): string => Carbon::parse((string) $entry->worked_on)->format('Y-m'))
            ->map(fn ($group): int => (int) $group->sum('minutes'));

        $months = [];
        $firstPostTerminationSeen = false;
        $cursor = $calculationStart->copy();

        while ($cursor->lte($retainerMonthStart)) {
            $monthKey = $cursor->format('Y-m');
            $isPreAgreement = $monthKey < $agreementStart->format('Y-m');

            // The termination month keeps its whole retainer; months after it
            // get none.
            $isPostTermination = $terminationMonthKey !== null && $monthKey > $terminationMonthKey;

            // The first month past termination forfeits accumulated rollover,
            // which the calculator needs told explicitly.
            $resetRollover = $isPostTermination && ! $firstPostTerminationSeen;
            if ($resetRollover) {
                $firstPostTerminationSeen = true;
            }

            // Pre-agreement months exist only to place the window; they have no
            // retainer, so recording their hours would carry the whole lot in as
            // debt and let work done before the client had an agreement consume
            // the retainer they have now - or trigger catch-up billing for it.
            $months[] = [
                'year_month' => $monthKey,
                'retainer_hours' => ($isPreAgreement || $isPostTermination) ? 0.0 : $agreement->monthly_retainer_hours,
                'hours_worked' => $isPreAgreement ? 0.0 : ((int) ($minutesByMonth[$monthKey] ?? 0)) / 60,
                'reset_rollover' => $resetRollover,
            ];

            $cursor->addMonth();
        }

        return $this->rolloverCalculator->calculateMultipleMonths($months, (int) ($agreement->rollover_months ?? 0));
    }

    /**
     * @param  array<int, MonthSummary>  $balances
     */
    private function balanceForMonth(array $balances, string $yearMonth): ?MonthSummary
    {
        foreach ($balances as $balance) {
            if ($balance->yearMonth === $yearMonth) {
                return $balance;
            }
        }

        return null;
    }

    /**
     * A fresh month with the full retainer untouched.
     *
     * Used only when there is no calculation history at all, so that the rest of
     * generation has a defined balance to read rather than a null.
     */
    private function openingMonthSummary(ClientAgreement $agreement, string $yearMonth): MonthSummary
    {
        $retainer = $agreement->monthly_retainer_hours;

        return new MonthSummary(
            opening: new OpeningBalance(
                retainerHours: $retainer,
                rolloverHours: 0,
                expiredHours: 0,
                totalAvailable: $retainer,
                negativeOffset: 0,
                invoicedNegativeBalance: 0,
                effectiveRetainerHours: $retainer,
                remainingNegativeBalance: 0,
            ),
            closing: new ClosingBalance(
                hoursUsedFromRetainer: 0,
                hoursUsedFromRollover: 0,
                unusedHours: $retainer,
                excessHours: 0,
                negativeBalance: 0,
                remainingRollover: 0,
            ),
            hoursWorked: 0,
            yearMonth: $yearMonth,
            retainerHours: $retainer,
        );
    }

    /**
     * Balance the invoice opens with, once catch-up billing has paid off debt.
     *
     * The rollover calculator knows only retainer against worked hours; it has
     * no idea that some of the overage was already charged. This applies that
     * payment: debt first, and any surplus becomes available capacity.
     *
     * @param  array<int, MonthSummary>  $allBalances
     * @return array{unused: float, negative: float}
     */
    private function calculateCumulativeBalanceSnapshot(ClientAgreement $agreement, Carbon $periodEnd, array $allBalances): array
    {
        $summary = $this->balanceForMonth($allBalances, $periodEnd->copy()->addDay()->startOfMonth()->format('Y-m'));

        if (! $summary instanceof MonthSummary) {
            return ['unused' => 0.0, 'negative' => 0.0];
        }

        [$netUnused, $netNegative] = $this->applyBilledOverages(
            $summary->opening->totalAvailable,
            $summary->opening->remainingNegativeBalance,
            $this->totalBilledOveragesThrough($agreement, $periodEnd),
        );

        return ['unused' => round($netUnused, 4), 'negative' => round($netNegative, 4)];
    }

    /**
     * Pay billed overage against debt first, then into available capacity.
     *
     * @return array{0: float, 1: float} Net unused hours, net negative hours.
     */
    private function applyBilledOverages(float $rawUnused, float $rawNegative, float $billedOverages): array
    {
        $netNegative = max(0.0, $rawNegative - $billedOverages);
        $netUnused = $billedOverages > $rawNegative
            ? $rawUnused + ($billedOverages - $rawNegative)
            : $rawUnused;

        return [$netUnused, $netNegative];
    }

    private function totalBilledOveragesThrough(ClientAgreement $agreement, Carbon $periodEnd): float
    {
        return (float) ClientInvoice::query()
            ->where('workspace_id', $agreement->workspace_id)
            ->where('client_agreement_id', $agreement->id)
            ->where('status', '!=', 'void')
            ->where('service_period_end', '<=', $periodEnd->toDateString())
            ->sum('hours_billed_at_rate');
    }

    /**
     * Work this agreement's retainer may draw on, inside a period.
     *
     * @return Collection<int, ClientTimeEntry>
     */
    private function unbilledEntriesBetween(
        ClientCompany $company,
        ClientAgreement $agreement,
        Carbon $from,
        Carbon $to,
    ): Collection {
        return ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->unbilled()
            ->where('is_billable', true)
            ->where('is_deferred', false)
            // Flat-hourly subcontractor work is billed additively by the
            // composer at the rate snapshotted on the entry. Letting the
            // retainer allocator claim it first leaves the composer nothing to
            // bill, so the work is absorbed by the retainer or charged at the
            // agreement rate instead of the contractor's.
            ->whereNull('subcontractor_cost_amount')
            ->retainerBillable()
            // An agreement scoped to one project allocates only that project's
            // work. Otherwise whichever agreement generates first claims every
            // project's time and the rest find nothing left to bill.
            ->when(
                $agreement->client_project_id !== null,
                fn ($query) => $query->where('client_project_id', $agreement->client_project_id),
            )
            ->whereBetween('worked_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('worked_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * The last retainer date generation should reach.
     *
     * A successor agreement caps the segment: once the next agreement takes
     * over, this one stops producing invoices even if its own cadence would
     * carry on.
     */
    private function retainerGenerationThroughDate(ClientAgreement $agreement, ?ClientAgreement $successorAgreement = null): Carbon
    {
        $terminationDate = $this->agreementEnd($agreement);

        if ($agreement->effectiveBillingCadence() === BillingCadence::Monthly) {
            $retainerPeriodStart = Carbon::now()->startOfMonth()->addMonth();

            if ($successorAgreement instanceof ClientAgreement && $terminationDate instanceof Carbon) {
                // Leave room for the final catch-up invoice: the month after
                // termination, or the month before the successor starts,
                // whichever reaches further.
                $successorCatchUpStart = $this->agreementStart($successorAgreement)->copy()->startOfMonth()->subMonth();
                $terminationSegmentEnd = $terminationDate->copy()->startOfMonth()->addMonth();
                $segmentEnd = $successorCatchUpStart->gt($terminationSegmentEnd) ? $successorCatchUpStart : $terminationSegmentEnd;

                if ($segmentEnd->lt($retainerPeriodStart)) {
                    $retainerPeriodStart = $segmentEnd;
                }
            }

            return $retainerPeriodStart->copy()->endOfMonth()->startOfDay();
        }

        $activeDate = $this->agreementStart($agreement);
        $referenceDate = Carbon::now()->startOfDay();

        // An agreement that has not started yet still gets its first cycle
        // generated, because a cadence retainer is billed in advance.
        if ($activeDate->gt($referenceDate)) {
            return $this->billingCycleResolver->cycleContaining($agreement, $activeDate)->end;
        }

        if ($terminationDate instanceof Carbon && $terminationDate->lt($referenceDate)) {
            $referenceDate = $terminationDate->copy();
        }

        return $this->nextBillingCycle(
            $agreement,
            $this->billingCycleResolver->cycleContaining($agreement, $referenceDate),
        )->end;
    }

    /**
     * @return iterable<BillingCycle>
     */
    private function retainerPeriodsThrough(ClientAgreement $agreement, Carbon $through): iterable
    {
        $cursor = $this->billingCycleResolver->cycleContaining($agreement, $this->agreementStart($agreement));

        while ($cursor->start->lte($through)) {
            yield $cursor;
            $cursor = $this->nextBillingCycle($agreement, $cursor);
        }
    }

    private function findGeneratedInvoiceForWorkCycle(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $workCycle,
    ): ?ClientInvoice {
        return $this->scopedInvoices($company)
            ->where('client_agreement_id', $agreement->id)
            ->where('invoice_kind', InvoiceKind::CadencePeriod->value)
            ->whereDate('service_period_start', $workCycle->start->toDateString())
            ->whereDate('service_period_end', $workCycle->end->toDateString())
            ->first();
    }

    /**
     * An invoice that already sold this retainer period.
     *
     * Matched on the cycle columns rather than the work period, so invoices
     * written under the older "period == cycle" convention are recognised. Void
     * counts as settled: a waived cycle stays waived rather than regenerating.
     */
    private function findExistingInvoiceForRetainerPeriod(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $retainerPeriod,
    ): ?ClientInvoice {
        return $this->scopedInvoices($company)
            ->where('client_agreement_id', $agreement->id)
            ->where('invoice_kind', InvoiceKind::CadencePeriod->value)
            ->whereDate('cycle_start', $retainerPeriod->start->toDateString())
            ->whereDate('cycle_end', $retainerPeriod->end->toDateString())
            ->whereIn('status', ['issued', 'paid', 'void'])
            ->first();
    }

    /**
     * Skip a post-termination cycle that has nothing left to bill.
     *
     * Without this, a terminated agreement keeps producing empty invoices for
     * every cycle between its end and today. The months carrying unbilled work
     * are collected once and reused across the walk.
     *
     * @param  array<string, true>|null  $monthsWithUnbilledPostTermination
     */
    private function shouldSkipEmptyPostTerminationWorkCycle(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $workCycle,
        ?ClientInvoice $existingInvoice,
        ?array &$monthsWithUnbilledPostTermination,
    ): bool {
        $terminationDate = $this->agreementEnd($agreement);

        if (! $terminationDate instanceof Carbon
            || $existingInvoice instanceof ClientInvoice
            || ! $workCycle->start->gt($terminationDate)) {
            return false;
        }

        if ($monthsWithUnbilledPostTermination === null) {
            $workMonths = ClientTimeEntry::query()
                ->where('workspace_id', $company->workspace_id)
                ->where('client_company_id', $company->id)
                ->where('is_billable', true)
                ->billableForInvoicing()
                ->unbilled()
                ->where('worked_on', '>', $terminationDate->toDateString())
                ->pluck('worked_on')
                ->map(fn (mixed $date): string => substr((string) $date, 0, 7))
                ->all();

            $monthsWithUnbilledPostTermination = array_fill_keys(array_unique($workMonths), true);
        }

        $cursor = $workCycle->start->copy()->startOfMonth();
        $endMonth = $workCycle->end->copy()->startOfMonth();

        while ($cursor->lte($endMonth)) {
            if (isset($monthsWithUnbilledPostTermination[$cursor->format('Y-m')])) {
                return false;
            }

            $cursor->addMonth()->startOfMonth();
        }

        return true;
    }

    /**
     * Suppress a cycle entirely when there is no retainer and nothing happened.
     *
     * The decision is to not create the row at all - not to create and void, and
     * not to create and hide - so no invoice number is consumed and the invoice
     * count is not inflated by empty drafts.
     *
     * "Nothing happened" is read conservatively: every source that could produce
     * a line is checked, and anything that would yield even a zero-netting line
     * counts as activity. When an invoice already exists the guard defers to the
     * normal refresh path.
     */
    private function shouldSkipZeroActivityNonRetainerCycle(
        ClientCompany $company,
        ClientAgreement $agreement,
        BillingCycle $workCycle,
        BillingCycle $retainerPeriod,
        ?ClientInvoice $existingInvoice,
    ): bool {
        if ($existingInvoice instanceof ClientInvoice) {
            return false;
        }

        if ($agreement->periodRetainerFee() > 0.0 || $agreement->periodRetainerHours() > 0.0) {
            return false;
        }

        $workCycleEndDate = $workCycle->end->toDateString();

        $hasBillableTimeEntry = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_billable', true)
            ->billableForInvoicing()
            ->unbilled()
            ->whereDate('worked_on', '<=', $workCycleEndDate)
            ->exists();

        if ($hasBillableTimeEntry) {
            return false;
        }

        $hasBillableMilestone = ClientTask::query()
            ->where('workspace_id', $company->workspace_id)
            ->whereHas('project', fn ($query) => $query->where('client_company_id', $company->id))
            ->where('milestone_price_amount', '>', 0)
            ->whereNotNull('completed_at')
            ->whereNull('client_invoice_line_id')
            ->where('completed_at', '<=', $workCycle->end->copy()->endOfDay())
            ->exists();

        if ($hasBillableMilestone) {
            return false;
        }

        $agreement->loadMissing('recurringItems');

        return count($this->recurringItemBiller->linesForCycle($agreement, $retainerPeriod->start, $retainerPeriod->end)) === 0;
    }

    private function nextBillingCycle(ClientAgreement $agreement, BillingCycle $cycle): BillingCycle
    {
        // Anchored on the natural cycle containing this one's start, so a
        // prorated opening cycle does not shift every cycle after it.
        $naturalCycle = $this->billingCycleResolver->cycleContaining($agreement, $cycle->start);
        $start = $naturalCycle->end->copy()->addDay()->startOfDay();
        $end = $start->copy()->addMonths($agreement->effectiveBillingCadence()->monthsInCycle())->subDay()->startOfDay();

        return $this->makeBillingCycle($start, $end, false);
    }

    private function previousBillingCycle(ClientAgreement $agreement, BillingCycle $retainerPeriod): BillingCycle
    {
        $end = $retainerPeriod->start->copy()->subDay()->startOfDay();
        $start = $retainerPeriod->start->copy()
            ->subMonths($agreement->effectiveBillingCadence()->monthsInCycle())
            ->startOfDay();

        return $this->makeBillingCycle($start, $end, false);
    }

    /**
     * Clip a work cycle at the termination date when the agreement ends inside
     * it, so the ledger prorates that cycle instead of granting a full one.
     */
    private function ledgerCycleForWorkCycle(ClientAgreement $agreement, BillingCycle $workCycle): BillingCycle
    {
        $terminationDate = $this->agreementEnd($agreement);

        if (! $terminationDate instanceof Carbon
            || $terminationDate->lt($workCycle->start)
            || $terminationDate->gte($workCycle->end)) {
            return $workCycle;
        }

        return $this->makeBillingCycle($workCycle->start->copy(), $terminationDate->copy(), true);
    }

    private function makeBillingCycle(Carbon $start, Carbon $end, bool $isProrated): BillingCycle
    {
        $monthStarts = [];
        $cursor = $start->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            $monthStarts[] = $cursor->copy();
            $cursor->addMonth()->startOfMonth();
        }

        return new BillingCycle(
            start: $start,
            end: $end,
            isProrated: $isProrated,
            monthCount: count($monthStarts),
            monthStarts: $monthStarts,
        );
    }

    /**
     * Widen the invoice's work period to cover the lines it actually carries.
     *
     * Expands only, never contracts: a manually added line dated outside the
     * cycle should show on the invoice's period, but a period the operator set
     * deliberately must not shrink underneath them. The retainer line is
     * excluded because it is dated in the coming cycle by design.
     */
    private function updateInvoicePeriodFromLines(ClientInvoice $invoice): void
    {
        $lines = $invoice->lines()
            ->whereNotNull('line_date')
            ->whereNotIn('type', [InvoiceLineType::Retainer->value, InvoiceLineType::Credit->value])
            ->get();

        if ($lines->isEmpty()) {
            return;
        }

        $earliest = Carbon::parse((string) $lines->min('line_date'))->startOfDay();
        $latest = Carbon::parse((string) $lines->max('line_date'))->startOfDay();

        $periodStart = $invoice->service_period_start === null
            ? $earliest
            : Carbon::parse((string) $invoice->service_period_start)->startOfDay()->min($earliest);
        $periodEnd = $invoice->service_period_end === null
            ? $latest
            : Carbon::parse((string) $invoice->service_period_end)->startOfDay()->max($latest);

        $invoice->update([
            'service_period_start' => $periodStart,
            'service_period_end' => $periodEnd,
        ]);
    }

    /**
     * @return Builder<ClientInvoice>
     */
    private function scopedInvoices(ClientCompany $company): Builder
    {
        return ClientInvoice::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id);
    }

    /**
     * An agreement with no start date is treated as starting at the epoch of the
     * walk rather than failing: generation should surface an empty result, not
     * an exception, for a half-configured agreement.
     */
    private function agreementStart(ClientAgreement $agreement): Carbon
    {
        return $agreement->starts_on === null
            ? Carbon::now()->startOfDay()
            : Carbon::parse((string) $agreement->starts_on)->startOfDay();
    }

    private function agreementEnd(ClientAgreement $agreement): ?Carbon
    {
        return $agreement->ends_on === null
            ? null
            : Carbon::parse((string) $agreement->ends_on)->startOfDay();
    }

    /**
     * @return GenerationResults
     */
    private function emptyGenerationResults(): array
    {
        return $this->summarizeGenerationResults([], [], []);
    }

    /**
     * @return array{
     *     retainer_hours: float, retainer_multiplier: float, covered_hours: float,
     *     hours_worked: float, rollover_hours_used: float, unused_hours: float,
     *     negative_hours: float, starting_unused_hours: float, starting_negative_hours: float
     * }
     */
    private function emptyCycleLedgerSummary(): array
    {
        return [
            'retainer_hours' => 0.0,
            'retainer_multiplier' => 0.0,
            'covered_hours' => 0.0,
            'hours_worked' => 0.0,
            'rollover_hours_used' => 0.0,
            'unused_hours' => 0.0,
            'negative_hours' => 0.0,
            'starting_unused_hours' => 0.0,
            'starting_negative_hours' => 0.0,
        ];
    }

    /**
     * @param  GenerationResults  $left
     * @param  GenerationResults  $right
     * @return GenerationResults
     */
    private function mergeGenerationResults(array $left, array $right): array
    {
        return $this->summarizeGenerationResults(
            array_merge($left['generated'], $right['generated']),
            array_merge($left['updated'], $right['updated']),
            array_merge($left['skipped'], $right['skipped']),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $generated
     * @param  list<array<string, mixed>>  $updated
     * @param  list<array<string, mixed>>  $skipped
     * @return GenerationResults
     */
    private function summarizeGenerationResults(array $generated, array $updated, array $skipped): array
    {
        $countKind = static fn (array $rows, string $kind): int => count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['invoice_kind'] ?? null) === $kind,
        ));

        return [
            'generated' => $generated,
            'updated' => $updated,
            'skipped' => $skipped,
            'summary' => [
                'generated_count' => count($generated),
                'updated_count' => count($updated),
                'skipped_count' => count($skipped),
                'cadence_period_invoices_created' => $countKind($generated, InvoiceKind::CadencePeriod->value),
                'interim_invoices_created' => $countKind($generated, InvoiceKind::InterimOverage->value),
                'zero_activity_skipped_count' => count(array_filter(
                    $skipped,
                    static fn (array $row): bool => ($row['reason_code'] ?? null) === self::SKIP_REASON_ZERO_ACTIVITY,
                )),
            ],
        ];
    }

    private function generationPeriodLabel(
        ClientAgreement $agreement,
        BillingCycle $workCycle,
        BillingCycle $retainerPeriod,
    ): string {
        $workLabel = PeriodLabel::for($workCycle->start, $workCycle->end);

        if ($agreement->effectiveBillingCadence() === BillingCadence::Monthly) {
            return $workLabel;
        }

        return $workLabel.' -> '.PeriodLabel::for($retainerPeriod->start, $retainerPeriod->end);
    }
}
