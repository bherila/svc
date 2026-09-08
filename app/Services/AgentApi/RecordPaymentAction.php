<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Support\Facades\Validator;

/** Records money already received; never initiates a charge or changes a payment status. */
final class RecordPaymentAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly InvoiceLifecycleService $invoices,
        private readonly AgentAccess $access,
    ) {}

    /** @param array<string, mixed> $payload
     * @return list<string> */
    public function run(User $user, Workspace $workspace, string $clientId, string $key, array $payload): array
    {
        abort_unless(trim($key) !== '' && strlen($key) <= 255, 422, 'An idempotency key is required.');

        return $this->mutations->run($user, $workspace, $clientId, 'payments.record', $key, $payload,
            function () use ($user, $workspace, $clientId, $key, $payload): array {
                $this->authorize($user, $workspace);
                $data = Validator::make(['payment' => $payload], [
                    'payment' => ['required', 'array:invoice_id,amount,currency,received_on,method,reference'],
                    'payment.invoice_id' => ['required', 'uuid'],
                    'payment.amount' => ['required', 'integer', 'min:1'],
                    'payment.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
                    'payment.received_on' => ['required', 'date_format:Y-m-d'],
                    'payment.method' => ['required', 'string', 'max:64'],
                    'payment.reference' => ['nullable', 'string', 'max:255'],
                ])->validate()['payment'];
                $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)
                    ->where('public_id', $data['invoice_id'])->firstOrFail();
                // Domain keys are workspace-wide; namespace them by caller and operation
                // so an agent receipt cannot collide with a CLI/import receipt.
                $data['idempotency_key'] = 'agent-payment:'.hash('sha256', json_encode([$user->id, $clientId, $key], JSON_THROW_ON_ERROR));
                $data['status'] = 'succeeded';
                $payment = $this->invoices->applyPayment($invoice, $data, $workspace);

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
        abort_unless((bool) config('agent_api.writes_enabled') && (bool) config('agent_api.payment_writes_enabled'), 404);
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
    }
}
