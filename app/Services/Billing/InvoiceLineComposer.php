<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\DeferredAllocationResult;
use App\Services\Billing\Balances\TimeEntryFragment;
use App\Support\Billing\HoursQuantity;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\SubcontractorBillingMode;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InvoiceLineComposer
{
    public function __construct(private readonly RecurringItemBiller $recurringItemBiller = new RecurringItemBiller) {}

    /**
     * Remove generated lines from a draft invoice before regeneration.
     */
    public function resetSystemGeneratedLines(ClientInvoice $invoice): void
    {
        $invoice->assertLineOwnership();
        $systemLines = ClientInvoiceLine::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->whereIn('type', InvoiceLineType::systemGeneratedValues())
            ->get();

        $lineIds = $systemLines->modelKeys();
        $hasForeignPivots = DB::table('client_invoice_line_time_entries')
            ->whereIn('client_invoice_line_id', $lineIds)
            ->where(fn ($query) => $query
                ->whereNull('workspace_id')
                ->orWhere('workspace_id', '!=', $invoice->workspace_id))
            ->exists();
        if ($hasForeignPivots) {
            throw new RuntimeException('The draft contains a time allocation owned by another workspace.');
        }

        $hasForeignTasks = ClientTask::query()
            ->whereIn('client_invoice_line_id', $lineIds)
            ->where(fn ($query) => $query
                ->whereNull('workspace_id')
                ->orWhere('workspace_id', '!=', $invoice->workspace_id))
            ->exists();
        if ($hasForeignTasks) {
            throw new RuntimeException('The draft contains a milestone allocation owned by another workspace.');
        }

        foreach ($systemLines as $line) {
            // Time is linked through a pivot here, so releasing it is a detach
            // rather than nulling a column. Milestones keep a column, because a
            // deliverable is never split across lines.
            $line->timeEntries()->detach();
            ClientTask::query()
                ->where('workspace_id', $invoice->workspace_id)
                ->where('client_invoice_line_id', $line->id)
                ->update(['client_invoice_line_id' => null]);
        }

        ClientInvoiceLine::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->whereIn('type', InvoiceLineType::systemGeneratedValues())
            ->delete();

    }

    /**
     * Add recurring fixed-fee item incidences to a cadence-period invoice.
     */
    public function addRecurringItemLines(
        ClientInvoice $invoice,
        ClientAgreement $agreement,
        Carbon $periodStart,
        Carbon $periodEnd,
        int &$sortOrder,
    ): void {
        // Loaded through the tenant, not the foreign key alone. A recurring
        // item row carrying this agreement's id under another workspace is
        // reachable otherwise, and its description and price would be copied
        // straight onto this tenant's invoice.
        $agreement->setRelation(
            'recurringItems',
            $agreement->recurringItems()->where('workspace_id', $invoice->workspace_id)->get(),
        );

        foreach ($this->recurringItemBiller->linesForCycle($agreement, $periodStart, $periodEnd) as $lineData) {
            // The item carries its own currency and the line carries only a
            // number. Copying one into the other silently relabels the charge -
            // an imported EUR item becomes the same count of USD minor units -
            // so a mismatch stops here rather than reaching an invoice.
            $itemCurrency = (string) $lineData['item']->currency;
            if ($itemCurrency !== '' && $itemCurrency !== (string) $invoice->currency) {
                throw new RuntimeException(sprintf(
                    'Recurring item %s is priced in %s but invoice %s is in %s; convert it before billing.',
                    $lineData['item']->public_id,
                    (string) $itemCurrency,
                    (string) $invoice->invoice_number,
                    (string) $invoice->currency,
                ));
            }

            $line = $this->recurringItemBiller->buildLine($lineData, $sortOrder++);
            // The biller builds the line without knowing which invoice it lands
            // on. `associate` sets the key through the relation, which types it
            // correctly rather than casting an int into an unsigned column.
            $line->workspace_id = $invoice->workspace_id;
            $line->invoice()->associate($invoice);
            $line->save();
        }
    }

    /**
     * Add billable milestone tasks (with milestone_price > 0) to the invoice.
     *
     * Includes all unbilled tasks completed on or before the period end.
     * This handles the case where a task was completed in a prior period where
     * the invoice was already issued/paid — such tasks are carried forward to
     * the next available (draft or new) invoice.
     */
    public function addBillableMilestoneTasks(
        ClientCompany $company,
        ClientInvoice $invoice,
        Carbon $periodEnd,
        int &$sortOrder
    ): void {
        $tasks = ClientTask::query()
            ->where('workspace_id', $company->workspace_id)
            ->whereHas('project', fn ($q) => $q->where('client_company_id', $company->id))
            ->forAgreementScope($this->agreementFor($invoice))
            ->where('milestone_price_amount', '>', 0)
            ->whereNotNull('completed_at')
            ->whereNull('client_invoice_line_id')
            ->where('completed_at', '<=', $periodEnd->copy()->endOfDay())
            ->orderBy('completed_at')
            // Two agreements under one company generate under separate
            // agreement locks, which do not serialise each other. Both could
            // read the same unclaimed task, both create a milestone line, and
            // the second claim would overwrite the first - two invoices
            // charging one deliverable, with only one of them traceable.
            ->lockForUpdate()
            ->get();

        foreach ($tasks as $task) {
            $line = ClientInvoiceLine::create([
                'workspace_id' => $invoice->workspace_id,
                'client_invoice_id' => $invoice->id,
                'client_agreement_id' => $invoice->client_agreement_id,
                'description' => 'Milestone: '.$task->title,
                'quantity' => '1',
                'unit_amount' => (int) $task->milestone_price_amount,
                'tax_amount' => 0,
                'total_amount' => (int) $task->milestone_price_amount,
                'type' => 'milestone',
                'hours' => null,
                'line_date' => $task->completed_at,
                'sort_order' => $sortOrder++,
            ]);

            // Conditional, so the claim itself decides the race rather than
            // whichever write lands last. The row lock above should already
            // have settled it; this holds on an engine or isolation level
            // where it does not.
            $claimed = ClientTask::query()
                ->whereKey($task->getKey())
                ->whereNull('client_invoice_line_id')
                ->update(['client_invoice_line_id' => $line->id]);

            if ($claimed === 0) {
                // Someone else billed this deliverable first. Leaving the line
                // would charge it twice; the gap it leaves in `sort_order` is
                // only an ordering hint and costs nothing.
                $line->delete();
            }
        }
    }

    /**
     * Add a single prior_month_retainer line that covers all deferred time
     * entries that fit in the remaining capacity for this period.
     *
     * The whole-entry invariant (see docs/client-management/deferred-billing.md):
     * each entry is attached directly — TimeEntrySplitter is never involved.
     */
    public function addDeferredRetainerLine(
        ClientInvoice $invoice,
        ClientAgreement $agreement,
        DeferredAllocationResult $result,
        Carbon $periodEnd,
        int &$sortOrder,
    ): void {
        $hours = $result->hoursBilled;
        $line = ClientInvoiceLine::create([
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $agreement->id,
            'description' => sprintf(
                'Deferred work items applied to retainer (%s)',
                HoursQuantity::format($hours),
            ),
            // A retainer draw-down charges nothing; the capacity was already paid for.
            'quantity' => '0',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'type' => 'prior_month_retainer',
            'hours' => $hours,
            'line_date' => $periodEnd,
            'sort_order' => $sortOrder++,
        ]);

        foreach ($result->billed as $candidate) {
            $this->attach($line, $candidate->entry);
        }
    }

    /**
     * Force-bill every outstanding deferred entry we are responsible for on a
     * termination invoice. Consultant and retainer-mode work use the agreement
     * rate; flat-hourly work keeps its snapshotted subcontractor rate. Direct
     * work is tracked but never billed here.
     *
     * @param  Collection<int, ClientTimeEntry>  $entries
     */
    public function addDeferredTerminationLine(
        ClientInvoice $invoice,
        ClientAgreement $agreement,
        Collection $entries,
        int &$sortOrder,
    ): void {
        $ordinary = collect();
        $flatHourly = collect();

        foreach ($entries as $entry) {
            $rawMode = $entry->getRawOriginal('subcontractor_billing_mode');
            $mode = $rawMode === null ? null : SubcontractorBillingMode::tryFrom((string) $rawMode);

            if ($rawMode !== null && ! $mode instanceof SubcontractorBillingMode) {
                throw new RuntimeException('Deferred time has an unsupported subcontractor billing mode.');
            }
            if ($mode === SubcontractorBillingMode::Direct) {
                continue;
            }
            if ($mode === SubcontractorBillingMode::FlatHourly) {
                $flatHourly->push($entry);

                continue;
            }
            if ($mode === null && $entry->subcontractor_cost_amount !== null) {
                throw new RuntimeException('Deferred time has a subcontractor cost without a billing mode.');
            }

            $ordinary->push($entry);
        }

        $totalMinutes = (int) $ordinary->sum('minutes_worked');
        if ($totalMinutes <= 0) {
            $this->addFlatHourlySubcontractorEntries(
                $invoice,
                $flatHourly,
                Carbon::parse($invoice->service_period_end),
                $sortOrder,
            );

            return;
        }
        $hours = round($totalMinutes / 60, 4);
        $rateAmount = (int) ($agreement->hourly_rate_amount ?? 0);

        $line = ClientInvoiceLine::create([
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $agreement->id,
            'description' => sprintf(
                'Deferred work items billed on agreement termination (%s @ %s/hr)',
                HoursQuantity::format($hours),
                $this->formatMoney($rateAmount, (string) $invoice->currency),
            ),
            'quantity' => HoursQuantity::decimal($hours),
            'unit_amount' => $rateAmount,
            'tax_amount' => 0,
            'total_amount' => MoneyService::hourlyAmount($totalMinutes, $rateAmount),
            'type' => 'additional_hours',
            'hours' => $hours,
            'line_date' => $invoice->service_period_end,
            'sort_order' => $sortOrder++,
        ]);

        foreach ($ordinary as $entry) {
            $this->attach($line, $entry);
        }

        $this->addFlatHourlySubcontractorEntries(
            $invoice,
            $flatHourly,
            Carbon::parse($invoice->service_period_end),
            $sortOrder,
        );
    }

    /**
     * Add one invoice line per flat-hourly subcontractor for the period, billed
     * at the rate snapshotted on each entry. These hours are independent of the
     * retainer pool (they were excluded from the ledger), so the line is purely
     * additive. Whole entries only — never split. Idempotent on draft
     * regeneration because `subcontractor` is a system-generated line type.
     */
    public function addSubcontractorFlatHourlyLines(
        ClientCompany $company,
        ClientInvoice $invoice,
        Carbon $periodStart,
        Carbon $periodEnd,
        int &$sortOrder,
    ): void {
        $agreement = $this->agreementFor($invoice);

        $entries = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->when(
                $agreement instanceof ClientAgreement,
                fn ($q) => $q->forAgreementScope($agreement),
            )
            ->whereDoesntHave('invoiceLines')
            ->where('is_billable', true)
            ->where('is_deferred', false)
            ->flatHourlySubcontractor()
            ->whereBetween('worked_on', [$periodStart, $periodEnd])
            ->with('user:id,name')
            ->orderBy('worked_on')
            ->orderBy('id')
            ->get();

        $this->addFlatHourlySubcontractorEntries($invoice, $entries, $periodEnd, $sortOrder);
    }

    /**
     * @param  Collection<int, ClientTimeEntry>  $entries
     */
    private function addFlatHourlySubcontractorEntries(
        ClientInvoice $invoice,
        Collection $entries,
        Carbon $lineDate,
        int &$sortOrder,
    ): void {
        $missingRate = $entries->first(
            fn (ClientTimeEntry $entry): bool => $entry->subcontractor_cost_amount === null
                || trim((string) $entry->subcontractor_cost_currency) === '',
        );
        if ($missingRate instanceof ClientTimeEntry) {
            throw new RuntimeException('Flat-hourly subcontractor time requires a snapshotted amount and currency.');
        }

        // Group by (user, project, snapshot rate, rate currency) so a mid-period
        // rate change produces correctly-priced separate lines rather than one
        // blended line. The currency belongs in the key rather than only in the
        // refusal below: without it, two entries costed at the same number in
        // different currencies share a group, and the check sees only the first
        // one - so the other currency's minutes bill at this one's rate.
        $groups = $entries->groupBy(fn (ClientTimeEntry $entry): string => implode('|', [
            $entry->user_id,
            $entry->client_project_id,
            (string) $entry->subcontractor_cost_amount,
            (string) ($entry->subcontractor_cost_currency ?? ''),
        ]));

        foreach ($groups as $groupEntries) {
            /** @var ClientTimeEntry $sample */
            $sample = $groupEntries->first();
            $rateAmount = (int) $sample->subcontractor_cost_amount;
            $totalMinutes = (int) $groupEntries->sum('minutes');
            $hours = round($totalMinutes / 60, 4);
            if ($hours <= 0) {
                continue;
            }

            $name = $sample->user->name;

            // The same check the recurring-item path makes, for the same
            // reason: the cost is stored in minor units of its own currency, so
            // billing it unconverted turns an EUR rate into that many USD cents.
            $costCurrency = (string) ($sample->subcontractor_cost_currency ?? '');
            if ($costCurrency !== '' && $costCurrency !== (string) $invoice->currency) {
                throw new RuntimeException(sprintf(
                    'Subcontractor time is costed in %s but invoice %s is in %s; convert it before billing.',
                    $costCurrency,
                    (string) $invoice->invoice_number,
                    (string) $invoice->currency,
                ));
            }

            $line = ClientInvoiceLine::create([
                'workspace_id' => $invoice->workspace_id,
                'client_invoice_id' => $invoice->id,
                'client_agreement_id' => $invoice->client_agreement_id,
                'client_project_id' => $sample->client_project_id,
                'description' => sprintf(
                    'Subcontractor: %s (%s @ %s/hr)',
                    $name,
                    HoursQuantity::format($hours),
                    $this->formatMoney($rateAmount, (string) $invoice->currency),
                ),
                'quantity' => HoursQuantity::decimal($hours),
                'unit_amount' => $rateAmount,
                'tax_amount' => 0,
                'total_amount' => MoneyService::hourlyAmount($totalMinutes, $rateAmount),
                'type' => InvoiceLineType::Subcontractor->value,
                'hours' => $hours,
                'line_date' => $lineDate,
                'sort_order' => $sortOrder++,
            ]);

            foreach ($groupEntries as $entry) {
                $this->attach($line, $entry);
            }
        }
    }

    /**
     * Link all time entry fragments to their respective invoice lines, handling splits correctly.
     *
     * @param  array<int, array<int, TimeEntryFragment>>  $fragmentsToLines
     */
    public function linkAllFragmentsToLines(array $fragmentsToLines, TimeEntrySplitter $splitter): void
    {
        $entrySplitPlan = [];

        foreach ($fragmentsToLines as $lineId => $fragments) {
            foreach ($fragments as $fragment) {
                $entryId = $fragment->originalTimeEntryId;
                if (! isset($entrySplitPlan[$entryId])) {
                    $entrySplitPlan[$entryId] = [];
                }
                $entrySplitPlan[$entryId][] = [
                    'line_id' => $lineId,
                    'minutes' => $fragment->minutes,
                ];
            }
        }

        foreach ($entrySplitPlan as $entryId => $splits) {
            $entry = ClientTimeEntry::find($entryId);
            if (! $entry) {
                continue;
            }

            if (count($splits) == 1 && $splits[0]['minutes'] >= $entry->minutes_worked) {
                $this->attachToLineId($splits[0]['line_id'], $entry);

                continue;
            }

            $remainingEntry = $entry;
            $totalMinutes = $entry->minutes_worked;
            $processedMinutes = 0;

            foreach ($splits as $split) {
                $minutesForThisSplit = min($split['minutes'], $totalMinutes - $processedMinutes);

                if ($minutesForThisSplit <= 0) {
                    break;
                }

                // "Last" has to mean "takes what is left", not "last in the
                // list". An interim overage passes a single fragment covering
                // only the part of an entry that exceeded capacity, leaving the
                // covered part for cadence reconciliation - and being the only
                // split, it was treated as the last one and attached the whole
                // entry, so the covered portion was never billed by anything.
                $isLastSplit = $processedMinutes + $minutesForThisSplit >= $totalMinutes;

                if ($isLastSplit) {
                    $this->attachToLineId($split['line_id'], $remainingEntry);
                } else {
                    // Each fragment becomes its own row, carrying lineage back to
                    // the entry it came from, so the pivot's one-line-per-entry
                    // rule holds and recombination can put it back later.
                    $splitResult = $splitter->splitEntry($remainingEntry, $minutesForThisSplit);
                    $this->attachToLineId($split['line_id'], $splitResult['primary']);
                    $remainingEntry = $splitResult['overflow'];
                }

                $processedMinutes += $minutesForThisSplit;
            }
        }
    }

    /**
     * Bill one entry on one line.
     *
     * The predecessor stored this as a column on the entry. Here it is a pivot
     * with a unique index per entry, which is what stops the same work being
     * billed twice.
     */
    private function attach(ClientInvoiceLine $line, ClientTimeEntry $entry): void
    {
        $line->timeEntries()->syncWithoutDetaching([
            $entry->id => ['workspace_id' => $line->workspace_id],
        ]);
    }

    private function attachToLineId(int $lineId, ClientTimeEntry $entry): void
    {
        $line = ClientInvoiceLine::query()->find($lineId);
        if ($line instanceof ClientInvoiceLine) {
            $this->attach($line, $entry);
        }
    }

    /** Minor units as a plain decimal beside its currency code. */
    private function formatMoney(int $minorUnits, string $currency): string
    {
        return sprintf('%s %s', number_format($minorUnits / 100, 2), $currency);
    }

    /**
     * The agreement an invoice bills under, if any.
     *
     * Ordinary time was project-scoped through `forAgreementScope()`, but the
     * supplemental sources - milestones and flat-hourly subcontractor work -
     * still selected by company. With two project-scoped agreements under one
     * company, whichever generated first claimed the other project's tasks and
     * time and attached them permanently to the wrong invoice.
     *
     * Read from the invoice rather than taken as an argument, because an
     * argument is the thing four callers already forgot to pass.
     */
    private function agreementFor(ClientInvoice $invoice): ?ClientAgreement
    {
        if ($invoice->client_agreement_id === null) {
            return null;
        }

        return ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereKey($invoice->client_agreement_id)
            ->first();
    }
}
