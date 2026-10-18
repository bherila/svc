<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\Billing\ManualPaymentChange;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * `payments.set_status` and `payments.refund`: what a payment recorded by hand
 * is worth, changed with a reason (#455).
 *
 * The one implementation behind REST and MCP; the console commands reach the
 * same {@see ManualPaymentChange} and {@see InvoiceLifecycleService::changeManualPayment()}
 * without the agent gates. Gated as the other payment writes are - both write
 * cutovers, the payments:record scope (on the route and the tool), a workspace
 * owner/admin - each rechecked before a receipt is replayed. The body is closed,
 * names the version the caller read from `payments.list` and a reason, and
 * requires explicit confirmation. Never charges a customer and never sends a
 * refund: a refund recorded here is money already returned outside SVC.
 */
final class ChangeManualPaymentAction
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
    public function setStatus(User $user, Workspace $workspace, string $clientId, string $key, string $paymentId, array $payload): array
    {
        return $this->run('payments.set_status', $user, $workspace, $clientId, $key, $paymentId, $payload, [
            'body.status' => ['required', 'string', Rule::in(ManualPaymentChange::settableValues())],
        ], static fn (array $data): ManualPaymentChange => ManualPaymentChange::status($data['status'], $data['reason']));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function refund(User $user, Workspace $workspace, string $clientId, string $key, string $paymentId, array $payload): array
    {
        return $this->run('payments.refund', $user, $workspace, $clientId, $key, $paymentId, $payload, [
            'body.amount' => ['required', 'integer:strict', 'min:1'],
        ], static fn (array $data): ManualPaymentChange => ManualPaymentChange::refund($data['amount'], $data['reason']));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<mixed>>  $rules  the operation's own field
     * @param  callable(array<string, mixed>): ManualPaymentChange  $change
     * @return list<string>
     */
    private function run(string $operation, User $user, Workspace $workspace, string $clientId, string $key, string $paymentId, array $payload, array $rules, callable $change): array
    {
        abort_unless(trim($key) !== '' && strlen($key) <= 255, 422, 'An idempotency key is required.');
        $field = substr((string) array_key_first($rules), strlen('body.'));

        return $this->mutations->run($user, $workspace, $clientId, $operation, $key, ['payment_id' => $paymentId, 'body' => $payload],
            function () use ($user, $workspace, $paymentId, $payload, $rules, $field, $change): array {
                $this->authorize($user, $workspace);
                $data = Validator::make(['body' => $payload], [
                    'body' => ['required', 'array:expected_version,confirm,reason,'.$field],
                    'body.expected_version' => ['required', 'string', 'size:64'],
                    'body.confirm' => ['required', new ExplicitConfirmation],
                    'body.reason' => ['required', 'string', 'max:'.ManualPaymentChange::REASON_MAX_LENGTH],
                    ...$rules,
                ])->validate()['body'];
                $payment = ClientInvoicePayment::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('public_id', $paymentId)
                    ->firstOrFail();
                $outcome = $this->invoices->changeManualPayment($payment, $change($data), (string) $data['expected_version'], $workspace);

                return [$outcome->payment->public_id];
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
