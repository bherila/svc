<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Support\AgentApi\AgentApiCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;

final class AgentPaymentReadService
{
    public function __construct(private readonly AgentAccess $access, private readonly PortalInvoiceQuery $portalInvoices) {}

    /** @return array<string, mixed> */
    public function listing(User|AgentPrincipal $user, Workspace $workspace, ?string $invoiceId, ?string $companyId, int $limit = 25, ?string $cursor = null): array
    {
        $this->requireWorkspace($user, $workspace);
        Validator::make(['invoice_id' => $invoiceId, 'company_id' => $companyId, 'limit' => $limit, 'cursor' => $cursor], [
            'invoice_id' => ['nullable', 'required_without:company_id', 'uuid'],
            'company_id' => ['nullable', 'required_without:invoice_id', 'uuid'],
            'limit' => ['required', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ])->validate();
        $queryKey = 'payments:'.json_encode([$invoiceId, $companyId], JSON_THROW_ON_ERROR);
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $queryKey);
        $manager = $this->access->isWorkspaceManager($user, $workspace);
        // At least one explicit filter is required, so resolve at most one
        // company; never iterate every company a portal identity can access.
        $companies = ClientCompany::query()->where('workspace_id', $workspace->id);
        if ($companyId !== null) {
            $companies->where('public_id', $companyId);
        }
        if ($invoiceId !== null) {
            $companies->whereIn('id', ClientInvoice::query()->where('workspace_id', $workspace->id)
                ->where('public_id', $invoiceId)->select('client_company_id'));
        }
        $company = $companies->first();
        if ($company === null) {
            return ['data' => [], 'next_cursor' => null];
        }
        $company->setRelation('workspace', $workspace);
        $invoices = $manager
            ? ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
            : $this->portalInvoices->visibleTo($company, $user instanceof User ? $user : User::query()->findOrFail($user->id));
        if ($invoiceId !== null) {
            $invoices->where('public_id', $invoiceId);
        }
        $query = $this->query($workspace)->whereIn('client_invoice_id', $invoices->select('client_invoices.id'));
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $payments = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $payments->count() > $limit;
        if ($hasMore) {
            $payments->pop();
        }
        $last = $payments->last();
        $manager = $this->access->isWorkspaceManager($user, $workspace);

        return [
            'data' => $payments->map(fn (ClientInvoicePayment $payment): array => $this->present($payment, $manager))->values()->all(),
            'next_cursor' => $hasMore && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $queryKey) : null,
        ];
    }

    /** Conceal inaccessible selectors before validating any query-specific input. */
    public function requireWorkspace(User|AgentPrincipal $user, Workspace $workspace): void
    {
        if (! $this->access->canViewWorkspace($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(Workspace::class);
        }
    }

    /** @param list<string> $ids
     * @return array<string, mixed> */
    public function result(User|AgentPrincipal $user, Workspace $workspace, array $ids): array
    {
        // Mutation readback is manager-only, just like the action itself.
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
        $payments = $this->query($workspace)->whereIn('public_id', $ids)->get();
        abort_unless($payments->count() === count($ids), 404);
        $manager = $this->access->isWorkspaceManager($user, $workspace);

        return ['data' => $payments->map(fn (ClientInvoicePayment $payment): array => $this->present($payment, $manager))->values()->all()];
    }

    /** @return Builder<ClientInvoicePayment> */
    private function query(Workspace $workspace): Builder
    {
        return ClientInvoicePayment::query()->select(['id', 'public_id', 'workspace_id', 'client_invoice_id', 'amount', 'refunded_amount', 'currency', 'received_on', 'method', 'reference', 'status'])->where('workspace_id', $workspace->id)
            ->whereHas('invoice', fn (Builder $invoices) => $invoices->where('workspace_id', $workspace->id))
            ->with(['invoice' => fn ($invoices) => $invoices->where('workspace_id', $workspace->id)
                ->select(['id', 'workspace_id', 'public_id'])]);
    }

    /** The explicit allow-list excludes processor, reconciliation and private note fields.
     * @return array<string, mixed> */
    private function present(ClientInvoicePayment $payment, bool $manager): array
    {
        $invoice = $payment->getRelation('invoice');
        abort_unless($invoice instanceof ClientInvoice, 404);

        return [
            'id' => $payment->public_id,
            'invoice_id' => $invoice->public_id,
            'amount' => $payment->amount,
            'refunded_amount' => $payment->refunded_amount,
            'currency' => $payment->currency,
            'received_on' => $payment->received_on?->toDateString(),
            'method' => $payment->method,
            'reference' => $manager ? $payment->reference : null,
            'status' => $payment->status,
        ];
    }
}
