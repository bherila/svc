<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\Billing\InvoiceKind;
use DomainException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Rebuild the exact draft whose selected time was edited or deleted. */
final class DraftInvoiceTimeRegenerator
{
    public function __construct(
        private readonly ClientInvoicingService $generated,
        private readonly InvoiceFromTimeService $selectedTime,
    ) {}

    public function regenerate(ClientInvoice $invoice, Workspace $workspace, int $mutatedEntryId): void
    {
        abort_unless($invoice->workspace_id === $workspace->id, 404);
        abort_unless($invoice->status === 'draft', 409, 'Only time on a draft invoice can be changed.');

        try {
            if ($invoice->invoiceKindValue() === InvoiceKind::AdHoc->value) {
                $this->selectedTime->regenerateDraftSelection($invoice, $workspace, $mutatedEntryId);

                return;
            }

            $this->generated->regenerateDraftInvoice($invoice);

            // Moving time across periods affects two drafts: the one that used
            // to own it and an already-existing draft covering the new date.
            // Rebuild companions of the same generated kind and agreement so
            // the old draft releases the charge and the new one picks it up.
            // Ad-hoc drafts are explicit selections and intentionally do not
            // acquire entries merely because their dates overlap.
            $entry = ClientTimeEntry::query()
                ->whereKey($mutatedEntryId)
                ->where('workspace_id', $workspace->id)
                ->first();
            if (! $entry instanceof ClientTimeEntry) {
                return;
            }

            $kind = $invoice->invoiceKindValue();
            $companions = ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $invoice->client_company_id)
                ->where('client_agreement_id', $invoice->client_agreement_id)
                ->when(
                    $kind === InvoiceKind::CadencePeriod->value,
                    fn ($query) => $query->where(
                        fn ($kindQuery) => $kindQuery
                            ->whereNull('invoice_kind')
                            ->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value),
                    ),
                    fn ($query) => $query->where('invoice_kind', $kind),
                )
                ->where('status', 'draft')
                ->whereKeyNot($invoice->id)
                ->whereDate('service_period_start', '<=', $entry->worked_on->toDateString())
                ->whereDate('service_period_end', '>=', $entry->worked_on->toDateString())
                ->orderBy('id')
                ->get();

            foreach ($companions as $companion) {
                $this->generated->regenerateDraftInvoice($companion);
            }
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (DomainException|RuntimeException $exception) {
            abort(409, 'The time entry was not changed because its draft invoice could not be regenerated: '.$exception->getMessage());
        }
    }
}
