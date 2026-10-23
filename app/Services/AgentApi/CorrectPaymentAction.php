<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\Billing\PaymentCorrection;
use Illuminate\Support\Facades\Validator;

/**
 * Corrects a received payment's descriptive fields; never moves money.
 *
 * The agent door to {@see InvoiceLifecycleService::correctPayment()}, gated
 * exactly as `payments.record` is - both write cutovers, the payments:record
 * scope (on the route and the tool) and a workspace owner/admin, each
 * rechecked before a receipt is replayed. The body is a closed shape: a caller
 * may name `method`, `reference`, `notes` and `received_on` - the same fields
 * the console command and the service accept - and must give the version it
 * read and a reason. `payments.list` returns the note to the workspace
 * managers who alone may write it, so a correction can be checked (#454).
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
                    'body' => ['required', 'array:expected_version,confirm,reason,method,reference,notes,received_on'],
                    'body.expected_version' => ['required', 'string', 'size:64'],
                    'body.confirm' => ['required', new ExplicitConfirmation],
                    'body.reason' => ['required', 'string', 'max:'.PaymentCorrection::REASON_MAX_LENGTH],
                    'body.method' => ['sometimes', 'required', 'string', 'max:'.ClientInvoicePayment::METHOD_MAX_LENGTH],
                    'body.reference' => ['sometimes', 'nullable', 'string', 'max:'.PaymentCorrection::REFERENCE_MAX_LENGTH],
                    'body.notes' => ['sometimes', 'nullable', 'string', 'max:'.PaymentCorrection::NOTES_MAX_LENGTH],
                    'body.received_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
                ])->validate()['body'];
                $payment = ClientInvoicePayment::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('public_id', $paymentId)
                    ->firstOrFail();
                $changes = array_intersect_key($data, array_flip(PaymentCorrection::FIELDS));
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
        abort_unless(AgentWriteCutover::payments(), 404);
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
    }
}
