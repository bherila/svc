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
 * Only this agreement's own charged invoices make a row late: a period nobody has
 * charged yet still bills its own work.
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
            ->get(['service_period_start', 'service_period_end']);

        if ($charged->isEmpty()) {
            return [];
        }

        return array_values($work->whereDate('worked_on', '<', $before->toDateString())
            ->get(['id', 'worked_on'])
            ->filter(fn (ClientTimeEntry $entry): bool => $charged->contains(
                fn (ClientInvoice $invoice): bool => $entry->worked_on->toDateString() >= $invoice->service_period_start?->toDateString()
                    && $entry->worked_on->toDateString() <= $invoice->service_period_end?->toDateString(),
            ))
            ->map(static fn (ClientTimeEntry $entry): int => (int) $entry->id)
            ->all());
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
    public function deferFromThePool(ClientCompany $company, ClientAgreement $agreement, Carbon $before): int
    {
        if (! $this->drawsOnARetainer($agreement)) {
            return 0;
        }

        $late = $this->ids($this->eligible($company)->forAgreementScope($agreement), $agreement, $before);

        return $late === [] ? 0 : $this->eligible($company)
            ->whereKey($late)
            ->update(['is_deferred' => true, 'lock_version' => DB::raw('lock_version + 1')]);
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
