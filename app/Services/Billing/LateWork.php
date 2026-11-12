<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Support\Approval\LateAnswer;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Work that arrived after the invoice for its own period was charged
 * (docs/client-management/billing.md, "Late work").
 *
 * Each period's generator selects its own dates, so without this no later period
 * reaches such a row and it is never billed. It rolls forward by how it is priced:
 * a row priced alone, flat-hourly or on an agreement with no retainer, goes on the
 * next draft. A row that draws on a retainer cannot: the ledger reads work by its
 * date, so the same hour would be counted in its own month and in the draft taking
 * it. It becomes deferred work instead, as an hour answered late does
 * ({@see LateAnswer}), and draws on later free capacity.
 *
 * Only this agreement's own charged invoices make a row late, and only a row that
 * was logged or approved after that invoice was issued: a period nobody has charged
 * yet still bills its own work, and imported history is not billed twice.
 */
final class LateWork
{
    /**
     * Which of these rows are dated earlier than $before, in a period this
     * agreement has charged.
     *
     * @param  Builder<ClientTimeEntry>  $work
     * @return list<int>
     */
    public function ids(Builder $work, ClientAgreement $agreement, Carbon $before): array
    {
        $charged = ClientInvoice::query()
            ->where('workspace_id', $agreement->workspace_id)
            ->where('client_agreement_id', $agreement->id)
            ->whereIn('status', InvoiceStatus::charged())
            ->where(fn (Builder $kind): Builder => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
            ->whereNotNull('service_period_start')
            ->whereNotNull('service_period_end')
            ->whereNotNull('issued_at')
            // A generated invoice can legitimately have no linked time. Only
            // recorded import provenance excludes history, whose unlinked work
            // may already have been billed in the predecessor system.
            ->whereNotExists(fn ($imported) => $imported->select('external_import_items.id')
                ->from('external_import_items')
                ->join('external_import_runs', 'external_import_runs.id', '=', 'external_import_items.external_import_run_id')
                ->whereColumn('external_import_runs.workspace_id', 'client_invoices.workspace_id')
                ->where('external_import_items.target_type', 'invoice')
                ->whereColumn('external_import_items.target_public_id', 'client_invoices.public_id'))
            ->get(['service_period_start', 'service_period_end', 'issued_at']);

        if ($charged->isEmpty()) {
            return [];
        }

        return array_values($work->whereDate('worked_on', '<', $before->toDateString())
            ->get(['id', 'worked_on', 'created_at', 'approved_at'])
            ->filter(fn (ClientTimeEntry $entry): bool => $charged->contains(
                fn (ClientInvoice $invoice): bool => $this->arrivedAfter($entry, $invoice),
            ))
            ->map(static fn (ClientTimeEntry $entry): int => (int) $entry->id)
            ->all());
    }

    /** Did the invoice cover this row's date, and charge before the row could be billed? */
    private function arrivedAfter(ClientTimeEntry $entry, ClientInvoice $invoice): bool
    {
        $day = $entry->worked_on->toDateString();
        $issued = $invoice->issued_at;

        return $issued !== null
            && $day >= $invoice->service_period_start?->toDateString()
            && $day <= $invoice->service_period_end?->toDateString()
            && (($entry->created_at !== null && $entry->created_at->gt($issued))
                || ($entry->approved_at !== null && $entry->approved_at->gt($issued)));
    }

    /**
     * Does this agreement's late work wait as deferred work, rather than go on
     * the next draft?
     */
    public function drawsOnARetainer(ClientAgreement $agreement): bool
    {
        return $agreement->periodRetainerHours() > 0.0;
    }

    /**
     * Set a retainer agreement's late work aside before the ledger is read.
     * Call it inside the generating transaction, where
     * {@see ConsultantTimeReleaser} runs and for the same reason: each write is
     * a compare-and-set, so it takes no row locks ahead of the numbering rows.
     *
     * @return int how many rows were deferred
     */
    public function deferFromThePool(ClientCompany $company, ClientAgreement $agreement, Carbon $before, ?CarbonInterface $tookOver = null, ?CarbonInterface $handedOver = null): int
    {
        if (! $this->drawsOnARetainer($agreement)) {
            return 0;
        }

        $late = $this->ids($this->withinSegment($this->eligible($company)->forAgreementScope($agreement), $tookOver, $handedOver), $agreement, $before);

        return $late === [] ? 0 : $this->eligible($company)
            ->whereKey($late)
            ->update(['is_deferred' => true, 'lock_version' => DB::raw('lock_version + 1')]);
    }

    /**
     * Keep ordinary work inside the agreement's handovers (#699). An
     * agreement's own charged invoice can span days that are not its work: an
     * outgoing final reconciliation runs its whole natural cycle past the day
     * a successor took over, and a successor's opening invoice is dated over
     * its predecessor's last period. An hour arriving late on such a day is
     * the other agreement's, and must neither go on this draft nor be
     * deferred into this pool.
     *
     * @param  Builder<ClientTimeEntry>  $work
     * @return Builder<ClientTimeEntry>
     */
    public function withinSegment(Builder $work, ?CarbonInterface $tookOver, ?CarbonInterface $handedOver): Builder
    {
        return $work
            ->when($tookOver !== null, fn (Builder $query): Builder => $query->whereDate('worked_on', '>=', $tookOver?->toDateString()))
            ->when($handedOver !== null, fn (Builder $query): Builder => $query->whereDate('worked_on', '<=', $handedOver?->toDateString()));
    }

    /** @return Builder<ClientTimeEntry> */
    private function eligible(ClientCompany $company): Builder
    {
        return ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->unbilled()
            ->where('is_billable', true)
            ->where('is_deferred', false)
            ->retainerBillable();
    }
}
