<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\RecordReceivedPayment;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\Billing\ReceivedPaymentData;
use Illuminate\Support\Facades\Validator;

/**
 * `payments.record`: money already received, recorded by hand.
 *
 * The one implementation behind REST and MCP; both adapters only translate
 * their transport into the payload. The fields are the browser form's
 * ({@see ReceivedPaymentData}): an optional date (today on the workspace's
 * calendar), an optional reference and note, `pending` or `succeeded`, and the
 * finance system's transaction id. It never initiates a charge, and never
 * changes an existing payment - that is `payments.set_status`.
 *
 * Under the invoice lock, after a completed receipt has had the chance to
 * replay, {@see PaymentCollectionGuard} refuses a payment while another is
 * still in flight on the invoice, or when Square collects it.
 */
final class RecordPaymentAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly RecordReceivedPayment $receivedPayments,
        private readonly AgentAccess $access,
        private readonly PaymentCollectionGuard $collection,
    ) {}

    /** @param array<string, mixed> $payload
     * @return list<string> */
    public function run(User $user, Workspace $workspace, string $clientId, string $key, array $payload): array
    {
        abort_unless(trim($key) !== '' && strlen($key) <= 255, 422, 'An idempotency key is required.');

        // Both transports mean no reference when it is omitted or null.
        // Canonicalize before hashing so a retry may switch REST/MCP safely.
        $payload += ['reference' => null];
        // The optional fields #454 added mean the same omitted or null, and
        // are dropped rather than defaulted: a receipt written before they
        // existed hashes exactly as it did, so its retry still replays.
        foreach (['received_on', 'notes', 'status', 'external_finance_transaction_uuid'] as $optional) {
            if (array_key_exists($optional, $payload) && $payload[$optional] === null) {
                unset($payload[$optional]);
            }
        }

        return $this->mutations->run($user, $workspace, $clientId, 'payments.record', $key, $payload,
            function () use ($user, $workspace, $clientId, $key, $payload): array {
                $this->authorize($user, $workspace);
                $data = Validator::make(['payment' => $payload], [
                    'payment' => ['required', 'array:invoice_id,amount,currency,received_on,method,reference,notes,status,external_finance_transaction_uuid,expected_version,confirm'],
                    'payment.invoice_id' => ['required', 'uuid'],
                    'payment.expected_version' => ['required', 'string', 'size:64'],
                    'payment.confirm' => ['required', new ExplicitConfirmation],
                ])->validate()['payment'];
                $facts = ReceivedPaymentData::from($payload, requireDate: false, includeBookkeeping: true, errorPrefix: 'payment');
                $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)
                    ->where('public_id', $data['invoice_id'])->firstOrFail();
                // Domain keys are workspace-wide; namespace them by caller and operation
                // so an agent receipt cannot collide with a CLI/import receipt.
                $payment = $this->receivedPayments->record($workspace, $invoice, $facts, RecordReceivedPayment::agentKey($user->id, $clientId, $key),
                    function (ClientInvoice $locked) use ($data): void {
                        abort_unless(AgentApiVersion::matches($locked, $data['expected_version']), 409, 'The invoice has changed; read it and retry.');
                        $this->collection->assertMayRecord($locked);
                    });

                return [$payment->public_id];
            },
            function (array $ids) use ($user, $workspace): void {
                $this->authorize($user, $workspace);
                abort_unless(ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
                    ->whereIn('public_id', $ids)->count() === count($ids), 404);
            });
    }

    private function authorize(User $user, Workspace $workspace): void
    {
        abort_unless(AgentWriteCutover::payments(), 404);
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
    }
}
