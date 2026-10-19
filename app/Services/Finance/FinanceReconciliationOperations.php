<?php

namespace App\Services\Finance;

use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\FinancePaymentQuery;
use App\Support\Finance\ReconciliationAllocation;
use App\Support\Finance\ReconciliationTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The finance reconciliation operations (#457), one implementation behind
 * every door: the REST routes (`finance.invoice_payments.list`,
 * `finance.reconciliations.upsert`, `finance.reconciliations.deactivate`),
 * the MCP tools of the same names, which reach those routes through the
 * internal transport, and the `svc:finance:*` console commands.
 *
 * Who may do what is decided here, from the caller's door
 * ({@see FinanceCaller}): an owner/admin always; any workspace member may
 * list only through the Sanctum finance token, the contract that integration
 * has always had. Every read and write is scoped to the workspace. A write is
 * named by its path - payment, external system, transaction - so repeating it
 * is the same write: it takes no idempotency key and is audited without a
 * receipt ({@see AgentMutationExecutor::runIdempotentByPath()}). Nothing here
 * moves money; an allocation only records which external transaction covers
 * how much of a payment.
 */
final class FinanceReconciliationOperations
{
    public const string LIST = 'finance.invoice_payments.list';

    public const string UPSERT = 'finance.reconciliations.upsert';

    public const string DEACTIVATE = 'finance.reconciliations.deactivate';

    public function __construct(
        private readonly PaymentReconciliationService $reconciliations,
        private readonly AgentMutationExecutor $mutations,
    ) {}

    /**
     * Received payments with their net, reconciled and unreconciled amounts and
     * every allocation, active first, newest received first.
     *
     * @return LengthAwarePaginator<int, ClientInvoicePayment>
     *
     * @throws AuthorizationException
     */
    public function list(FinanceCaller $caller, Workspace $workspace, FinancePaymentQuery $query): LengthAwarePaginator
    {
        Gate::forUser($caller->user)->authorize($caller->door->membersMayRead() ? 'view' : 'manage', $workspace);
        $workspaceId = $workspace->id;

        return ClientInvoicePayment::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', $query->status->value)
            ->when($query->invoicePublicId !== null, static fn (Builder $payments) => $payments->whereHas(
                'invoice',
                static fn (Builder $invoices) => $invoices->where('workspace_id', $workspaceId)->where('public_id', $query->invoicePublicId),
            ))
            ->when($query->receivedFrom !== null, static fn (Builder $payments) => $payments->whereDate('received_on', '>=', (string) $query->receivedFrom))
            ->when($query->receivedTo !== null, static fn (Builder $payments) => $payments->whereDate('received_on', '<=', (string) $query->receivedTo))
            ->with([
                'invoice' => static fn ($invoices) => $invoices->where('workspace_id', $workspaceId),
                'invoice.clientCompany' => static fn ($companies) => $companies->where('workspace_id', $workspaceId),
                'reconciliations' => static fn ($allocations) => $allocations->where('workspace_id', $workspaceId)->orderByDesc('is_active')->latest('id'),
            ])
            ->withSum([
                'reconciliations as active_reconciled_amount' => static fn (Builder $allocations) => $allocations->where('workspace_id', $workspaceId)->where('is_active', true),
            ], 'allocated_amount')
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate($query->perPage, ['*'], 'page', $query->page)
            ->withPath(self::listUrl($workspace))
            ->withQueryString();
    }

    /**
     * The REST route's address on this installation, which the page links
     * name whichever door asked: an MCP call reaches the route through the
     * internal transport, whose request has no public host of its own.
     */
    public static function listUrl(Workspace $workspace): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/workspaces/'.$workspace->public_id.'/invoice-payments';
    }

    /**
     * Create or update the allocation the target names. Only a succeeded
     * payment can be reconciled, in its own currency, and its active
     * allocations never exceed what it is worth net of refunds.
     */
    public function upsert(FinanceCaller $caller, Workspace $workspace, ReconciliationTarget $target, ReconciliationAllocation $allocation): PaymentReconciliation
    {
        $written = null;
        $this->mutations->runIdempotentByPath($caller->user, $workspace, $caller->credential, self::UPSERT,
            function () use ($caller, $workspace, $target, $allocation, &$written): array {
                $payment = $this->payment($caller, $workspace, $target);
                $written = $this->reconciliations->upsert($workspace, $payment, $caller->user, $allocation->attributesFor($target));

                return [(string) $written->public_id];
            });

        /** @var PaymentReconciliation $written */
        return $written;
    }

    /** Stop the allocation counting; it is kept, with its history, and upserting it again reactivates it. */
    public function deactivate(FinanceCaller $caller, Workspace $workspace, ReconciliationTarget $target): PaymentReconciliation
    {
        $written = null;
        $this->mutations->runIdempotentByPath($caller->user, $workspace, $caller->credential, self::DEACTIVATE,
            function () use ($caller, $workspace, $target, &$written): array {
                $payment = $this->payment($caller, $workspace, $target);
                $written = $this->reconciliations->deactivate($workspace, $payment, $target->externalSystem, $target->externalTransactionUuid);

                return [(string) $written->public_id];
            });

        /** @var PaymentReconciliation $written */
        return $written;
    }

    /**
     * The workspace's payment, for an owner/admin: a writer is authorized
     * before anything is looked up, and another workspace's payment is not
     * found.
     *
     * @throws AuthorizationException
     */
    private function payment(FinanceCaller $caller, Workspace $workspace, ReconciliationTarget $target): ClientInvoicePayment
    {
        Gate::forUser($caller->user)->authorize('manage', $workspace);

        return ClientInvoicePayment::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $target->paymentPublicId)
            ->firstOrFail();
    }
}
