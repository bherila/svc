<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\DeferredBuyDownRequest;
use App\Support\Billing\DeferredBuyDownSelection;
use App\Support\Billing\DeferredBuyDownSnapshot;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
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

    public function create(Workspace $workspace, ClientAgreement $agreement, DeferredBuyDownRequest $request, string $expectedVersion): ClientInvoice
    {
        abort_unless($agreement->workspace_id === $workspace->id, 404);

        return DB::transaction(function () use ($workspace, $agreement, $request, $expectedVersion): ClientInvoice {
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
            $number = $this->numbers->next($workspace);
            $ids = array_map(static fn (ClientTimeEntry $entry): int => $entry->id, $selection->entries);
            $current = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
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

            $dates = array_map(static fn (ClientTimeEntry $entry): string => $entry->worked_on->toDateString(), $selection->entries);

            return $this->invoices->createDraft($workspace, $company, [
                'invoice_number' => $number, 'invoice_kind' => InvoiceKind::DeferredBuyDown->value,
                'client_agreement_id' => $locked->id, 'currency' => $locked->currency,
                'service_period_start' => min($dates), 'service_period_end' => max($dates),
                'issue_date' => $this->clock->today($workspace)->toDateString(),
            ], [], beforeGenerationRecorded: function (ClientInvoice $invoice) use ($selection, $locked, $workspace): void {
                $pricing = $selection->pricing;
                if ($pricing->agreementRateEntries !== []) {
                    $this->line($invoice, $pricing->agreementRateEntries, $pricing->agreementRateMinutes,
                        $pricing->agreementRateUnitAmount ?? 0, $pricing->agreementRateTotal, $locked->client_project_id);
                }
                foreach ($pricing->flatHourlyGroups as $group) {
                    $this->line($invoice, $group->entries, $group->minutes, $group->unitAmount, $group->totalAmount, $group->entries[0]->client_project_id);
                }
                if ($selection->request->discount > 0) {
                    $invoice->lines()->create(['workspace_id' => $workspace->id, 'client_agreement_id' => $invoice->client_agreement_id, 'type' => InvoiceLineType::Adjustment->value,
                        'description' => $selection->request->discountReason, 'quantity' => 1,
                        'unit_amount' => -$selection->request->discount, 'tax_amount' => 0, 'total_amount' => -$selection->request->discount, 'line_date' => $invoice->issue_date?->toDateString(),
                        'sort_order' => 10000]);
                }
                $invoice->recalculateTotals();
            });
        });
    }

    /** @param list<ClientTimeEntry> $entries */
    private function line(ClientInvoice $invoice, array $entries, int $minutes, int $rate, int $total, ?int $project): void
    {
        $line = $invoice->lines()->create(['workspace_id' => $invoice->workspace_id, 'client_agreement_id' => $invoice->client_agreement_id, 'client_project_id' => $project,
            'type' => InvoiceLineType::DeferredBuyDown->value, 'description' => 'Deferred work settled outside the retainer',
            'quantity' => MoneyService::hoursForMinutes($minutes), 'unit_amount' => $rate,
            'tax_amount' => 0, 'total_amount' => $total, 'line_date' => $invoice->issue_date?->toDateString(), 'sort_order' => $entries[0]->id]);
        $line->timeEntries()->attach(array_map(static fn (ClientTimeEntry $entry): int => $entry->id, $entries), ['workspace_id' => $invoice->workspace_id]);
    }
}
