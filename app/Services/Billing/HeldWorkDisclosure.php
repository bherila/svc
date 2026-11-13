<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Support\Billing\InvoiceDeferredWorkSnapshot;
use App\Support\Billing\InvoiceKind;
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
     */
    public function adHoc(ClientInvoice $invoice, array $droppedEntryIds = []): void
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

        $this->write($invoice, InvoiceDeferredWorkSnapshot::fromEntries([], $held));
    }

    /** Rewrite an interim draft's disclosure from the held work in its period. */
    public function interim(ClientInvoice $invoice, ClientAgreement $agreement): void
    {
        $start = $invoice->service_period_start?->toDateString();
        $end = $invoice->service_period_end?->toDateString();
        $held = $start === null || $end === null ? new Collection : ClientTimeEntry::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->heldThatWouldOtherwiseBill()
            ->forAgreementScope($agreement)
            ->whereBetween('worked_on', [$start, $end])
            ->orderBy('worked_on')
            ->orderBy('id')
            ->get();

        $this->write($invoice, InvoiceDeferredWorkSnapshot::fromEntries([], $held));
    }

    /**
     * Bring a draft's disclosure up to date without rebuilding its charges: at
     * issue, so the document freezes what is held then, and after a settlement
     * that left an ad-hoc draft's lines alone. Cadence invoices have their own
     * statement and a buy-down or terminal invoice lists none.
     */
    public function refresh(ClientInvoice $invoice): void
    {
        match (InvoiceKind::from($invoice->invoiceKindValue())) {
            InvoiceKind::AdHoc => $this->adHoc($invoice),
            InvoiceKind::InterimOverage => $this->refreshInterim($invoice),
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

    /** Nothing held and nothing listed is nothing to write. */
    private function write(ClientInvoice $invoice, ?InvoiceDeferredWorkSnapshot $snapshot): void
    {
        if ($snapshot === null && $invoice->deferred_work_statement === null) {
            return;
        }
        $this->statements->write($invoice, $snapshot);
    }

    private function refreshInterim(ClientInvoice $invoice): void
    {
        $agreement = $invoice->client_agreement_id === null ? null : ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->whereKey($invoice->client_agreement_id)
            ->first();
        if ($agreement instanceof ClientAgreement) {
            $this->interim($invoice, $agreement);
        }
    }
}
