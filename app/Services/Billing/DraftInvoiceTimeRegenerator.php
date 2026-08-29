<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\Billing\InvoiceKind;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
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
            // Rebuild every generated draft structurally eligible for the new
            // date. A move can cross an agreement renewal, and a non-monthly
            // cycle can move between an interim preview and its closing cadence
            // invoice, so the source agreement and kind are not eligibility
            // boundaries. Ad-hoc drafts remain explicit selections and never
            // acquire entries merely because their dates overlap.
            $entry = ClientTimeEntry::query()
                ->whereKey($mutatedEntryId)
                ->where('workspace_id', $workspace->id)
                ->first();
            if (! $entry instanceof ClientTimeEntry) {
                return;
            }

            $companions = ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $invoice->client_company_id)
                ->whereNotNull('client_agreement_id')
                ->where(fn (Builder $kind): Builder => $kind
                    ->whereNull('invoice_kind')
                    ->orWhereIn('invoice_kind', [
                        InvoiceKind::CadencePeriod->value,
                        InvoiceKind::InterimOverage->value,
                    ]))
                ->whereHas('agreement', fn (Builder $agreement): Builder => $agreement
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $invoice->client_company_id)
                    ->where(fn (Builder $scope): Builder => $scope
                        ->whereNull('client_project_id')
                        ->orWhere('client_project_id', $entry->client_project_id)))
                ->where('status', 'draft')
                ->whereKeyNot($invoice->id)
                ->whereDate('service_period_start', '<=', $entry->worked_on->toDateString())
                ->whereDate('service_period_end', '>=', $entry->worked_on->toDateString())
                ->get();

            // Interim first, cadence last. Cadence regeneration deliberately
            // releases unissued interim claims before reconciling the closing
            // cycle; reversing this order would let the interim reclaim time
            // after the cadence invoice had already rebuilt.
            $companions = $companions->sortBy(fn (ClientInvoice $candidate): array => [
                $candidate->invoiceKindValue() === InvoiceKind::InterimOverage->value ? 0 : 1,
                $candidate->id,
            ]);

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
