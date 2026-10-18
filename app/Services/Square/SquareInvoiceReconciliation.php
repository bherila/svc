<?php

namespace App\Services\Square;

use App\Models\ClientInvoice;
use App\Models\InvoiceSquareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentAccess;
use DomainException;

/**
 * Reconcile one linked svc invoice with Square now (#458), rather than
 * waiting for the webhook or the fifteen-minute sweep. The single owner of the
 * behaviour behind `invoices.square_reconcile` (REST and MCP) and
 * `svc:square:reconcile-payments --invoice=`.
 *
 * It runs exactly the sweep's refetch ({@see SquarePaymentReconciler::invoice()}):
 * read-only Square calls, then completed payments and refunds recorded through
 * the received-payment service. Repeating it is safe - the Square payment id is
 * the ledger's unique provider key and refunds are cumulative. Nothing is ever
 * written to Square.
 */
final class SquareInvoiceReconciliation
{
    public function __construct(
        private readonly SquarePaymentReconciler $reconciler,
        private readonly SquareLinkRepository $links,
        private readonly AgentAccess $access,
        private readonly AgentMutationExecutor $mutations,
    ) {}

    /**
     * For a person or agent through the API: workspace managers only, under
     * one mutation receipt per idempotency key. The receipt only admits the
     * request - Square is read after it commits, never inside a transaction -
     * so a replayed key reconciles again, harmlessly, and returns the invoice
     * as it now stands.
     */
    public function reconcileFor(User $user, Workspace $workspace, string $invoiceId, string $clientId, string $idempotencyKey): ClientInvoice
    {
        $this->mutations->run($user, $workspace, $clientId, 'invoices.square_reconcile', $idempotencyKey, ['invoice_id' => $invoiceId],
            function () use ($user, $workspace, $invoiceId): array {
                $this->admit($user, $workspace);

                return [$this->linked($workspace, $invoiceId)[0]->public_id];
            },
            fn (array $ids) => $this->admit($user, $workspace),
        );

        return $this->reconcile($workspace, $invoiceId);
    }

    /**
     * For the operator's command, which acts for the workspace Square serves.
     *
     * @throws SquareRequestFailed when Square is not connected to the workspace or cannot be read
     * @throws DomainException when the invoice is not linked, or Square's facts cannot be recorded
     */
    public function reconcile(Workspace $workspace, string $invoiceId): ClientInvoice
    {
        [$invoice, $link] = $this->linked($workspace, $invoiceId);
        $this->reconciler->invoice($workspace, $link->square_invoice_id);

        return $invoice->refresh();
    }

    /** @return array{ClientInvoice, InvoiceSquareLink} */
    private function linked(Workspace $workspace, string $invoiceId): array
    {
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            throw SquareRequestFailed::notConnected();
        }
        $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $invoiceId)->firstOrFail();
        $link = $this->links->activeLink($invoice);
        if ($link === null) {
            throw new DomainException('This invoice is not linked to a Square invoice. Link it with invoices.square_link first.');
        }

        return [$invoice, $link];
    }

    private function admit(User $user, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403, 'Square reconciliation is available to workspace managers only.');
    }
}
