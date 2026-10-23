<?php

namespace App\Services\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Authorization\ProjectAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Where a payment's permanent deep link lands for one signed-in reader.
 *
 * An external finance system keeps the payment's public UUID, never a screen
 * address: a stable `/finance-links/payments/{uuid}` keeps working when the
 * screens move. This resolves that UUID to the invoice screen the payment is
 * shown on, anchored at its row.
 *
 * Null means "not found", and it means the same thing whichever check failed:
 * no such payment, a payment in a workspace this reader is not a member of, a
 * client they do not reach, or an invoice they may not see. The caller answers
 * every one of them with the same 404, so the link reveals nothing about
 * whether a record it does not open exists.
 *
 * The payment is read only inside the reader's own workspaces: membership is a
 * SQL predicate on the lookup, so a payment of another tenant is never loaded
 * to find out whose it is.
 */
final readonly class FinancePaymentLinkResolver
{
    public function __construct(
        private ProjectAccess $projects,
        private BillingRecordAccess $billing,
    ) {}

    /** The invoice screen to open, as a relative URL with the payment's anchor, or null. */
    public function destination(User $reader, string $paymentPublicId): ?string
    {
        if (! Str::isUuid($paymentPublicId)) {
            return null;
        }

        $payment = ClientInvoicePayment::query()
            ->where('public_id', strtolower($paymentPublicId))
            ->whereIn(
                'workspace_id',
                WorkspaceMembership::query()->select('workspace_id')->where('user_id', $reader->id),
            )
            ->first(['id', 'public_id', 'workspace_id', 'client_invoice_id']);

        if ($payment === null) {
            return null;
        }

        $workspace = Workspace::query()->whereKey($payment->workspace_id)->first();
        if ($workspace === null || ! Gate::forUser($reader)->allows('view', $workspace)) {
            return null;
        }

        $invoice = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($payment->client_invoice_id)
            ->first();
        if ($invoice === null) {
            return null;
        }

        $company = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($invoice->client_company_id)
            ->first();
        if ($company === null) {
            return null;
        }

        // The same two checks the invoice screen makes, made here first: a
        // redirect that the screen then refused would tell this reader the
        // payment exists.
        $reachable = $this->projects->reachableCompanyIds($reader, $workspace);
        if ($reachable !== null && ! in_array((int) $company->id, $reachable, true)) {
            return null;
        }
        if (! $this->billing->canViewInvoice($reader, $workspace, $invoice)) {
            return null;
        }

        return route('clients.invoice', [$workspace, $company, $invoice], absolute: false)
            .'#payment-'.$payment->public_id;
    }
}
