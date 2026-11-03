<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\TimeEntryAllocation;
use App\Support\Billing\DeferredBuyDownRequest;
use App\Support\Billing\DeferredBuyDownSelection;
use App\Support\Billing\DeferredBuyDownSnapshot;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Support\Facades\DB;

/** One reservation boundary for the website, REST, MCP and operator CLI. */
final class DeferredBuyDownAction
{
    public function __construct(
        private readonly DeferredBacklogReader $backlogs,
        private readonly InvoiceLifecycleService $invoices,
        private readonly InvoiceNumberAllocator $numbers,
        private readonly WorkspaceClock $clock,
    ) {}

    public function preview(Workspace $workspace, ClientAgreement $agreement, DeferredBuyDownRequest $request, ?string $expectedVersion = null): DeferredBuyDownSnapshot
    {
        abort_unless($agreement->workspace_id === $workspace->id, 404);
        $today = $this->clock->today($workspace);
        $backlog = $this->backlogs->forAgreement($workspace, $agreement, $today);
        $entries = ClientTimeEntry::query()->where('workspace_id', $workspace->id)
            ->where('client_company_id', $agreement->client_company_id)->whereIn('id', $backlog->entryIds)
            ->with(TimeEntryAllocation::relations($workspace->id))
            ->orderBy('worked_on')->orderBy('id')->get();
        $version = hash('sha256', json_encode([
            'agreement' => AgentApiVersion::for($agreement), 'through' => $backlog->through,
            'entries' => $entries->map(fn (ClientTimeEntry $entry): array => [$entry->public_id, AgentApiVersion::for($entry)])->all(),
        ], JSON_THROW_ON_ERROR));

        if ($expectedVersion !== null) {
            abort_unless(hash_equals($version, $expectedVersion), 409, 'The deferred backlog has changed; preview it again.');
        }
        if ($agreement->starts_on->gt($today) || ! $agreement->billsOnARecurringCadence() || ! in_array($agreement->status, ['active', 'paused', 'terminated', 'expired'], true)) {
            throw new DomainException('Deferred buy-down requires a recurring agreement whose terms are in force or have ended.');
        }
        if ($backlog->issue !== null) {
            throw new DomainException('The deferred backlog cannot be priced safely. Correct its terms and project ownership before billing it.');
        }

        return new DeferredBuyDownSnapshot($version, DeferredBuyDownSelection::select($agreement, $entries, $request));
    }

    /**
     * Reserve the previewed selection, either on a new settlement invoice or,
     * when `$target` names one, on that existing cadence draft instead - the
     * reopened-invoice case (#594): the settlement the client agreed to pay as
     * an extra month lands on the invoice being reissued rather than on a
     * second document. The target keeps its number, its kind and its cadence
     * cycle; only its lines and totals change.
     */
    public function create(Workspace $workspace, ClientAgreement $agreement, DeferredBuyDownRequest $request, string $expectedVersion, ?ClientInvoice $target = null): ClientInvoice
    {
        abort_unless($agreement->workspace_id === $workspace->id, 404);
        if ($target !== null) {
            abort_unless($target->workspace_id === $workspace->id, 404);
        }

        return DB::transaction(function () use ($workspace, $agreement, $request, $expectedVersion, $target): ClientInvoice {
            BillingMaintenance::share($workspace->id);
            $locked = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($agreement->id)
                ->tap(Locks::forUpdate())->firstOrFail();
            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($locked->client_company_id)->firstOrFail();
            $snapshot = $this->preview($workspace, $locked, $request, $expectedVersion);
            $selection = $snapshot->selection;
            if ($selection->entries === []) {
                throw new DomainException('No whole deferred entry fits this target. Increase the target or choose round up.');
            }

            // The registry orders agreement, numbering, time, then pivots. All
            // cadence allocators take this same agreement lock before claiming.
            // A target invoice ranks between the agreement and the time it will
            // claim, so it is locked here, ahead of both.
            $invoice = $target === null ? null : $this->lockTarget($workspace, $company, $locked, $target);
            $number = $invoice === null ? $this->numbers->next($workspace) : null;
            $ids = array_map(static fn (ClientTimeEntry $entry): int => $entry->id, $selection->entries);
            $current = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
                ->with(TimeEntryAllocation::relations($workspace->id))
                ->whereIn('id', $ids)->orderBy('id')->tap(Locks::forUpdate())->get()->keyBy('id');
            foreach ($selection->entries as $entry) {
                $now = $current->get($entry->id);
                abort_unless($now !== null && AgentApiVersion::for($now) === AgentApiVersion::for($entry), 409, 'Selected deferred work changed; preview it again.');
            }
            // Current locking read also sees allocation-only changes despite
            // an earlier REPEATABLE READ snapshot. A malformed pivot must not
            // hide another invoice's claim on one of our entries.
            $claims = DB::table('client_invoice_line_time_entries')
                ->join('client_time_entries as entries', 'entries.id', '=', 'client_invoice_line_time_entries.client_time_entry_id')
                ->where('entries.workspace_id', $workspace->id)->whereIn('entries.id', $ids)
                ->orderBy('entries.id')->tap(Locks::forUpdate())->get(['client_invoice_line_time_entries.client_time_entry_id']);
            abort_unless($claims->isEmpty(), 409, 'Selected deferred work was allocated; preview it again.');

            if ($invoice !== null) {
                $this->writeSettlementLines($invoice, $selection, $locked, $this->settlementLineDate($invoice, $workspace));
                $invoice->recalculateTotals();

                return $invoice->load('lines', 'clientCompany');
            }

            $dates = array_map(static fn (ClientTimeEntry $entry): string => $entry->worked_on->toDateString(), $selection->entries);

            return $this->invoices->createDraft($workspace, $company, [
                'invoice_number' => $number, 'invoice_kind' => InvoiceKind::DeferredBuyDown->value,
                'client_agreement_id' => $locked->id, 'currency' => $locked->currency,
                'service_period_start' => min($dates), 'service_period_end' => max($dates),
                'issue_date' => $this->clock->today($workspace)->toDateString(),
            ], [], beforeGenerationRecorded: function (ClientInvoice $invoice) use ($selection, $locked): void {
                $this->writeSettlementLines($invoice, $selection, $locked, $invoice->issue_date?->toDateString());
                $invoice->recalculateTotals();
            });
        });
    }

    /**
     * Lock the invoice a settlement is to land on and prove it can carry one.
     *
     * Only a draft the cadence generator owns can be regenerated, voided and
     * reissued around the settlement lines: an issued invoice's lines are the
     * record of what the client was sent, an ad-hoc draft has no cycle for the
     * settlement to ride, and a billing schedule composes its drafts from a
     * line template rather than the generator. The company, agreement and
     * currency must be the agreement's own, as a settlement is priced from its
     * terms and read back against its ledger.
     */
    private function lockTarget(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement, ClientInvoice $target): ClientInvoice
    {
        $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($target->id)
            ->tap(Locks::forUpdate())->firstOrFail();
        if ($invoice->status !== InvoiceStatus::Draft->value) {
            throw new DomainException(sprintf(
                'A deferred buy-down can only be attached to a draft invoice; invoice %s is %s. Reopen it first if it must carry the settlement.',
                (string) $invoice->invoice_number,
                $invoice->status,
            ));
        }
        if ($invoice->invoiceKindValue() !== InvoiceKind::CadencePeriod->value) {
            throw new DomainException(sprintf(
                'A deferred buy-down can only be attached to a cadence invoice; invoice %s is a %s invoice.',
                (string) $invoice->invoice_number,
                $invoice->invoiceKindValue(),
            ));
        }
        if ($invoice->client_billing_schedule_id !== null) {
            throw new DomainException(sprintf(
                'Invoice %s was billed from a billing schedule, which cannot be regenerated, so a buy-down cannot be attached to it.',
                (string) $invoice->invoice_number,
            ));
        }
        if ($invoice->client_company_id !== $company->id) {
            throw new DomainException(sprintf(
                'Invoice %s belongs to a different client company than the agreement whose backlog it would settle.',
                (string) $invoice->invoice_number,
            ));
        }
        if ($invoice->client_agreement_id !== $agreement->id) {
            throw new DomainException(sprintf(
                'Invoice %s was not generated for the agreement whose backlog it would settle.',
                (string) $invoice->invoice_number,
            ));
        }
        if ($invoice->currency !== $agreement->currency) {
            throw new DomainException(sprintf(
                'Invoice %s is in %s but the agreement bills in %s, so its settlement cannot be priced onto it.',
                (string) $invoice->invoice_number,
                $invoice->currency,
                $agreement->currency,
            ));
        }

        return $invoice;
    }

    /**
     * The day an attached settlement's lines are dated.
     *
     * A settlement line is work describing, so a regeneration widens the
     * host invoice's service period to cover its date
     * ({@see InvoiceLineType::definingTheWorkPeriod()}): dated the day the
     * buy-down is created - a standalone settlement's own dating - it would
     * push a reopened cadence draft's period past the month it reconciles and
     * into the next cycle's window. The host's own work-period end is the day
     * its other work lines already reach, so the period stands, and the
     * backlog trend books the settlement in the host's work month, on
     * `allocatedOn()`, exactly as it books deferred work the host applied.
     * A draft with no work period falls back to its issue date, then today.
     */
    private function settlementLineDate(ClientInvoice $invoice, Workspace $workspace): string
    {
        return $invoice->service_period_end?->toDateString()
            ?? $invoice->issue_date?->toDateString()
            ?? $this->clock->today($workspace)->toDateString();
    }

    /** Write the settlement's priced lines and, when discounted, its adjustment. */
    private function writeSettlementLines(ClientInvoice $invoice, DeferredBuyDownSelection $selection, ClientAgreement $agreement, ?string $lineDate): void
    {
        $pricing = $selection->pricing;
        if ($pricing->agreementRateEntries !== []) {
            $this->line($invoice, $pricing->agreementRateEntries, $pricing->agreementRateMinutes,
                $pricing->agreementRateUnitAmount ?? 0, $pricing->agreementRateTotal, $agreement->client_project_id, $lineDate);
        }
        foreach ($pricing->flatHourlyGroups as $group) {
            $this->line($invoice, $group->entries, $group->minutes, $group->unitAmount, $group->totalAmount, $group->entries[0]->client_project_id, $lineDate);
        }
        if ($selection->request->discount > 0) {
            $invoice->lines()->create(['workspace_id' => $invoice->workspace_id, 'client_agreement_id' => $invoice->client_agreement_id, 'type' => InvoiceLineType::Adjustment->value,
                'description' => $selection->request->discountReason, 'quantity' => 1,
                'unit_amount' => -$selection->request->discount, 'tax_amount' => 0, 'total_amount' => -$selection->request->discount, 'line_date' => $lineDate,
                'sort_order' => 10000]);
        }
    }

    /** @param list<ClientTimeEntry> $entries */
    private function line(ClientInvoice $invoice, array $entries, int $minutes, int $rate, int $total, ?int $project, ?string $lineDate = null): void
    {
        $hours = MoneyService::hoursForMinutes($minutes);
        $line = $invoice->lines()->create(['workspace_id' => $invoice->workspace_id, 'client_agreement_id' => $invoice->client_agreement_id, 'client_project_id' => $project,
            'type' => InvoiceLineType::DeferredBuyDown->value, 'description' => 'Deferred work settled outside the retainer',
            'quantity' => $hours, 'hours' => $hours, 'unit_amount' => $rate,
            'tax_amount' => 0, 'total_amount' => $total, 'line_date' => $lineDate ?? $invoice->issue_date?->toDateString(), 'sort_order' => $entries[0]->id]);
        $line->timeEntries()->attach(array_map(static fn (ClientTimeEntry $entry): int => $entry->id, $entries), ['workspace_id' => $invoice->workspace_id]);
    }
}
