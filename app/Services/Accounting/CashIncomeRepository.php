<?php

namespace App\Services\Accounting;

use App\Models\AccountingCashRow;
use App\Models\ClientInvoicePayment;
use App\Models\ClientInvoicePaymentRefund;
use App\Models\ProcessorMovement;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Accounting\AccountingQuery;
use App\Support\Accounting\CashAmounts;
use App\Support\Accounting\CashIncomeClassifier;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\PaymentRefundKind;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\ProcessorMovementKind;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A bounded union of existing finance facts; no processor calls or duplicate stored ledger.
 *
 * The union selects only the keys a change feed needs to order, filter and
 * page (a tagged id, the public id, the effective date and the change stamp).
 * The page's facts are then loaded once, by primary key and workspace, in a
 * fixed number of queries ({@see self::hydrate()}) and classified by the
 * database-free {@see CashIncomeClassifier}.
 */
final readonly class CashIncomeRepository
{
    /** Row ids are tagged by source: payment * 4, refund * 4 + 1, processor movement * 4 + 2. */
    private const int SOURCES = 4;

    private const int REFUND_SOURCE = 1;

    private const int MOVEMENT_SOURCE = 2;

    private const int CHUNK = 100;

    public function __construct(private FinanceReconciliationOperations $finance) {}

    /** @return Builder<AccountingCashRow> */
    public function query(FinanceCaller $caller, Workspace $workspace, AccountingQuery $query): Builder
    {
        $payments = $this->finance->accountingPayments($caller, $workspace)
            ->select([])
            ->selectRaw('id * 4 as id, public_id as source_id, received_on as effective_on, substr(updated_at, 1, 19) as updated_at, lock_version')
            ->toBase();
        $refunds = DB::table('client_invoice_payment_refunds as r')
            ->join('client_invoice_payments as p', function ($join) use ($workspace): void {
                $join->on('p.id', '=', 'r.client_invoice_payment_id')->where('p.workspace_id', $workspace->id);
            })
            ->where('r.workspace_id', $workspace->id)
            ->selectRaw('r.id * 4 + 1 as id, r.public_id as source_id, date(r.created_at) as effective_on, substr(r.created_at, 1, 19) as updated_at, 1 as lock_version');
        $movements = DB::table('processor_movements as m')
            ->join('client_invoice_payments as p', function ($join) use ($workspace): void {
                $join->on('p.id', '=', 'm.client_invoice_payment_id')->where('p.workspace_id', $workspace->id);
            })
            ->where('m.workspace_id', $workspace->id)
            ->selectRaw('m.id * 4 + 2 as id, m.public_id as source_id, m.occurred_on as effective_on, substr(m.updated_at, 1, 19) as updated_at, m.lock_version');
        $union = $payments->unionAll($refunds)->unionAll($movements);

        return AccountingCashRow::query()->fromSub($union, 'accounting_cash')
            ->when($query->from !== null, fn (Builder $rows) => $rows->where('effective_on', '>=', $query->from))
            ->when($query->to !== null, fn (Builder $rows) => $rows->where('effective_on', '<=', $query->to));
    }

    /**
     * Rows of a bounded range in id order, loaded and classified in chunks of
     * {@see self::CHUNK}: the queries grow with chunks, never with rows.
     *
     * @param  Builder<AccountingCashRow>  $rows
     * @return Generator<int, AccountingCashRow>
     */
    public function hydrated(Workspace $workspace, Builder $rows): Generator
    {
        foreach ($rows->lazyById(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            $page = new Collection($chunk->all());
            $this->hydrate($workspace, $page);
            yield from $page;
        }
    }

    /**
     * Loads the facts behind a page of key rows and classifies them. Every
     * query is scoped to the workspace and keyed by the page's primary keys,
     * so at most six queries run however many rows the page holds.
     *
     * @param  Collection<int, AccountingCashRow>  $rows
     */
    public function hydrate(Workspace $workspace, Collection $rows): void
    {
        $keys = [self::REFUND_SOURCE => [], self::MOVEMENT_SOURCE => [], 0 => []];
        foreach ($rows as $row) {
            $keys[$row->id % self::SOURCES][] = intdiv($row->id, self::SOURCES);
        }
        $refunds = $keys[self::REFUND_SOURCE] === [] ? new Collection : ClientInvoicePaymentRefund::query()
            ->where('workspace_id', $workspace->id)->whereIn('id', $keys[self::REFUND_SOURCE])
            ->get(['id', 'public_id', 'client_invoice_payment_id', 'kind', 'amount', 'reverses_refund_id'])->keyBy('id');
        $movements = $keys[self::MOVEMENT_SOURCE] === [] ? new Collection : ProcessorMovement::query()
            ->where('workspace_id', $workspace->id)->whereIn('id', $keys[self::MOVEMENT_SOURCE])
            ->get(['id', 'client_invoice_payment_id', 'kind', 'currency', 'principal_minor', 'fee_minor'])->keyBy('id');
        $originals = $refunds->pluck('reverses_refund_id')->filter()->unique()->values()->all();
        $reversed = $originals === [] ? new Collection : ClientInvoicePaymentRefund::query()
            ->where('workspace_id', $workspace->id)->whereIn('id', $originals)->get(['id', 'public_id'])->keyBy('id');
        $paymentIds = array_values(array_unique([...$keys[0], ...$refunds->pluck('client_invoice_payment_id')->all(), ...$movements->pluck('client_invoice_payment_id')->all()]));
        $payments = $paymentIds === [] ? new Collection : ClientInvoicePayment::query()
            ->where('workspace_id', $workspace->id)->whereIn('id', $paymentIds)
            ->get(['id', 'public_id', 'status', 'amount', 'refunded_amount', 'provider_fee_amount', 'currency'])->keyBy('id');
        [$ledger, $processor] = $keys[0] === [] ? [[], []] : $this->histories($workspace, $keys[0]);

        foreach ($rows as $row) {
            $pk = intdiv($row->id, self::SOURCES);
            $source = $row->id % self::SOURCES;
            if ($source === self::REFUND_SOURCE) {
                $refund = $refunds->get($pk) ?? throw new LogicException('A cash row lost its refund.');
                $payment = $payments->get($refund->client_invoice_payment_id) ?? throw new LogicException('A cash row lost its payment.');
                $amounts = CashIncomeClassifier::refund($refund->kind, $refund->amount);
                $this->apply($row, $amounts, $payment->public_id, $payment->currency, $reversed->get($refund->reverses_refund_id)?->public_id);
            } elseif ($source === self::MOVEMENT_SOURCE) {
                $movement = $movements->get($pk) ?? throw new LogicException('A cash row lost its movement.');
                $payment = $payments->get($movement->client_invoice_payment_id) ?? throw new LogicException('A cash row lost its payment.');
                $amounts = CashIncomeClassifier::movement($movement->kind, $movement->principal_minor, $movement->fee_minor);
                $this->apply($row, $amounts, $payment->public_id, $movement->currency, null);
            } else {
                $payment = $payments->get($pk) ?? throw new LogicException('A cash row lost its payment.');
                $amounts = CashIncomeClassifier::payment(InvoicePaymentStatus::tryFrom((string) $payment->status), (int) $payment->amount,
                    $payment->provider_fee_amount === null ? null : (int) $payment->provider_fee_amount, (int) $payment->refunded_amount,
                    $ledger[$pk] ?? [], $processor[$pk] ?? []);
                $this->apply($row, $amounts, $payment->public_id, $payment->currency, null);
            }
        }
    }

    /** @return array<string, mixed> */
    public function present(AccountingCashRow $row): array
    {
        return ['id' => $row->kind.':'.$row->source_id, 'source_id' => $row->source_id,
            'kind' => $row->kind, 'payment_id' => $row->payment_id, 'reverses_id' => $row->reverses_id,
            'currency' => $row->currency, 'effective_on' => $row->effective_on,
            'amount' => (int) $row->amount, 'fee_amount' => (int) $row->fee_amount,
            'net_amount' => (int) $row->amount - (int) $row->fee_amount,
            'refunded_amount' => (int) $row->refunded_amount,
            'version' => AgentApiVersion::for($row),
            'updated_at' => $row->updated_at?->toIso8601String()];
    }

    /**
     * The refund history and processor movements of the page's payments, grouped by payment.
     *
     * @param  list<int>  $paymentIds
     * @return array{0: array<int, list<array{kind: PaymentRefundKind, amount: int}>>, 1: array<int, list<array{kind: ProcessorMovementKind, principal: int}>>}
     */
    private function histories(Workspace $workspace, array $paymentIds): array
    {
        $ledger = [];
        foreach (ClientInvoicePaymentRefund::query()->where('workspace_id', $workspace->id)->whereIn('client_invoice_payment_id', $paymentIds)
            ->get(['client_invoice_payment_id', 'kind', 'amount']) as $refund) {
            $ledger[$refund->client_invoice_payment_id][] = ['kind' => $refund->kind, 'amount' => $refund->amount];
        }
        $processor = [];
        foreach (ProcessorMovement::query()->where('workspace_id', $workspace->id)->whereIn('client_invoice_payment_id', $paymentIds)
            ->get(['client_invoice_payment_id', 'kind', 'principal_minor']) as $movement) {
            $processor[$movement->client_invoice_payment_id][] = ['kind' => $movement->kind, 'principal' => $movement->principal_minor];
        }

        return [$ledger, $processor];
    }

    private function apply(AccountingCashRow $row, CashAmounts $amounts, string $paymentId, string $currency, ?string $reversesId): void
    {
        $row->forceFill(['kind' => $amounts->kind, 'payment_id' => $paymentId, 'reverses_id' => $reversesId, 'currency' => $currency,
            'amount' => $amounts->amount, 'fee_amount' => $amounts->feeAmount, 'refunded_amount' => $amounts->refundedAmount,
            'unattributed_refund_amount' => $amounts->unattributedRefundAmount]);
    }
}
