<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\InvoiceAdministratorNotificationService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use DomainException;
use Illuminate\Support\Facades\Validator;

/**
 * The tenant-scoped, idempotent invoice-issue workflow shared by REST and MCP.
 *
 * Issuing never sends the client anything itself. When the client company has
 * automatic invoice email enabled, {@see InvoiceLifecycleService::issue()}
 * schedules that delivery for the scheduled dispatcher to send after the
 * company's configured delay, exactly as a browser issue does.
 *
 * `payment` records money already collected elsewhere - a card autopay, a
 * wire that arrived before the invoice went out - in the same transaction and
 * under the same receipt, so the invoice is either issued and paid or not
 * touched at all. {@see InvoiceLifecycleService::applyPayment()} cancels the
 * automatic delivery that `issue()` just scheduled, and because both happen in
 * one transaction the dispatcher can never observe a sendable, unpaid invoice
 * for money already received. The payment carries every gate `payments.record`
 * does - both write cutovers, the payments:record scope and a workspace
 * owner/admin - each rechecked before a receipt is replayed. It never
 * initiates a charge and refuses overpayment.
 */
final class IssueInvoiceAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly InvoiceLifecycleService $invoices,
        private readonly AgentAccess $access,
        private readonly InvoiceAdministratorNotificationService $administratorNotifications,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @return list<string> the invoice public ID, then the payment's when one was recorded
     */
    public function run(User $user, Workspace $workspace, string $clientId, string $key, string $invoiceId, array $body, bool $allowsPaymentScope): array
    {
        // Both transports mean no reference when it is omitted or null, as
        // payments.record does. Canonicalized before hashing so a retry may
        // switch REST/MCP safely. A body without a payment is left exactly as
        // it was, so every receipt written before this option existed replays.
        if (isset($body['payment']) && is_array($body['payment'])) {
            $body['payment'] += ['reference' => null];
        }
        $paying = array_key_exists('payment', $body);

        return $this->mutations->run(
            $user,
            $workspace,
            $clientId,
            'invoices.issue',
            $key,
            ['invoice_id' => $invoiceId, 'body' => $body],
            function () use ($user, $workspace, $clientId, $key, $invoiceId, $body, $paying, $allowsPaymentScope): array {
                $this->authorizeIssue($user, $workspace);
                if ($paying) {
                    $this->authorizePayment($allowsPaymentScope);
                }
                $data = Validator::make($body, [
                    'expected_version' => ['required', 'string', 'size:64'],
                    'confirm' => ['accepted'],
                    'payment' => ['sometimes', 'array:amount,currency,received_on,method,reference'],
                    'payment.amount' => ['required_with:payment', 'integer', 'min:1'],
                    'payment.currency' => ['required_with:payment', 'string', 'regex:/^[A-Z]{3}$/'],
                    'payment.received_on' => ['required_with:payment', 'date_format:Y-m-d'],
                    'payment.method' => ['required_with:payment', 'string', 'max:64'],
                    'payment.reference' => ['nullable', 'string', 'max:255'],
                ])->validate();
                // Both checks are asked of the row issue() locks for the
                // transition, not of this read: an invoice issued by another
                // request in between would otherwise pass them here and then be
                // returned by issue() as an idempotent no-op.
                $issued = $this->invoices->issue(
                    $this->invoice($workspace, $invoiceId),
                    $workspace,
                    function (ClientInvoice $locked) use ($data, $paying): void {
                        abort_unless(AgentApiVersion::matches($locked, $data['expected_version']), 409, 'The invoice changed since it was read.');
                        if ($paying && $locked->status !== 'draft') {
                            // Issuing an already-charged invoice is a no-op, which
                            // would turn this into a plain payment under the issue
                            // tool's name. That is payments.record's job.
                            throw new DomainException('Only a draft invoice can be issued with a payment. Record a payment on an issued invoice with payments.record.');
                        }
                    },
                );
                if (! $paying) {
                    return [$issued->public_id];
                }
                $payment = $data['payment'];
                // The domain key is workspace-wide. Namespace it by caller, client
                // and operation so it can collide neither with a CLI/import key
                // nor with a payments.record call that happens to reuse this key.
                $payment['idempotency_key'] = 'agent-payment:'.hash('sha256', json_encode([$user->id, $clientId, 'invoices.issue', $key], JSON_THROW_ON_ERROR));
                $payment['status'] = 'succeeded';
                $recorded = $this->invoices->applyPayment($issued, $payment, $workspace);
                // issue() snapshotted the administrator's copy while the invoice
                // was still unpaid, a state this transaction never commits.
                $this->administratorNotifications->resnapshotIssued($this->invoice($workspace, $issued->public_id));

                return [$issued->public_id, $recorded->public_id];
            },
            function (array $ids) use ($user, $workspace, $paying, $allowsPaymentScope): void {
                $this->authorizeIssue($user, $workspace);
                if ($paying) {
                    $this->authorizePayment($allowsPaymentScope);
                }
                $invoice = $this->invoice($workspace, $ids[0] ?? '');
                if ($paying) {
                    abort_unless(count($ids) === 2 && ClientInvoicePayment::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('client_invoice_id', $invoice->id)
                        ->where('public_id', $ids[1])
                        ->exists(), 404);
                }
            },
            $paying ? 'payments.record' : null,
        );
    }

    private function invoice(Workspace $workspace, string $invoiceId): ClientInvoice
    {
        return ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $invoiceId)
            ->firstOrFail();
    }

    private function authorizeIssue(User $user, Workspace $workspace): void
    {
        // The REST route and the MCP catalog already withhold the operation
        // while either cutover is off; a replay rechecks it here as well.
        abort_unless((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.invoice_writes_enabled'), 404);
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
    }

    private function authorizePayment(bool $allowsPaymentScope): void
    {
        // Folding the payment into issue must not route around any gate that
        // withholds payments.record itself. Its outer cutover and owner/admin
        // requirement are authorizeIssue()'s, which runs first on every path.
        abort_unless((bool) config('agent_api.payment_writes_enabled'), 403, 'Recording payments is not enabled for agents.');
        abort_unless($allowsPaymentScope, 403, 'Recording a payment requires the payments:record scope.');
    }
}
