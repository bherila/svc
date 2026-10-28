<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\RecordReceivedPayment;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Services\Finance\PaymentReconciliationService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\PaymentRecordingPolicy;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Finance\ReconciliationTarget;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
 * An optional `finance_reconciliation` - external system slug, transaction
 * UUID and allocated amount - writes the payment's first finance allocation
 * in the same transaction and under the same receipt, so a deposit is either
 * recorded and reconciled or not touched at all. It needs `finance:reconcile`
 * as `finance.reconciliations.upsert` does, and is audited under that
 * operation too. It does not set the legacy `external_finance_transaction_uuid`.
 *
 * Under the invoice lock, after a completed receipt has had the chance to
 * replay, {@see PaymentCollectionGuard} refuses a payment while another is
 * still in flight on the invoice. Its default agent policy also refuses
 * invoices Square collects. The trusted website adapter explicitly selects
 * operator bookkeeping to preserve recording money already received there;
 * a request body cannot select that policy.
 */
final class RecordPaymentAction
{
    /** What `finance_reconciliation` may carry: the allocation's natural key and its amount. */
    public const array RECONCILIATION_FIELDS = ['external_system_slug', 'external_transaction_uuid', 'allocated_amount'];

    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly RecordReceivedPayment $receivedPayments,
        private readonly AgentAccess $access,
        private readonly PaymentCollectionGuard $collection,
        private readonly PaymentReconciliationService $reconciliations,
    ) {}

    /** @param array<string, mixed> $payload
     * @return list<string> */
    public function run(User $user, Workspace $workspace, string $clientId, string $key, array $payload, bool $allowsReconcileScope = false, PaymentRecordingPolicy $policy = PaymentRecordingPolicy::Agent): array
    {
        abort_unless(trim($key) !== '' && strlen($key) <= 255, 422, 'An idempotency key is required.');

        // Both transports mean no reference when it is omitted or null.
        // Canonicalize before hashing so a retry may switch REST/MCP safely.
        $payload += ['reference' => null];
        // The optional fields #454 added mean the same omitted or null, and
        // are dropped rather than defaulted: a receipt written before they
        // existed hashes exactly as it did, so its retry still replays. The
        // same holds for `finance_reconciliation`.
        foreach (['received_on', 'notes', 'status', 'external_finance_transaction_uuid', 'finance_reconciliation'] as $optional) {
            if (array_key_exists($optional, $payload) && $payload[$optional] === null) {
                unset($payload[$optional]);
            }
        }
        $reconciling = array_key_exists('finance_reconciliation', $payload);

        $ids = $this->mutations->run($user, $workspace, $clientId, 'payments.record', $key, $payload,
            function () use ($user, $workspace, $clientId, $key, $payload, $reconciling, $allowsReconcileScope, $policy): array {
                $this->authorize($user, $workspace, $reconciling, $allowsReconcileScope);
                $data = Validator::make(['payment' => $payload], [
                    'payment' => ['required', 'array:invoice_id,amount,currency,received_on,method,reference,notes,status,external_finance_transaction_uuid,finance_reconciliation,expected_version,confirm'],
                    'payment.invoice_id' => ['required', 'uuid'],
                    'payment.expected_version' => ['required', 'string', 'size:64'],
                    'payment.confirm' => ['required', new ExplicitConfirmation],
                    'payment.finance_reconciliation' => ['sometimes', 'required', 'array:'.implode(',', self::RECONCILIATION_FIELDS)],
                    'payment.finance_reconciliation.external_system_slug' => ['required_with:payment.finance_reconciliation', 'string', 'max:'.ReconciliationTarget::SYSTEM_MAX_LENGTH, 'regex:/^'.ReconciliationTarget::SYSTEM_PATTERN.'$/'],
                    'payment.finance_reconciliation.external_transaction_uuid' => ['required_with:payment.finance_reconciliation', 'uuid'],
                    'payment.finance_reconciliation.allocated_amount' => ['required_with:payment.finance_reconciliation', 'integer', 'min:1'],
                ])->validate()['payment'];
                $facts = ReceivedPaymentData::from($payload, requireDate: false, includeBookkeeping: true, errorPrefix: 'payment');
                /** @var array{external_system_slug: string, external_transaction_uuid: string, allocated_amount: int|string}|null $allocation */
                $allocation = $reconciling ? $data['finance_reconciliation'] : null;
                if ($allocation !== null) {
                    $this->assertAllocatable($facts, (int) $allocation['allocated_amount']);
                }
                $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)
                    ->where('public_id', $data['invoice_id'])->firstOrFail();
                // The agreement's Square marker, read current and held before the
                // invoice lock that record() takes next.
                $agreement = $this->collection->holdAgreement($invoice);
                // Domain keys are workspace-wide; namespace them by caller and operation
                // so an agent receipt cannot collide with a CLI/import receipt.
                $payment = $this->receivedPayments->record($workspace, $invoice, $facts, RecordReceivedPayment::agentKey($user->id, $clientId, $key),
                    function (ClientInvoice $locked) use ($data, $agreement, $policy): void {
                        abort_unless(AgentApiVersion::matches($locked, $data['expected_version']), 409, 'The invoice has changed; read it and retry.');
                        $this->collection->assertMayRecord($locked, $agreement, $policy);
                    });
                if ($allocation !== null) {
                    $reconciliation = $this->reconciliations->upsert($workspace, $payment, $user, [
                        'external_system_slug' => $allocation['external_system_slug'],
                        'external_transaction_uuid' => strtolower($allocation['external_transaction_uuid']),
                        'allocated_amount' => (int) $allocation['allocated_amount'],
                        'currency' => $payment->currency,
                        'reconciled_on' => null,
                        'is_active' => true,
                    ]);

                    // The payment, then the allocation: each audit row names its own.
                    return [$payment->public_id, (string) $reconciliation->public_id];
                }

                return [$payment->public_id];
            },
            function (array $ids) use ($user, $workspace, $reconciling, $allowsReconcileScope): void {
                $this->authorize($user, $workspace, $reconciling, $allowsReconcileScope);
                $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
                    ->where('public_id', $ids[0] ?? '')->first(['id']);
                abort_unless($payment !== null && count($ids) === ($reconciling ? 2 : 1), 404);
                abort_unless(! $reconciling || PaymentReconciliation::query()->where('workspace_id', $workspace->id)
                    ->where('client_invoice_payment_id', $payment->id)->where('public_id', $ids[1])->exists(), 404);
            },
            $reconciling ? FinanceReconciliationOperations::UPSERT : null,
            auditIds: static fn (string $operation, array $ids): array => $operation === FinanceReconciliationOperations::UPSERT
                ? array_slice($ids, 1, 1)
                : array_slice($ids, 0, 1));

        // The payment is the result; the allocation is reached through it.
        return array_slice($ids, 0, 1);
    }

    private function authorize(User $user, Workspace $workspace, bool $reconciling, bool $allowsReconcileScope): void
    {
        abort_unless(AgentWriteCutover::payments(), 404);
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
        // Folding the allocation in must not route around the scope that
        // withholds finance.reconciliations.upsert itself.
        abort_unless(! $reconciling || $allowsReconcileScope, 403, 'Reconciling a payment requires the finance:reconcile scope.');
    }

    /**
     * Refused before anything is written, with the field named: only money
     * that has arrived can be reconciled, and never for more than it is.
     *
     * @throws ValidationException
     */
    private function assertAllocatable(ReceivedPaymentData $facts, int $allocated): void
    {
        if ($facts->status !== InvoicePaymentStatus::Succeeded) {
            throw ValidationException::withMessages([
                'payment.finance_reconciliation' => 'Only a succeeded payment can be reconciled. Record it, settle it with payments.set_status once it clears, then reconcile it with finance.reconciliations.upsert.',
            ]);
        }
        if ($allocated > $facts->amount) {
            throw ValidationException::withMessages([
                'payment.finance_reconciliation.allocated_amount' => 'The allocated amount cannot exceed the payment amount.',
            ]);
        }
    }
}
