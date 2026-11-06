<?php

namespace App\Services\Revenue;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Support\Billing\InvoiceStatus;
use App\Support\Revenue\ActualAmount;
use App\Support\Revenue\CollectedPayment;
use App\Support\Revenue\OpenInvoice;
use App\Support\Revenue\ProjectionInputs;
use App\Support\Revenue\RevenueActualsInputs;
use App\Support\Revenue\RevenueBand;
use App\Support\Revenue\RevenueClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Reads {@see RevenueActualsInputs} for one workspace in five queries:
 *
 * 1. its client companies;
 * 2. invoiced money: one grouped aggregate of pre-tax line amounts over
 *    charged invoices (`issued`, `partially_paid`, `paid`, `disputed`; never
 *    draft or void), by client, line type and placement date;
 * 3. received payments in the window, with their client;
 * 4. the pre-tax composition, by line type, of the invoices those payments
 *    paid: one grouped aggregate;
 * 5. charged invoices with a balance still owed.
 *
 * Placement (invoiced basis). An invoice is placed on its service month -
 * `service_period_end`, as `BilledOverageLedger` places billed hours - or on
 * its issue date when it has no period. A line sold in advance (the retainer
 * and recurring items, {@see RevenueBand::placesByOwnDate()}) is placed on its
 * own date instead, the first day of the cycle or the charge date it sells.
 * Corrections are made in place on an issued invoice's lines, and credits are
 * lines of the invoice they apply to, so both net in that invoice's month.
 * An invoice with neither a period nor an issue date cannot be placed and is
 * left to `billing.audit_undated_collectible_invoices`.
 *
 * Every query names the workspace on every tenant table it touches.
 */
final class RevenueActualsRepository
{
    public function load(Workspace $workspace, CarbonImmutable $asOf): RevenueActualsInputs
    {
        $workspaceId = (int) $workspace->getKey();
        $from = RevenueActualsInputs::windowStart($asOf);
        // Lines sold in advance can be dated inside the projection window, so
        // the invoiced read runs to its end; the projection leaves those
        // charges out as already billed.
        $invoicedTo = (new ProjectionInputs($asOf, []))->windowEnd();

        $clients = [];
        foreach (ClientCompany::query()->where('workspace_id', $workspaceId)->orderBy('id')->get(['id', 'public_id', 'name', 'is_active']) as $company) {
            $clients[(int) $company->id] = new RevenueClient((int) $company->id, $company->public_id, $company->name, $company->is_active);
        }

        return new RevenueActualsInputs(
            $asOf->startOfDay(),
            $clients,
            $this->invoiced($workspaceId, $from, $invoicedTo),
            $this->collected($workspaceId, $from, $asOf->startOfDay()),
            $this->openInvoices($workspaceId),
        );
    }

    /** @return list<ActualAmount> */
    private function invoiced(int $workspaceId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ownDate = RevenueBand::ownDateLineTypeValues();
        $placeholders = implode(', ', array_fill(0, count($ownDate), '?'));
        $placement = 'CASE WHEN client_invoice_lines.type IN ('.$placeholders.') '
            .'THEN COALESCE(client_invoice_lines.line_date, '.ProjectionInputsRepository::SERVICE_DATE.') '
            .'ELSE '.ProjectionInputsRepository::SERVICE_DATE.' END';

        $lines = $this->chargedLines($workspaceId)
            ->selectRaw('client_invoices.client_company_id as client_id, client_invoice_lines.type as line_type, '.$placement.' as placed_on, '.ProjectionInputsRepository::PRE_TAX_AMOUNT.' as amount', $ownDate);

        $rows = DB::query()
            ->fromSub($lines, 'placed')
            ->whereNotNull('placed_on')
            ->where('placed_on', '>=', $from->toDateString())
            ->where('placed_on', '<=', $to->toDateString())
            ->groupBy('client_id', 'line_type', 'placed_on')
            ->selectRaw('client_id, line_type, placed_on, SUM(amount) as amount')
            ->get();

        $amounts = [];
        foreach ($rows as $row) {
            if ((int) $row->amount === 0) {
                continue;
            }
            $amounts[] = new ActualAmount(
                (int) $row->client_id,
                CarbonImmutable::parse(substr((string) $row->placed_on, 0, 10))->startOfDay(),
                RevenueBand::forStoredLineType((string) $row->line_type),
                (int) $row->amount,
            );
        }

        return $amounts;
    }

    /**
     * Payments net of refunds, on the day received. A payment counts what it
     * adds to its invoice ({@see ClientInvoicePayment::paidContribution()}):
     * a succeeded payment less its refunds, a disputed one less what the
     * dispute holds back, nothing for pending, failed or cancelled ones. The
     * status is read in PHP for the reason that method gives. Refunds net on
     * the payment they return: a processor refund updates the payment's
     * refunded amount without a dated record of its own.
     *
     * @return list<ActualAmount>
     */
    private function collected(int $workspaceId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $payments = ClientInvoicePayment::query()
            ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_payments.client_invoice_id')
            ->where('client_invoice_payments.workspace_id', $workspaceId)
            ->where('client_invoices.workspace_id', $workspaceId)
            ->whereIn('client_invoices.status', InvoiceStatus::charged())
            ->whereNotNull('client_invoice_payments.received_on')
            ->where('client_invoice_payments.received_on', '>=', $from->toDateString())
            ->where('client_invoice_payments.received_on', '<=', $to->toDateString())
            ->orderBy('client_invoice_payments.id')
            ->get([
                'client_invoice_payments.id', 'client_invoice_payments.client_invoice_id', 'client_invoice_payments.status',
                'client_invoice_payments.amount', 'client_invoice_payments.refunded_amount', 'client_invoice_payments.disputed_amount',
                'client_invoice_payments.received_on', 'client_invoices.client_company_id as client_id',
            ]);

        $collected = [];
        foreach ($payments as $payment) {
            $received = $payment->received_on;
            if ($received === null) {
                continue;
            }
            $collected[] = new CollectedPayment(
                (int) $payment->getAttribute('client_id'),
                (int) $payment->getAttribute('client_invoice_id'),
                $received->startOfDay(),
                $payment->paidContribution(),
            );
        }
        if ($collected === []) {
            return [];
        }

        $invoiceIds = array_values(array_unique(array_map(static fn (CollectedPayment $payment): int => $payment->invoiceId, $collected)));
        $compositions = [];
        $rows = $this->chargedLines($workspaceId)
            ->whereIn('client_invoices.id', $invoiceIds)
            ->groupBy('client_invoice_lines.client_invoice_id', 'client_invoice_lines.type')
            ->selectRaw('client_invoice_lines.client_invoice_id as invoice_id, client_invoice_lines.type as line_type, SUM(client_invoice_lines.total_amount) as gross, SUM('.ProjectionInputsRepository::PRE_TAX_AMOUNT.') as net')
            ->get();
        foreach ($rows as $row) {
            $invoiceId = (int) $row->invoice_id;
            $compositions[$invoiceId] ??= ['gross' => 0, 'bands' => []];
            $compositions[$invoiceId]['gross'] += (int) $row->gross;
            $band = RevenueBand::forStoredLineType((string) $row->line_type)->value;
            $compositions[$invoiceId]['bands'][$band] = ($compositions[$invoiceId]['bands'][$band] ?? 0) + (int) $row->net;
        }

        return RevenueActualsInputs::collectedAmounts($collected, $compositions);
    }

    /** @return list<OpenInvoice> */
    private function openInvoices(int $workspaceId): array
    {
        $open = [];
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', InvoiceStatus::charged())
            ->where('balance_amount', '>', 0)
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspaceId))
            ->orderBy('id')
            ->get(['id', 'client_company_id', 'balance_amount', 'due_date']);
        foreach ($invoices as $invoice) {
            $open[] = new OpenInvoice((int) $invoice->getAttribute('client_company_id'), $invoice->balance_amount, $invoice->due_date);
        }

        return $open;
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
