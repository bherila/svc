<?php

namespace App\Services\Square;

use App\Models\AgentPrincipal;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\AgentApi\PaymentCollectionGuard;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\RecordReceivedPayment;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PaymentDateBounds;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Billing\ReceivedPaymentProvider;
use App\Support\Square\SquarePaymentFacts;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Explicitly reviewed allocation of one covered Square payment; never part of automatic sync. */
final class SquareCoveredPaymentService
{
    public const string RECORD = 'invoices.square_covered_payment_record';

    public function __construct(private readonly SquareCoveredPaymentRepository $records,
        private readonly SquareInvoicePaymentReader $reader, private readonly SquarePaymentDisputeReader $disputes,
        private readonly AgentAccess $access, private readonly AgentMutationExecutor $mutations,
        private readonly RecordReceivedPayment $received, private readonly PaymentCollectionGuard $collection,
        private readonly SquareSettlementSync $settlements, private readonly RateLimiter $limiter, private readonly WorkspaceClock $clock) {}

    /** @return array<string,mixed> */
    public function review(User|AgentPrincipal $actor, Workspace $workspace, string $invoiceId, string $squareInvoiceId, bool $allowsRecord): array
    {
        $this->admit($actor, $workspace);
        $observation = $this->observe($workspace, $invoiceId, $squareInvoiceId);
        $invoice = $this->records->invoice($workspace, $invoiceId);
        $coverage = $this->records->coverage($invoice, $squareInvoiceId);
        abort_unless($coverage->public_id === $observation->coverageId, 409, 'Coverage changed. Review it again.');
        $recorded = $this->records->recorded($workspace, array_map(static fn (SquarePaymentFacts $fact): string => $fact->id, $observation->payments));
        $pending = $this->collection->summary($invoice)['pending_payments'];

        return ['data' => [
            'invoice_id' => $invoice->public_id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_version' => AgentApiVersion::for($invoice),
            'balance_amount' => $invoice->balance_amount,
            'currency' => $invoice->currency,
            'square_invoice_id' => $squareInvoiceId,
            'square_invoice_number' => $coverage->square_invoice_number,
            'payments' => array_map(function (SquarePaymentFacts $fact) use ($workspace, $invoice, $squareInvoiceId, $observation, $recorded, $pending, $allowsRecord): array {
                $existing = $recorded->get($fact->id);
                $refusal = $existing === null ? $this->refusal($invoice, $fact, $observation, $pending) : 'This Square payment is already recorded in SVC.';

                return [
                    'square_payment_id' => $fact->id,
                    'amount' => $fact->amount,
                    'refunded_amount' => $fact->refundedAmount,
                    'currency' => $fact->currency,
                    'received_on' => $fact->receivedAt->setTimezone($workspace->timezone)->toDateString(),
                    'reviewed_version' => $observation->version($fact),
                    'recorded_payment_id' => $existing?->public_id,
                    'refusal' => $refusal,
                    'record_href' => $refusal === null && $allowsRecord && AgentWriteCutover::payments()
                        ? route('agent-api.v1.invoices.square-covered-payments.store', [$workspace, $invoice->public_id, $squareInvoiceId, $fact->id], absolute: false) : null,
                ];
            }, $observation->payments),
        ]];
    }

    /** @param array<string,mixed> $payload */
    public function record(User $actor, Workspace $workspace, string $invoiceId, string $squareInvoiceId, string $paymentId, string $clientId, string $key, array $payload, bool $native = false): ClientInvoicePayment
    {
        $this->admit($actor, $workspace);
        abort_unless($native || AgentWriteCutover::payments(), 404);
        abort_unless(trim($key) !== '' && strlen($key) <= 255, 422, 'An idempotency key is required.');
        /** @var array{expected_version:string,reviewed_version:string,confirm:true} $data */
        $data = Validator::make(['record' => $payload], [
            'record' => ['required', 'array:expected_version,reviewed_version,confirm'],
            'record.expected_version' => ['required', 'string', 'size:64'],
            'record.reviewed_version' => ['required', 'string', 'size:64'],
            'record.confirm' => ['required', new ExplicitConfirmation],
        ])->validate()['record'];
        // Read-only provider calls happen before the receipt transaction. An
        // existing completed receipt may replay even if Square is now offline
        // or the historical coverage was removed; its payment stays recorded.
        try {
            $observed = $this->observe($workspace, $invoiceId, $squareInvoiceId);
        } catch (SquareRequestFailed|DomainException|ModelNotFoundException|HttpExceptionInterface $error) {
            $observed = $error;
        }
        $ids = $this->mutations->run($actor, $workspace, $clientId, self::RECORD, $key,
            ['invoice_id' => $invoiceId, 'square_invoice_id' => $squareInvoiceId, 'square_payment_id' => $paymentId, ...$data],
            function () use ($actor, $workspace, $invoiceId, $squareInvoiceId, $paymentId, $data, $observed): array {
                $this->admit($actor, $workspace);
                if ($observed instanceof Throwable) {
                    throw $observed;
                }
                BillingMaintenance::share($workspace->id);
                $invoice = $this->records->invoice($workspace, $invoiceId, true);
                abort_unless(AgentApiVersion::matches($invoice, $data['expected_version']), 409, 'The invoice changed. Review the payment again.');
                // Compare the transaction snapshot with the locked current row
                // before classifying payment rows read without out-of-order locks.
                $seen = $this->records->invoice($workspace, $invoiceId);
                abort_unless(AgentApiVersion::matches($seen, AgentApiVersion::for($invoice)), 409, 'The invoice changed while this payment waited. Review it again.');
                abort_unless($invoice->workspace->timezone === $observed->timezone, 409, 'Workspace timezone changed. Review the payment again.');
                $fact = $observed->payment($paymentId);
                abort_unless(hash_equals($observed->version($fact), $data['reviewed_version']), 409, 'Square payment facts changed. Review the payment again.');
                $refusal = $this->refusal($invoice, $fact, $observed, $this->collection->summary($invoice)['pending_payments']);
                if ($refusal !== null) {
                    throw new DomainException($refusal);
                }
                $payment = $this->received->recordProvider($workspace, $invoice, ReceivedPaymentData::from([
                    'amount' => $fact->amount, 'currency' => $fact->currency,
                    'received_on' => $fact->receivedAt->setTimezone($workspace->timezone)->toDateString(), 'method' => 'square',
                ]), new ReceivedPaymentProvider('square', $fact->id));
                // The final current coverage read follows all ledger locks.
                // A concurrent coverage change rolls the receipt and payment back.
                $this->records->assertCurrentManager($actor, $workspace);
                $coverage = $this->records->coverage($invoice, $squareInvoiceId, true);
                abort_unless($coverage->public_id === $observed->coverageId, 409, 'Coverage changed. Review it again.');

                return [$payment->public_id];
            }, fn (array $ids) => $this->records->assertCurrentManager($actor, $workspace));

        $payment = $this->records->payment($workspace, $ids[0]);
        if ($observed instanceof SquareCoveredPaymentObservation) {
            $this->settlements->recordCharge($workspace, $observed->payment($paymentId));
        }

        return $payment;
    }

    private function observe(Workspace $workspace, string $invoiceId, string $squareInvoiceId): SquareCoveredPaymentObservation
    {
        $invoice = $this->records->invoice($workspace, $invoiceId);
        $coverage = $this->records->coverage($invoice, $squareInvoiceId);
        if (! $this->limiter->attempt('square-covered-payments:'.$workspace->id, 20, static fn (): bool => true, 600)) {
            abort(429, 'Square payment review was requested often just now. Retry later.', ['Retry-After' => (string) max(1, $this->limiter->availableIn('square-covered-payments:'.$workspace->id))]);
        }
        $payments = $this->reader->read($workspace, $squareInvoiceId, $invoice->currency, refreshMirror: false);

        return new SquareCoveredPaymentObservation($coverage->public_id, $payments,
            $payments === [] ? [] : $this->disputes->read(array_map(static fn (SquarePaymentFacts $fact): string => $fact->id, $payments)), $workspace->timezone);
    }

    private function refusal(ClientInvoice $invoice, SquarePaymentFacts $fact, SquareCoveredPaymentObservation $observation, int $pending): ?string
    {
        $issued = match (InvoiceStatus::tryFrom((string) $invoice->status)) {
            InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid, InvoiceStatus::Disputed => true,
            InvoiceStatus::Draft, InvoiceStatus::Void, null => false,
        };
        if (! $issued) {
            return 'Only issued invoices can receive these payments.';
        }
        if ($pending > 0) {
            return 'Another payment is still in flight. Resolve it before recording this payment.';
        }
        if ($fact->currency !== $invoice->currency) {
            return 'The payment currency does not match this invoice.';
        }
        $receivedOn = $fact->receivedAt->setTimezone($invoice->workspace->timezone)->toDateString();
        $bounds = PaymentDateBounds::asOf($this->clock->today($invoice->workspace));
        if ($receivedOn < $bounds->earliest() || $receivedOn > $bounds->latest()) {
            return 'This payment date is outside the recording window and requires manual review.';
        }
        if ($fact->amount > $invoice->balance_amount) {
            return 'This payment exceeds the remaining invoice balance. Review its allocation manually.';
        }

        return $observation->providerRefusal($fact);
    }

    private function admit(User|AgentPrincipal $actor, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403, 'Covered Square payments are available to workspace managers only.');
    }
}
