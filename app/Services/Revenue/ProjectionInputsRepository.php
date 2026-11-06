<?php

namespace App\Services\Revenue;

use App\Models\ClientAgreement;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Services\Billing\AgreementSelector;
use App\Services\Billing\BilledOverageLedger;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectionInputs;
use App\Support\Revenue\RevenueAssumptions;
use App\Support\Revenue\RevenueBand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Reads {@see ProjectionInputs} for one workspace in a fixed number of
 * queries, however many invoices it holds:
 *
 * 1. the agreements that can still bill in the window, with their client
 *    and recurring items eager-loaded through the same workspace (three
 *    queries);
 * 2. billed overage per agreement per service month over the trailing
 *    window: one grouped aggregate over charged invoices, bounded by the
 *    number of agreements times the window, not by invoice rows;
 * 3. the retainer and recurring-item charges in the window a charged
 *    invoice already carries.
 *
 * Every query names the workspace on every tenant table it touches.
 */
final class ProjectionInputsRepository
{
    /**
     * The statuses invoice generation bills
     * ({@see AgreementSelector}): an active agreement,
     * signed or not; a paused one, which generation still selects; and a
     * terminated or expired one, for the days before its end. A draft has not
     * been activated and is never billed.
     */
    public const array BILLING_STATUSES = ['active', 'paused', 'terminated', 'expired'];

    /** The service month of an invoice: its period's end, or the day it was issued when it has none. */
    public const string SERVICE_DATE = 'COALESCE(client_invoices.service_period_end, client_invoices.issue_date)';

    /**
     * A line's money before tax: its total, which includes its own tax
     * (`ClientInvoice::totalsFromLines()`), less that tax. Both sides are cast
     * to signed: `tax_amount` is unsigned, and MariaDB evaluates a difference
     * with an unsigned operand as unsigned, refusing the negative result a
     * credit or negative adjustment line produces (and it may evaluate it for
     * rows a later filter discards). SQLite accepts the same cast.
     */
    public const string PRE_TAX_AMOUNT = 'CAST(client_invoice_lines.total_amount AS SIGNED) - CAST(client_invoice_lines.tax_amount AS SIGNED)';

    public function load(Workspace $workspace, CarbonImmutable $asOf): ProjectionInputs
    {
        $shell = new ProjectionInputs($asOf->startOfDay(), []);
        $windowStart = $shell->windowStart();
        $windowEnd = $shell->windowEnd();
        $workspaceId = (int) $workspace->getKey();

        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', self::BILLING_STATUSES)
            ->where('starts_on', '<=', $windowEnd->toDateString())
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $windowStart->toDateString()))
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspaceId))
            ->with([
                'clientCompany' => fn (BelongsTo $company) => $company->where('workspace_id', $workspaceId),
                'recurringItems' => fn (HasMany $items) => $items->where('workspace_id', $workspaceId)->orderBy('sort_order')->orderBy('id'),
            ])
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $projected = [];
        foreach ($agreements as $agreement) {
            $company = $agreement->clientCompany;
            if ($company === null) {
                continue;
            }
            $projected[] = ProjectedAgreement::fromModel($agreement, $company->public_id, $company->name);
        }
        $ids = array_map(static fn (ProjectedAgreement $agreement): int => $agreement->id, $projected);

        return new ProjectionInputs(
            $asOf->startOfDay(),
            $projected,
            $ids === [] ? [] : $this->overageHistory($workspaceId, $ids, $windowStart),
            $this->billedCharges($workspaceId, $windowStart, $windowEnd),
        );
    }

    /**
     * Billed overage amount, before tax, per agreement per service month over
     * the trailing window: the same charged invoices the invoiced basis counts,
     * placed by `service_period_end` as {@see BilledOverageLedger}
     * places billed hours, falling back to the issue date.
     *
     * @param  list<int>  $agreementIds
     * @return array<int, array<string, int>>
     */
    private function overageHistory(int $workspaceId, array $agreementIds, CarbonImmutable $windowStart): array
    {
        $from = $windowStart->subMonthsNoOverflow(RevenueAssumptions::OVERAGE_WINDOW_MONTHS)->toDateString();
        $to = $windowStart->subDay()->toDateString();
        $month = 'substr('.self::SERVICE_DATE.', 1, 7)';

        $rows = $this->chargedLines($workspaceId)
            ->whereIn('client_invoices.client_agreement_id', $agreementIds)
            ->whereIn('client_invoice_lines.type', RevenueBand::billedOverageLineTypeValues())
            ->whereRaw(self::SERVICE_DATE.' >= ?', [$from])
            ->whereRaw(self::SERVICE_DATE.' <= ?', [$to])
            ->groupBy('client_invoices.client_agreement_id')
            ->groupByRaw($month)
            ->selectRaw('client_invoices.client_agreement_id as agreement_id, '.$month.' as service_month, SUM('.self::PRE_TAX_AMOUNT.') as amount')
            ->get();

        $history = [];
        foreach ($rows as $row) {
            $history[(int) $row->agreement_id][(string) $row->service_month] = (int) $row->amount;
        }

        return $history;
    }

    /**
     * Keys of the retainer cycles and item incidences in the window that a
     * charged invoice already sells: the generator dates a retainer line the
     * day its cycle begins and an item line the day it is charged.
     *
     * @return array<string, true>
     */
    private function billedCharges(int $workspaceId, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $rows = $this->chargedLines($workspaceId)
            ->whereIn('client_invoice_lines.type', [InvoiceLineType::Retainer->value, InvoiceLineType::RecurringItem->value])
            ->whereNotNull('client_invoice_lines.line_date')
            ->where('client_invoice_lines.line_date', '>=', $windowStart->toDateString())
            ->where('client_invoice_lines.line_date', '<=', $windowEnd->toDateString())
            ->select([
                'client_invoice_lines.type',
                'client_invoice_lines.line_date',
                'client_invoice_lines.client_agreement_recurring_item_id as item_id',
                'client_invoice_lines.client_agreement_id as line_agreement_id',
                'client_invoices.client_agreement_id as invoice_agreement_id',
            ])
            ->get();

        $keys = [];
        foreach ($rows as $row) {
            $date = substr((string) $row->line_date, 0, 10);
            if ($row->type === InvoiceLineType::RecurringItem->value) {
                if ($row->item_id !== null) {
                    $keys[ProjectionInputs::itemKey((int) $row->item_id, $date)] = true;
                }

                continue;
            }
            $agreementId = $row->line_agreement_id ?? $row->invoice_agreement_id;
            if ($agreementId !== null) {
                $keys[ProjectionInputs::retainerKey((int) $agreementId, $date)] = true;
            }
        }

        return $keys;
    }

    /** Lines of the workspace's charged invoices, both tables scoped to it. */
    private function chargedLines(int $workspaceId): QueryBuilder
    {
        return ClientInvoiceLine::query()
            ->toBase()
            ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_lines.client_invoice_id')
            ->where('client_invoice_lines.workspace_id', $workspaceId)
            ->where('client_invoices.workspace_id', $workspaceId)
            ->whereIn('client_invoices.status', InvoiceStatus::charged());
    }
}
