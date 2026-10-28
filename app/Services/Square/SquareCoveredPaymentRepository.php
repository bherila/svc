<?php

namespace App\Services\Square;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\SquareInvoiceCoverage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Collection;

/** Tenant-scoped persistence boundaries for covered-payment review and recording. */
final class SquareCoveredPaymentRepository
{
    public function invoice(Workspace $workspace, string $id, bool $lock = false): ClientInvoice
    {
        $query = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->with('workspace')
            ->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id));
        if ($lock) {
            $query->tap(Locks::forUpdate());
        }

        return $query->firstOrFail();
    }

    public function coverage(ClientInvoice $invoice, string $squareInvoiceId, bool $current = false): SquareInvoiceCoverage
    {
        $query = SquareInvoiceCoverage::query()->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)->where('active_square_invoice_slot', $squareInvoiceId);
        if ($current) {
            $query->tap(Locks::forShare());
        }

        return $query->firstOrFail();
    }

    /** @param list<string> $ids
     * @return Collection<array-key,ClientInvoicePayment> */
    public function recorded(Workspace $workspace, array $ids): Collection
    {
        return ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('provider', 'square')
            ->whereIn('provider_payment_identifier', $ids)->get()->keyBy('provider_payment_identifier');
    }

    public function assertCurrentManager(User $actor, Workspace $workspace): void
    {
        $role = WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->tap(Locks::forShare())->value('role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Current workspace manager authority is required.');
    }

    public function payment(Workspace $workspace, string $id): ClientInvoicePayment
    {
        return ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->where('provider', 'square')->firstOrFail();
    }
}
