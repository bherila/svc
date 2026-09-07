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
                ->where(fn (Builder $kind): Builder => $this->generatedDraftKinds($kind))
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

            $this->assertTheEntryStillHasAnInvoice($invoice, $workspace, $entry);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (DomainException|RuntimeException $exception) {
            abort(409, 'The time entry was not changed because its draft invoice could not be regenerated: '.$exception->getMessage());
        }
    }

    /**
     * The draft kinds the generator owns and rebuilds from a period.
     *
     * A null kind is read as `cadence_period` throughout regeneration, so it
     * belongs here; ad-hoc drafts are explicit selections of time and never
     * acquire an entry merely because their dates overlap it.
     *
     * @param  Builder<ClientInvoice>  $query
     * @return Builder<ClientInvoice>
     */
    private function generatedDraftKinds(Builder $query): Builder
    {
        return $query
            ->whereNull('invoice_kind')
            ->orWhereIn('invoice_kind', [
                InvoiceKind::CadencePeriod->value,
                InvoiceKind::InterimOverage->value,
            ]);
    }

    /**
     * Refuse an edit that leaves billable work on no invoice at all.
     *
     * The companion search above asks for drafts that state an agreement and
     * both period boundaries. SQL compares a null to a date as UNKNOWN and
     * `WHERE` drops the row, so a draft missing any of the three is invisible
     * to it - while the draft that owned the entry has already, correctly,
     * given the entry up. Nothing then bills the work and nothing says so.
     *
     * A draft that states no agreement must stay unrebuilt: the lookup that
     * would follow drops its project scoping and would put every project's
     * work on it, which is why the direct path refuses that case outright. So
     * the search cannot be widened to reach these drafts, and this refuses
     * instead - the edit is stranded rather than the money, and an operator
     * can see and repair the malformed draft, which is the only place the
     * missing fact can come from.
     *
     * Deliberately narrow: it fires only when this entry is one the generator
     * would have billed, ends up attached to no line, and some draft that
     * could not be evaluated might have been its home. An entry moved to a
     * date no draft covers is unallocated for an ordinary reason and is not
     * refused, and a malformed draft whose stated boundary already rules the
     * new date out cannot be the missing home either.
     */
    private function assertTheEntryStillHasAnInvoice(ClientInvoice $invoice, Workspace $workspace, ClientTimeEntry $entry): void
    {
        // Re-read rather than trust the row from before regeneration: a rebuild
        // can merge a split group back together, and a fragment that no longer
        // exists is not stranded work.
        $stranded = ClientTimeEntry::query()
            ->whereKey($entry->id)
            ->where('workspace_id', $workspace->id)
            ->pricedForInvoicing()
            ->where('is_billable', true)
            ->where('is_deferred', false)
            ->whereDoesntHave(
                'invoiceLines',
                fn (Builder $lines): Builder => $lines->where('client_invoice_lines.workspace_id', $workspace->id),
            )
            ->first();
        if (! $stranded instanceof ClientTimeEntry) {
            return;
        }

        $workedOn = $stranded->worked_on->toDateString();

        $unevaluable = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $invoice->client_company_id)
            ->where('status', 'draft')
            ->whereKeyNot($invoice->id)
            ->where(fn (Builder $kind): Builder => $this->generatedDraftKinds($kind))
            ->where(fn (Builder $missing): Builder => $missing
                ->whereNull('client_agreement_id')
                ->orWhereNull('service_period_start')
                ->orWhereNull('service_period_end'))
            ->where(fn (Builder $start): Builder => $start
                ->whereNull('service_period_start')
                ->orWhereDate('service_period_start', '<=', $workedOn))
            ->where(fn (Builder $end): Builder => $end
                ->whereNull('service_period_end')
                ->orWhereDate('service_period_end', '>=', $workedOn))
            ->where(fn (Builder $scope): Builder => $scope
                ->whereNull('client_agreement_id')
                ->orWhereHas('agreement', fn (Builder $agreement): Builder => $agreement
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $invoice->client_company_id)
                    ->where(fn (Builder $project): Builder => $project
                        ->whereNull('client_project_id')
                        ->orWhere('client_project_id', $stranded->client_project_id))))
            ->orderBy('id')
            ->first();
        if (! $unevaluable instanceof ClientInvoice) {
            return;
        }

        abort(409, sprintf(
            'The time entry was not changed because draft invoice %s states no %s and cannot be evaluated for %s, which would leave the work on no invoice at all. Complete or discard that draft first.',
            (string) $unevaluable->invoice_number,
            $this->missingFacts($unevaluable),
            $workedOn,
        ));
    }

    /** Name what the draft does not state, in the order the search reads it. */
    private function missingFacts(ClientInvoice $invoice): string
    {
        $missing = [];
        if ($invoice->client_agreement_id === null) {
            $missing[] = 'agreement';
        }
        if ($invoice->service_period_start === null) {
            $missing[] = 'service period start';
        }
        if ($invoice->service_period_end === null) {
            $missing[] = 'service period end';
        }

        return implode(' and no ', $missing);
    }
}
