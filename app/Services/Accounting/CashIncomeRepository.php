<?php

namespace App\Services\Accounting;

use App\Models\AccountingCashRow;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Accounting\AccountingQuery;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Finance\FinanceCaller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** A bounded union of existing finance facts; no processor calls or duplicate stored ledger. */
final readonly class CashIncomeRepository
{
    public function __construct(private FinanceReconciliationOperations $finance) {}

    /** @return Builder<AccountingCashRow> */
    public function query(FinanceCaller $caller, Workspace $workspace, AccountingQuery $query): Builder
    {
        $payments = $this->finance->accountingPayments($caller, $workspace)
            ->select([])
            ->selectRaw("id * 4 as id, public_id as source_id, 'payment' as kind, public_id as payment_id, currency, case when status in ('succeeded', 'refunded', 'disputed') then amount else 0 end as amount, coalesce(provider_fee_amount, 0) as fee_amount, received_on as effective_on, substr(updated_at, 1, 19) as updated_at, lock_version, NULL as reverses_id, refunded_amount
                - coalesce((select sum(case when r.kind = 'refund' then r.amount else -r.amount end) from client_invoice_payment_refunds r where r.workspace_id = client_invoice_payments.workspace_id and r.client_invoice_payment_id = client_invoice_payments.id), 0)
                + coalesce((select sum(m.principal_minor) from processor_movements m where m.workspace_id = client_invoice_payments.workspace_id and m.client_invoice_payment_id = client_invoice_payments.id and m.kind in ('refund', 'refund_reversal')), 0) as unattributed_refund_amount")
            ->toBase();
        $refunds = DB::table('client_invoice_payment_refunds as r')
            ->join('client_invoice_payments as p', function ($join) use ($workspace): void {
                $join->on('p.id', '=', 'r.client_invoice_payment_id')->where('p.workspace_id', $workspace->id);
            })
            ->leftJoin('client_invoice_payment_refunds as original', function ($join) use ($workspace): void {
                $join->on('original.id', '=', 'r.reverses_refund_id')->where('original.workspace_id', $workspace->id);
            })
            ->where('r.workspace_id', $workspace->id)
            ->selectRaw("r.id * 4 + 1 as id, r.public_id as source_id, CASE WHEN r.kind = 'refund' THEN 'refund' ELSE 'refund_reversal' END as kind, p.public_id as payment_id, p.currency, CASE WHEN r.kind = 'refund' THEN -r.amount ELSE r.amount END as amount, 0 as fee_amount, date(r.created_at) as effective_on, substr(r.created_at, 1, 19) as updated_at, 1 as lock_version, original.public_id as reverses_id, 0 as unattributed_refund_amount");
        $movements = DB::table('processor_movements as m')
            ->join('client_invoice_payments as p', function ($join) use ($workspace): void {
                $join->on('p.id', '=', 'm.client_invoice_payment_id')->where('p.workspace_id', $workspace->id);
            })
            ->where('m.workspace_id', $workspace->id)
            ->selectRaw('m.id * 4 + 2 as id, m.public_id as source_id, m.kind, p.public_id as payment_id, m.currency, m.principal_minor as amount, m.fee_minor as fee_amount, m.occurred_on as effective_on, substr(m.updated_at, 1, 19) as updated_at, m.lock_version, NULL as reverses_id, 0 as unattributed_refund_amount');
        $union = $payments->unionAll($refunds)->unionAll($movements);

        return AccountingCashRow::query()->fromSub($union, 'accounting_cash')
            ->when($query->from !== null, fn (Builder $rows) => $rows->where('effective_on', '>=', $query->from))
            ->when($query->to !== null, fn (Builder $rows) => $rows->where('effective_on', '<=', $query->to));
    }

    /** @return array<string, mixed> */
    public function present(AccountingCashRow $row): array
    {
        return ['id' => $row->kind.':'.$row->source_id, 'source_id' => $row->source_id,
            'kind' => $row->kind, 'payment_id' => $row->payment_id, 'reverses_id' => $row->reverses_id,
            'currency' => $row->currency, 'effective_on' => $row->effective_on,
            'amount' => (int) $row->amount, 'fee_amount' => (int) $row->fee_amount,
            'net_amount' => (int) $row->amount - (int) $row->fee_amount,
            'unattributed_refund_amount' => (int) $row->unattributed_refund_amount,
            'version' => AgentApiVersion::for($row),
            'updated_at' => $row->updated_at?->toIso8601String()];
    }
}
