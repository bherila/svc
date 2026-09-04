<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Places charged catch-up hours in the work month that they settled.
 *
 * A billed hour is not timeless credit. It pays debt in the invoice's service
 * period and any deliberate catch-up surplus becomes capacity earned in that
 * month, subject to the agreement's ordinary rollover expiry.
 */
final class BilledOverageLedger
{
    /**
     * @infection-ignore-all The mutation lane runs unit tests only; the status, date, grouping, null refusal, and tenant boundary require the feature database and are covered there.
     *
     * @return array<string, float> Hours keyed by the invoice's YYYY-MM service month.
     */
    public function hoursByMonthThrough(ClientAgreement $agreement, Carbon $through): array
    {
        $invoices = $this->window($agreement, $through)
            ->orderBy('service_period_end')
            ->orderBy('id')
            ->get(['invoice_number', 'service_period_end', 'hours_billed_at_rate']);

        $byMonth = [];

        foreach ($invoices as $invoice) {
            $hours = $invoice->billedOverageHoursOrFail();

            if ($hours === 0.0) {
                continue;
            }

            if ($invoice->service_period_end === null) {
                throw new RuntimeException(
                    "Invoice #{$invoice->invoice_number} has charged overage but no service period end; "
                    .'give it a period before rebuilding the capacity ledger.',
                );
            }

            $month = $invoice->service_period_end->format('Y-m');
            $byMonth[$month] = round(
                ($byMonth[$month] ?? 0.0) + $hours,
                4,
            );
        }

        return $byMonth;
    }

    /**
     * Compatibility read for aggregate audits.
     *
     * An unplaceable charged invoice remains included here so an audit cannot
     * mistake it for unbilled. Chronological calculation is stricter because it
     * cannot honestly choose which month's expiring capacity the charge made.
     */
    public function totalThrough(ClientAgreement $agreement, Carbon $through): float
    {
        $window = $this->window($agreement, $through);
        $unknown = (clone $window)->whereNull('hours_billed_at_rate')->first();

        if ($unknown instanceof ClientInvoice) {
            $unknown->billedOverageHoursOrFail();
        }

        return (float) $window->sum('hours_billed_at_rate');
    }

    /**
     * @infection-ignore-all This tenant-scoped Eloquent predicate is exercised by feature tests; the mutation lane deliberately excludes database tests.
     *
     * @return Builder<ClientInvoice>
     */
    private function window(ClientAgreement $agreement, Carbon $through): Builder
    {
        return ClientInvoice::query()
            ->where('workspace_id', $agreement->workspace_id)
            ->where('client_agreement_id', $agreement->id)
            ->whereIn('status', InvoiceStatus::charged())
            ->where(function (Builder $window) use ($through): void {
                $window
                    ->whereDate('service_period_end', '<=', $through->toDateString())
                    ->orWhereNull('service_period_end');
            });
    }
}
