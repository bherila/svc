<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The catch-up that a monthly invoice was sized against while an earlier
 * invoice was still a draft.
 *
 * The capacity ledger books each month's work from time entries, but reads
 * catch-up hours only from charged invoices. Monthly generation therefore
 * overlays each earlier cadence draft's `hours_billed_at_rate` as though it
 * were issued. Without the overlay, the next invoice would open on debt that
 * the draft already bills and charge it again. With the overlay, the later
 * invoice relies on a charge that nobody has made yet. So the earlier draft
 * must be issued before the later invoice is, and it must not be discarded
 * while a later invoice relies on it: the lifecycle refuses both
 * (`EarlierDraftCatchUpTest`).
 *
 * Only monthly agreements read billed overage (see InvoiceLedgerBuilder), so
 * only they have this dependency.
 */
final class DraftCatchUpDependencies
{
    /**
     * Catch-up hours charged by earlier cadence drafts, keyed by the YYYY-MM
     * work month each settles: the same key the billed-overage ledger uses.
     *
     * Issued invoices are already in the billed-overage ledger, so only drafts
     * are missing from it. A draft whose work ends on or after `$before` is
     * excluded: the ledger is measured through the range being generated, and a
     * later draft is rebuilt against this one, not the other way round. Void
     * and deleted invoices charged nothing and are not counted.
     *
     * An unknown figure on a draft that carries an additional-hours line is
     * refused rather than read as zero, as it is on every billed-overage read:
     * dropping it would bill the same debt again. With no such line (a migrated
     * draft, typically) nothing on it bills at the rate, so it charges nothing.
     *
     * @infection-ignore-all The tenant, status, kind and date predicates need the feature database and are covered by CorrectionPoolDrawTest and EarlierDraftCatchUpTest; the mutation lane runs unit tests only.
     *
     * @return array<string, float>
     */
    public function chargesByMonthBefore(ClientAgreement $agreement, int $companyId, Carbon $before, ?int $excludeInvoiceId): array
    {
        $drafts = $this->cadenceInvoices((int) $agreement->workspace_id, $companyId, (int) $agreement->id)
            ->where('status', InvoiceStatus::Draft->value)
            ->when($excludeInvoiceId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeInvoiceId))
            ->whereDate('service_period_end', '<', $before->toDateString())
            ->orderBy('service_period_end')
            ->orderBy('id')
            ->where(fn (Builder $query): Builder => $this->billingCatchUp($query))
            ->get(['id', 'invoice_number', 'service_period_end', 'hours_billed_at_rate']);

        $byMonth = [];
        foreach ($drafts as $draft) {
            if ($draft->hours_billed_at_rate === null) {
                throw new DomainException(
                    "Draft invoice {$draft->invoice_number} records no billed-overage hours, so what it bills cannot "
                    .'be known and a later invoice cannot be sized without risking a second charge for the same '
                    .'hours. Regenerate or discard that draft first.',
                );
            }
            $hours = (float) $draft->hours_billed_at_rate;
            if ($hours === 0.0 || $draft->service_period_end === null) {
                continue;
            }
            $month = $draft->service_period_end->format('Y-m');
            $byMonth[$month] = round(($byMonth[$month] ?? 0.0) + $hours, 4);
        }

        return $byMonth;
    }

    /**
     * Refuse to issue a monthly cadence invoice while an earlier draft whose
     * catch-up it was sized against has not been issued.
     *
     * @infection-ignore-all The predicates need the feature database and are covered by EarlierDraftCatchUpTest; the mutation lane runs unit tests only.
     */
    public function assertIssuable(ClientInvoice $invoice): void
    {
        $agreement = $this->monthlyAgreementOf($invoice);
        if (! $agreement instanceof ClientAgreement || $invoice->service_period_start === null) {
            return;
        }

        $earlier = $this->cadenceInvoices((int) $invoice->workspace_id, (int) $invoice->client_company_id, (int) $agreement->id)
            ->where('status', InvoiceStatus::Draft->value)
            ->whereKeyNot($invoice->getKey())
            ->whereDate('service_period_end', '<', $invoice->service_period_start->toDateString())
            ->where(fn (Builder $query): Builder => $this->billingCatchUp($query))
            ->orderBy('service_period_end')
            ->value('invoice_number');

        if (is_string($earlier)) {
            throw new DomainException(
                "Draft invoice {$earlier} covers earlier work and bills catch-up hours that this invoice was sized "
                .'against as though they were already charged. Issue or discard it first, then regenerate this '
                .'invoice if it was discarded.',
            );
        }
    }

    /**
     * Refuse to discard or void a monthly cadence draft that bills catch-up
     * while a later live invoice was sized against that charge.
     *
     * @infection-ignore-all The predicates need the feature database and are covered by EarlierDraftCatchUpTest; the mutation lane runs unit tests only.
     */
    public function assertDiscardable(ClientInvoice $draft): void
    {
        $agreement = $this->monthlyAgreementOf($draft);
        if (! $agreement instanceof ClientAgreement
            || $draft->service_period_end === null
            || ! $this->billingCatchUp(
                ClientInvoice::query()->where('workspace_id', $draft->workspace_id)->whereKey($draft->getKey()),
            )->exists()) {
            return;
        }

        $later = $this->cadenceInvoices((int) $draft->workspace_id, (int) $draft->client_company_id, (int) $agreement->id)
            ->whereIn('status', InvoiceStatus::live())
            ->whereKeyNot($draft->getKey())
            ->whereDate('service_period_start', '>', $draft->service_period_end->toDateString())
            ->orderBy('service_period_start')
            ->value('invoice_number');

        if (is_string($later)) {
            throw new DomainException(
                "Invoice {$later} covers later work and was sized against the catch-up hours this draft bills. "
                .'Discard that invoice first, then discard this draft and regenerate it.',
            );
        }
    }

    /**
     * Invoices that bill catch-up, or may: a non-zero figure, or no figure at
     * all beside a line that bills hours at the rate.
     *
     * @param  Builder<ClientInvoice>  $query
     * @return Builder<ClientInvoice>
     */
    private function billingCatchUp(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('hours_billed_at_rate', '!=', 0)
                ->orWhere(function (Builder $unknown): void {
                    $unknown->whereNull('hours_billed_at_rate')
                        ->whereHas('lines', function (Builder $lines): void {
                            $lines->whereColumn('client_invoice_lines.workspace_id', 'client_invoices.workspace_id')
                                ->where('type', InvoiceLineType::AdditionalHours->value);
                        });
                });
        });
    }

    /**
     * The agreement of a cadence invoice sized by the monthly generator.
     *
     * Asked of the invoice's own stored statement first: that records the
     * cadence it was generated under, which an operator changing the
     * agreement's cadence afterwards does not change. Only an invoice with no
     * readable statement (hand-edited, or generated before statements existed)
     * falls back to the agreement's current cadence.
     */
    private function monthlyAgreementOf(ClientInvoice $invoice): ?ClientAgreement
    {
        if ($invoice->client_agreement_id === null
            || ! in_array($invoice->invoice_kind, [null, InvoiceKind::CadencePeriod->value], true)) {
            return null;
        }

        $agreement = ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->whereKey($invoice->client_agreement_id)
            ->first();
        if (! $agreement instanceof ClientAgreement) {
            return null;
        }

        $generatedUnder = $invoice->hoursStatement()?->cadence;
        $monthly = $generatedUnder === null
            ? $agreement->effectiveBillingCadence() === BillingCadence::Monthly
            : $generatedUnder === BillingCadence::Monthly->value;

        return $monthly ? $agreement : null;
    }

    /**
     * @return Builder<ClientInvoice>
     */
    private function cadenceInvoices(int $workspaceId, int $companyId, int $agreementId): Builder
    {
        return ClientInvoice::query()
            ->where('workspace_id', $workspaceId)
            ->where('client_company_id', $companyId)
            ->where('client_agreement_id', $agreementId)
            ->where(function (Builder $query): void {
                $query->whereNull('invoice_kind')
                    ->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value);
            });
    }
}
