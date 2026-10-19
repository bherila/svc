<?php

namespace App\Services\Square;

use App\Models\ClientInvoice;
use App\Models\InvoiceSquareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentAccess;
use App\Support\Concurrency\Locks;
use DomainException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

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
    /** API reconciliations per workspace in {@see WINDOW_SECONDS}: each reads Square up to 102 times. */
    public const int RECONCILES_PER_WINDOW = 20;

    public const int WINDOW_SECONDS = 600;

    public function __construct(
        private readonly SquarePaymentReconciler $reconciler,
        private readonly SquareLinkRepository $links,
        private readonly AgentAccess $access,
        private readonly AgentMutationExecutor $mutations,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * For a person or agent through the API: workspace managers only, within
     * a per-workspace budget of Square reads, under one mutation receipt per
     * idempotency key. Square is read first, outside any transaction; the
     * ledger is written inside the receipt's, so the receipt and its audit
     * record the outcome - a failed read or a refused payment is audited as a
     * failure and leaves no receipt, and a retry tries again. A replayed key
     * returns the invoice as it now stands.
     */
    public function reconcileFor(User $user, Workspace $workspace, string $invoiceId, string $clientId, string $idempotencyKey): ClientInvoice
    {
        $this->admit($user, $workspace);
        [$invoice, $link] = $this->linked($workspace, $invoiceId);
        $key = 'square-reconcile:'.$workspace->getKey();
        if (! $this->limiter->attempt($key, self::RECONCILES_PER_WINDOW, static fn (): bool => true, self::WINDOW_SECONDS)) {
            $seconds = max(1, $this->limiter->availableIn($key));
            abort(429, "Square was reconciled often just now. Retry in {$seconds} seconds.", ['Retry-After' => (string) $seconds]);
        }
        try {
            $refetched = $this->reconciler->refetch($workspace, $link->square_invoice_id);
        } catch (SquareRequestFailed|DomainException $failure) {
            $refetched = $failure;
        }
        $settle = function () use ($user, $workspace, $link, $refetched): void {
            $this->admit($user, $workspace);
            if ($refetched instanceof \Throwable) {
                throw $refetched;
            }
            $this->applyPinned($workspace, $link, $refetched);
        };
        $this->mutations->run($user, $workspace, $clientId, 'invoices.square_reconcile', $idempotencyKey, ['invoice_id' => $invoiceId],
            function () use ($settle, $invoice): array {
                $settle();

                return [$invoice->public_id];
            },
            // A replayed key does not re-run the callback, so this attempt's
            // outcome is applied here too: a Square failure is raised and
            // audited as one, and fresh facts reach the ledger.
            fn (array $ids) => $settle(),
        );

        return $invoice->refresh();
    }

    /**
     * For the operator's command, which acts for the workspace Square serves.
     *
     * @throws SquareRequestFailed when Square is not connected to the workspace or cannot be read
     * @throws ModelNotFoundException when the workspace has no invoice with that id
     * @throws DomainException when the invoice is not linked, or Square's facts cannot be recorded
     */
    public function reconcile(Workspace $workspace, string $invoiceId): ClientInvoice
    {
        [$invoice, $link] = $this->linked($workspace, $invoiceId);
        $this->applyPinned($workspace, $link, $this->reconciler->refetch($workspace, $link->square_invoice_id));

        return $invoice->refresh();
    }

    /**
     * Applies Square's facts only to the link that was asked about: if the
     * Square invoice was re-linked to another invoice meanwhile, nothing is
     * recorded on either, and the caller is told.
     */
    private function applyPinned(Workspace $workspace, InvoiceSquareLink $link, ?SquareInvoiceRefetch $refetched): void
    {
        if ($refetched === null || $refetched->activeLink?->id !== $link->id) {
            throw self::linkChanged();
        }
        DB::transaction(function () use ($workspace, $link, $refetched): void {
            $this->reconciler->apply($workspace, $refetched);
            // Then, under the invoice's lock - the boundary every link change
            // takes - the link must still be the one asked about, recorded
            // payments or not. Taken last, after whatever recording locked,
            // so the billing lock order holds; a change rolls it all back.
            ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($link->client_invoice_id)->tap(Locks::forUpdate())->firstOrFail();
            if ($this->links->activeLinkForSquareInvoice($workspace->id, $link->square_invoice_id)?->id !== $link->id) {
                throw self::linkChanged();
            }
        });
    }

    private static function linkChanged(): DomainException
    {
        return new DomainException('The invoice\'s Square link changed while it was being reconciled. Read the invoice and try again.');
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
