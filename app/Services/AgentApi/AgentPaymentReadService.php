<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

final class AgentPaymentReadService
{
    public function __construct(private readonly AgentAccess $access) {}

    /** @return array<string, mixed> */
    public function listing(User|AgentPrincipal $user, Workspace $workspace, ?string $invoiceId, ?string $companyId, int $limit = 25, ?string $cursor = null): array
    {
        Validator::make(['invoice_id' => $invoiceId, 'company_id' => $companyId, 'limit' => $limit, 'cursor' => $cursor], [
            'invoice_id' => ['nullable', 'required_without:company_id', 'uuid'],
            'company_id' => ['nullable', 'required_without:invoice_id', 'uuid'],
            'limit' => ['required', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ])->validate();
        $queryKey = 'payments:'.json_encode([$invoiceId, $companyId], JSON_THROW_ON_ERROR);
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $queryKey);
        $query = $this->query($user, $workspace);
        $query->whereHas('invoice', function (Builder $invoices) use ($workspace, $invoiceId, $companyId): void {
            $invoices->where('workspace_id', $workspace->id);
            if ($invoiceId !== null) {
                $invoices->where('public_id', $invoiceId);
            }
            if ($companyId !== null) {
                $invoices->whereHas('clientCompany', fn (Builder $companies) => $companies
                    ->where('workspace_id', $workspace->id)->where('public_id', $companyId));
            }
        });
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

    /** @param list<string> $ids
     * @return array<string, mixed> */
    public function result(User|AgentPrincipal $user, Workspace $workspace, array $ids): array
    {
        $payments = $this->query($user, $workspace)->whereIn('public_id', $ids)->get();
        abort_unless($payments->count() === count($ids), 404);
        $manager = $this->access->isWorkspaceManager($user, $workspace);

        return ['data' => $payments->map(fn (ClientInvoicePayment $payment): array => $this->present($payment, $manager))->values()->all()];
    }

    /** @return Builder<ClientInvoicePayment> */
    private function query(User|AgentPrincipal $user, Workspace $workspace): Builder
    {
        abort_unless($this->access->canViewWorkspace($user, $workspace), 403);
        $manager = $this->access->isWorkspaceManager($user, $workspace);
        $companies = $manager ? [] : $this->access->portalCompanyIdsIn($user, $workspace);

        return ClientInvoicePayment::query()->select(['id', 'public_id', 'workspace_id', 'client_invoice_id', 'amount', 'refunded_amount', 'currency', 'received_on', 'method', 'reference', 'status'])->where('workspace_id', $workspace->id)
            ->whereHas('invoice', function (Builder $invoices) use ($workspace, $manager, $companies): void {
                $invoices->where('workspace_id', $workspace->id);
                if (! $manager) {
                    $invoices->where('is_visible_to_client', true)->whereIn('status', ['issued', 'partially_paid', 'paid'])
                        ->whereIn('client_company_id', $companies);
                }
            })
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
