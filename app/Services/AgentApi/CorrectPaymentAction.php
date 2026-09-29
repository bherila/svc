<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Support\Facades\Validator;

/**
 * Corrects a received payment's descriptive fields; never moves money.
 *
 * The agent door to {@see InvoiceLifecycleService::correctPayment()}, gated
 * exactly as `payments.record` is - both write cutovers, the payments:record
 * scope (on the route and the tool) and a workspace owner/admin, each
 * rechecked before a receipt is replayed. The body is a closed shape: an agent
 * may name `method`, `reference` and `received_on`, and must give the version
 * it read and a reason. `notes` is deliberately not offered here: `payments.list`
 * never returns it, because it is the operator's private note, and a field an
 * agent cannot read back is one it cannot check its own write against. The
 * operator console command and the service both accept it.
 */
final class CorrectPaymentAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly InvoiceLifecycleService $invoices,
        private readonly AgentAccess $access,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function run(User $user, Workspace $workspace, string $clientId, string $key, string $paymentId, array $payload): array
    {
        abort_unless(trim($key) !== '' && strlen($key) <= 255, 422, 'An idempotency key is required.');

        return $this->mutations->run($user, $workspace, $clientId, 'payments.correct', $key, ['payment_id' => $paymentId, 'body' => $payload],
            function () use ($user, $workspace, $paymentId, $payload): array {
                $this->authorize($user, $workspace);
                $data = Validator::make(['body' => $payload], [
                    'body' => ['required', 'array:expected_version,reason,method,reference,received_on'],
                    'body.expected_version' => ['required', 'string', 'size:64'],
                    'body.reason' => ['required', 'string', 'max:500'],
                    'body.method' => ['sometimes', 'required', 'string', 'max:40'],
                    'body.reference' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'body.received_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
                ])->validate()['body'];
                $payment = ClientInvoicePayment::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('public_id', $paymentId)
                    ->firstOrFail();
                $changes = array_intersect_key($data, array_flip(['method', 'reference', 'received_on']));
                $corrected = $this->invoices->correctPayment($payment, $changes, (string) $data['reason'], (string) $data['expected_version'], $workspace);

                return [$corrected->public_id];
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
