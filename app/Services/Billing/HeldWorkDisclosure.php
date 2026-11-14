<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Support\Billing\InvoiceDeferredWorkSnapshot;
use App\Support\Billing\InvoiceKind;
use App\Support\Concurrency\Locks;
use DomainException;
use Illuminate\Support\Collection;

/**
 * What a client's dispute took off an ad-hoc or interim draft
 * (docs/ux-overhaul.md 7b″).
 *
 * A cadence draft lists held work beside its deferred work when it is composed
 * ({@see ClientInvoicingService::refreshDeferredWorkSnapshotAtIssue()}). The
 * other two drafts a dispute rebuilds wrote nothing, so the hour came off the
 * charge and disappeared from the document. This writes the same disclosure -
 * held entries tagged **Disputed** - through the one snapshot writer, which
 * keeps the project-visibility index beside it:
 *
 * - **Ad hoc:** the selections the draft dropped because a dispute holds them.
 *   An ad-hoc draft is an explicit selection, so it lists what was selected and
 *   taken off, never every disputed entry of the company. What it listed is
 *   kept until the dispute is settled, when the row is no longer held.
 * - **Interim:** held work that would otherwise bill, of the agreement's scope,
 *   in the draft's own period - the time an interim overage is drawn from.
 *
 * Reads here are ordinary: they decide what the document says, never a charge.
 * At issue they are compared with the rows the issue locked
 * (`InvoiceLifecycleService::lockDeferredCandidates()`), as the cadence
 * statement is, so a caller's older snapshot cannot freeze a row as it was.
 */
final class HeldWorkDisclosure
{
    public function __construct(
        private readonly InvoiceDeferredWorkStatements $statements = new InvoiceDeferredWorkStatements,
    ) {}

    /**
     * Rewrite an ad-hoc draft's disclosure: what it listed before plus the
     * selections just dropped, each kept only while a dispute still holds it.
     *
     * @param  list<int>  $droppedEntryIds
     * @param  array<int, array<string, mixed>>|null  $current  at issue, the candidates read with row locks
     */
    public function adHoc(ClientInvoice $invoice, array $droppedEntryIds = [], ?array $current = null): void
    {
        $ids = array_values(array_unique([...$this->disclosedIds($invoice), ...$droppedEntryIds]));
        $held = $ids === [] ? new Collection : ClientTimeEntry::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->whereIn('id', $ids)
            ->heldForDispute()
            ->orderBy('worked_on')
            ->orderBy('id')
            ->get();

        $this->assertCurrent($held, $current);
        $this->write($invoice, InvoiceDeferredWorkSnapshot::fromEntries([], $held));
    }

    /**
     * Rewrite an interim draft's disclosure from the held work in its period.
     *
     * @param  array<int, array<string, mixed>>|null  $current  at issue, the candidates read with row locks
     */
    public function interim(ClientInvoice $invoice, ClientAgreement $agreement, ?array $current = null): void
    {
        $start = $invoice->service_period_start;
        $end = $invoice->service_period_end;
        $held = $start === null || $end === null ? new Collection : ClientTimeEntry::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->heldThatWouldOtherwiseBill()
            ->forAgreementScope($agreement)
            ->whereBetween('worked_on', [$start->toDateString(), $end->toDateString()])
            ->orderBy('worked_on')
            ->orderBy('id')
            ->get();

        $this->assertCurrent($held, $current);
        $this->write($invoice, InvoiceDeferredWorkSnapshot::fromEntries([], $held));
    }

    /**
     * Bring a draft's disclosure up to date without rebuilding its charges: at
     * issue, so the document freezes what is held then, and after a settlement
     * that left an ad-hoc draft's lines alone. Cadence invoices have their own
     * statement and a buy-down or terminal invoice lists none.
     *
     * @param  array<int, array<string, mixed>>|null  $current  at issue, the candidates read with row locks
     */
    public function refresh(ClientInvoice $invoice, ?array $current = null): void
    {
        match (InvoiceKind::from($invoice->invoiceKindValue())) {
            InvoiceKind::AdHoc => $this->adHoc($invoice, current: $current),
            InvoiceKind::InterimOverage => $this->refreshInterim($invoice, $current),
            InvoiceKind::CadencePeriod, InvoiceKind::Terminal, InvoiceKind::DeferredBuyDown => null,
        };
    }

    /**
     * The entries an ad-hoc draft already lists. An ad-hoc draft lists nothing
     * but held work, so every one of them is a held selection.
     *
     * @return list<int>
     */
    public function disclosedIds(ClientInvoice $invoice): array
    {
        return array_map(
            static fn (array $entry): int => $entry['id'],
            $invoice->deferredWorkSnapshot()->entries ?? [],
        );
    }

    /**
     * The time a draft's held-work list may name at issue, read with row
     * locks: an ad-hoc draft's listed selections, or every held row of the
     * company an interim draft could list. Null for a kind that lists none.
     *
     * @return array<int, array<string, mixed>>|null each candidate's columns by id
     */
    public function lockCandidates(ClientInvoice $draft): ?array
    {
        // The stored kind, not `invoiceKindValue()`: a legacy or unrecognised
        // kind is a cadence draft there, and this lists nothing for either.
        $scope = match (InvoiceKind::tryFrom((string) $draft->invoice_kind)) {
            InvoiceKind::AdHoc => fn ($query) => $query->whereIn('id', $this->disclosedIds($draft)),
            InvoiceKind::InterimOverage => fn ($query) => $query->heldThatWouldOtherwiseBill(),
            InvoiceKind::CadencePeriod, InvoiceKind::Terminal, InvoiceKind::DeferredBuyDown, null => null,
        };
        if ($scope === null) {
            return null;
        }

        return ClientTimeEntry::query()
            ->where('workspace_id', $draft->workspace_id)
            ->where('client_company_id', $draft->client_company_id)
            ->where($scope)
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->get()
            ->mapWithKeys(fn (ClientTimeEntry $entry): array => [$entry->id => $entry->getAttributes()])
            ->all();
    }

    /**
     * Every row the list names must be the row as it is now, or the issued
     * document would print work since withdrawn as it was.
     *
     * @param  Collection<int, ClientTimeEntry>  $held
     * @param  array<int, array<string, mixed>>|null  $current
     */
    private function assertCurrent(Collection $held, ?array $current): void
    {
        if ($current === null) {
            return;
        }
        foreach ($held as $entry) {
            if (($current[$entry->id] ?? null) !== $entry->getAttributes()) {
                throw new DomainException('This invoice changed after it was loaded. Reload it and issue it again.');
            }
        }
    }

    /** Nothing held and nothing listed is nothing to write. */
    private function write(ClientInvoice $invoice, ?InvoiceDeferredWorkSnapshot $snapshot): void
    {
        if ($snapshot === null && $invoice->deferred_work_statement === null) {
            return;
        }
        $this->statements->write($invoice, $snapshot);
    }

    /** @param array<int, array<string, mixed>>|null $current */
    private function refreshInterim(ClientInvoice $invoice, ?array $current): void
    {
        $agreement = $invoice->client_agreement_id === null ? null : ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->whereKey($invoice->client_agreement_id)
            ->first();
        if ($agreement instanceof ClientAgreement) {
            $this->interim($invoice, $agreement, $current);
        }
    }
}
