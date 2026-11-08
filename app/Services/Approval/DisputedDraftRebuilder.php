<?php

namespace App\Services\Approval;

use App\Exceptions\DisputedRowsOnDraft;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\DraftInvoiceTimeRegenerator;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Keeps drafts in step with disputes (docs/ux-overhaul.md 7b″).
 *
 * A dispute holds a row out of billing, so the draft that carries it is
 * rebuilt without it; a dispute answered, approved away or written off puts
 * the row back or keeps it off. Billing reads the hold from the dispute itself
 * ({@see ClientTimeEntry::scopeNotHeldForDispute()}), so each
 * rebuild is the ordinary generator running again.
 *
 * When: after the decision has committed, handed to `DB::afterCommit()` by
 * the caller. A client's dispute must stand whatever happens to the draft, and
 * the rebuild takes the generators' own locks - agreement, invoice, then time -
 * which the deciding transaction, holding its rows' locks already, could only
 * take out of order. Nothing here runs from a queue.
 *
 * Each rebuild runs in the generator's own transaction; nothing here opens
 * one, so a decision with no draft to rebuild starts no transaction at all.
 *
 * A refused rebuild (a buy-down settles the entry, a malformed draft) is
 * reported and left: the dispute still holds the row, and issuing the draft is
 * refused until it is settled ({@see DisputedRowsOnDraft}).
 */
final class DisputedDraftRebuilder
{
    public function __construct(
        private readonly DraftInvoiceTimeRegenerator $time,
        private readonly ClientInvoicingService $generated,
    ) {}

    /**
     * Rebuild the drafts that carry these rows, so they come off the charge.
     *
     * @param  list<int>  $entryIds
     * @param  list<int>  $expenseIds
     */
    public function withdraw(int $workspaceId, array $entryIds, array $expenseIds): void
    {
        $workspace = Workspace::query()->whereKey($workspaceId)->first();
        if (! $workspace instanceof Workspace) {
            return;
        }

        foreach ($this->draftsCarryingTime($workspaceId, $entryIds) as [$draft, $entryId]) {
            $this->attempt(fn () => $this->time->regenerate($draft, $workspace, $entryId));
        }
        foreach ($this->cadenceDraftsCarryingExpenses($workspaceId, $expenseIds) as $draft) {
            $this->attempt(fn () => $this->generated->regenerateDraftInvoice($draft));
        }
    }

    /**
     * Rebuild the generated drafts whose period covers these rows, so a row no
     * longer held is billed where it belongs.
     *
     * @param  list<int>  $entryIds
     * @param  list<int>  $expenseIds
     */
    public function restore(int $workspaceId, array $entryIds, array $expenseIds): void
    {
        $workspace = Workspace::query()->whereKey($workspaceId)->first();
        if (! $workspace instanceof Workspace) {
            return;
        }

        $entries = $entryIds === [] ? collect() : ClientTimeEntry::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $entryIds)
            ->whereDoesntHave('invoiceLines', fn ($lines) => $lines->where('client_invoice_lines.workspace_id', $workspaceId))
            ->orderBy('id')
            ->get();
        foreach ($entries as $entry) {
            $this->attempt(fn () => $this->time->regenerateCovering($workspace, $entry));
        }

        $expenses = $expenseIds === [] ? collect() : ClientExpense::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $expenseIds)
            ->whereNull('client_invoice_line_id')
            ->orderBy('id')
            ->get();
        foreach ($expenses as $expense) {
            $drafts = ClientInvoice::query()
                ->where('workspace_id', $workspaceId)
                ->where('client_company_id', $expense->client_company_id)
                ->where('status', InvoiceStatus::Draft->value)
                ->where('invoice_kind', InvoiceKind::CadencePeriod->value)
                ->whereNotNull('client_agreement_id')
                ->whereDate('service_period_start', '<=', $expense->spent_on->toDateString())
                ->whereDate('service_period_end', '>=', $expense->spent_on->toDateString())
                ->orderBy('id')
                ->get();
            foreach ($drafts as $draft) {
                $this->attempt(fn () => $this->generated->regenerateDraftInvoice($draft));
            }
        }
    }

    /**
     * @param  list<int>  $entryIds
     * @return list<array{ClientInvoice, int}>
     */
    private function draftsCarryingTime(int $workspaceId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $links = DB::table('client_invoice_line_time_entries as pivot')
            ->join('client_invoice_lines as lines', 'lines.id', '=', 'pivot.client_invoice_line_id')
            ->join('client_invoices as invoices', 'invoices.id', '=', 'lines.client_invoice_id')
            ->where('pivot.workspace_id', $workspaceId)
            ->where('lines.workspace_id', $workspaceId)
            ->where('invoices.workspace_id', $workspaceId)
            ->where('invoices.status', InvoiceStatus::Draft->value)
            ->whereIn('pivot.client_time_entry_id', $entryIds)
            ->orderBy('invoices.id')
            ->orderBy('pivot.client_time_entry_id')
            ->get(['invoices.id as invoice_id', 'pivot.client_time_entry_id as entry_id']);

        $drafts = ClientInvoice::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $links->pluck('invoice_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $pairs = [];
        $rebuilt = [];
        foreach ($links as $link) {
            $draft = $drafts->get((int) $link->invoice_id);
            if (! $draft instanceof ClientInvoice) {
                continue;
            }
            // A generated draft is rebuilt whole, so once is enough; an ad-hoc
            // draft refreshes one named entry at a time.
            $generated = $draft->invoiceKindValue() !== InvoiceKind::AdHoc->value;
            if ($generated && isset($rebuilt[$draft->id])) {
                continue;
            }
            $rebuilt[$draft->id] = true;
            $pairs[] = [$draft, (int) $link->entry_id];
        }

        return $pairs;
    }

    /**
     * @param  list<int>  $expenseIds
     * @return list<ClientInvoice>
     */
    private function cadenceDraftsCarryingExpenses(int $workspaceId, array $expenseIds): array
    {
        if ($expenseIds === []) {
            return [];
        }

        return array_values(ClientInvoice::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', InvoiceStatus::Draft->value)
            ->where('invoice_kind', InvoiceKind::CadencePeriod->value)
            ->whereHas('lines', fn ($lines) => $lines
                ->where('client_invoice_lines.workspace_id', $workspaceId)
                ->whereIn('client_invoice_lines.id', ClientExpense::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $expenseIds)
                    ->whereNotNull('client_invoice_line_id')
                    ->select('client_invoice_line_id')))
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** One rebuild; a refusal is reported, never thrown back at the decision. */
    private function attempt(callable $rebuild): void
    {
        try {
            $rebuild();
        } catch (HttpExceptionInterface|DomainException|RuntimeException $refused) {
            report($refused);
        }
    }
}
