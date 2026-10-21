<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripeEvent;
use App\Models\ClientStripePaymentMethod;
use App\Models\Workspace;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\StripeEventMustBeRetried;
use App\Support\Billing\StripePayoutFacts;
use App\Support\Billing\StripeRefundStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;

final class StripeWebhookService
{
    /** Local bounds: an incomplete refund snapshot must never release recorded money. */
    private const int REFUND_MAX_PAGES = 100;

    private const int REFUND_PAGE_SIZE = 100;

    /**
     * Events that report a change to one refund. `charge.refund.updated` is
     * Stripe's older name, `refund.updated` and `refund.failed` the current
     * ones; any of them may be subscribed, and each is handled the same way.
     */
    private const array REFUND_UPDATES = ['charge.refund.updated', 'refund.updated', 'refund.failed'];

    /**
     * Events that report a payout reaching its end (S7). Each is applied from
     * the payout object it carries, ordered by the event's own time, so a
     * payout reported failed after it was reported paid stays failed.
     */
    private const array PAYOUT_EVENTS = ['payout.paid', 'payout.failed', 'payout.canceled'];

    public function __construct(
        private readonly InvoiceLifecycleService $invoices,
        private readonly StripePaymentMethodService $paymentMethods,
        private readonly StripeGateway $gateway,
        private readonly StripeSettlementSync $settlements,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    public function process(Event $event, string $payload): bool
    {
        $data = $event->toArray();
        $object = is_array($data['data']['object'] ?? null) ? $data['data']['object'] : [];
        $payloadHash = hash('sha256', $payload);

        // The idempotency-ledger row must commit independently of business handling:
        // if it shared the business transaction, a domain failure would roll the row
        // back, leave no queryable failure record, and make Stripe retry the same
        // deterministic failure indefinitely.
        DB::table('client_stripe_events')->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'stripe_event_id' => (string) $event->id,
            'event_type' => (string) $event->type,
            'object_id' => isset($object['id']) ? (string) $object['id'] : null,
            'payload_hash' => $payloadHash,
            'status' => 'received',
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
        $ledger = ClientStripeEvent::query()->where('stripe_event_id', $event->id)->firstOrFail();
        if (! hash_equals($ledger->payload_hash, $payloadHash)
            || $ledger->event_type !== (string) $event->type) {
            throw new \DomainException('A Stripe event ID was replayed with different contents.');
        }
        if (in_array($ledger->status, ['processed', 'failed'], true)) {
            // Processed events are benign replays; failed events were already recorded
            // and need operator intervention, not a retry of the same deterministic
            // failure. A 'received' row from an interrupted attempt falls through and
            // is processed again.
            return false;
        }

        try {
            if (in_array((string) $event->type, self::PAYOUT_EVENTS, true)) {
                return $this->handlePayout($event, $object, $ledger);
            }
            $processed = $this->handle($event, $object, $ledger);
        } catch (\DomainException $exception) {
            $ledger->forceFill([
                'status' => 'failed',
                'error_summary' => mb_substr($exception->getMessage(), 0, 1000),
                'processed_at' => $this->clock->now(),
            ])->save();

            throw $exception;
        }
        if ($event->type === 'payment_intent.succeeded') {
            $this->recordChargeSettlement($object);
        }

        return $processed;
    }

    /**
     * Store a payout for each workspace whose payments it settles, and record
     * on each settled payment its payout, arrival date and fee (S7).
     *
     * Stripe is read first - the payout's balance transactions, outside any
     * transaction - and a failure to read it is answered with a retryable
     * error, leaving the event to be delivered again. Applying the same event
     * again changes nothing.
     *
     * @param  array<string, mixed>  $object
     */
    private function handlePayout(Event $event, array $object, ClientStripeEvent $ledger): bool
    {
        $payout = StripePayoutFacts::from($object, max(0, (int) ($event->created ?? 0)) * 1000);
        $result = $this->settlements->apply($payout, $this->settlements->members($payout));
        $ledger->forceFill([
            'workspace_id' => $result->soleWorkspaceId(),
            'status' => 'processed',
            'processed_at' => $this->clock->now(),
        ])->save();

        return true;
    }

    /**
     * After a payment succeeds and is committed, read its charge's time and
     * fee from Stripe (S7). Best effort: the payment is already recorded, and
     * what cannot be read now is filled by the payout that settles it or by
     * `svc:finance:backfill-payouts`, so a failure here is logged and the
     * event still succeeds. Skipped where Stripe has no secret key.
     *
     * @param  array<string, mixed>  $object
     */
    private function recordChargeSettlement(array $object): void
    {
        $identifier = is_string($object['id'] ?? null) ? $object['id'] : '';
        if ($identifier === '' || $this->gateway->mode() === null) {
            return;
        }
        $payment = ClientInvoicePayment::query()->where('provider', 'stripe')->where('provider_payment_identifier', $identifier)->first();
        if ($payment === null || $payment->workspace_id < 1) {
            return;
        }
        try {
            $this->settlements->recordCharge($payment);
        } catch (StripeEventMustBeRetried $failure) {
            Log::warning('Stripe charge settlement could not be read; the payout or the backfill will fill it.', [
                'payment' => $payment->public_id,
                'reason' => $failure->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $object */
    private function handle(Event $event, array $object, ClientStripeEvent $ledger): bool
    {
        // Resolve scope outside the business transaction: its first ordinary
        // read must not establish a snapshot before the payment/invoice locks.
        $paymentWorkspaceId = null;
        $standingRefunds = null;
        $type = (string) $event->type;
        if (str_starts_with($type, 'payment_intent.') || str_starts_with($type, 'charge.') || in_array($type, self::REFUND_UPDATES, true)) {
            $probe = $this->paymentQuery($object)->first(['workspace_id', 'lock_version']);
            $paymentWorkspaceId = $probe !== null && $probe->workspace_id > 0 ? $probe->workspace_id : null;
            // Every refund correction is read from the current refund list,
            // including charge.refunded: Stripe does not promise that its
            // aggregate excludes a refund that later failed. Capture the local
            // revision before HTTP, then refuse a read overtaken by a write.
            if ($probe !== null && $paymentWorkspaceId !== null && ($type === 'charge.refunded' || $this->isUndoneRefund($type, $object))) {
                $standingRefunds = [...$this->standingRefundTotal($object, $type === 'charge.refunded'), 'version' => $probe->lock_version];
            }
        }

        return DB::transaction(function () use ($event, $object, $ledger, $paymentWorkspaceId, $standingRefunds): bool {
            if ($paymentWorkspaceId !== null) {
                BillingMaintenance::share($paymentWorkspaceId);
            }
            $providerCreatedAt = max(0, (int) ($event->created ?? 0));
            if (in_array($event->type, ['payment_method.attached', 'setup_intent.succeeded'], true)) {
                $paymentMethod = $this->paymentMethodObject($event, $object);
                if ($paymentMethod === null) {
                    throw new \DomainException('The Stripe event does not identify a payment method.');
                }
                if (! is_string($paymentMethod['id'] ?? null) || $paymentMethod['id'] === ''
                    || ! is_string($paymentMethod['customer'] ?? null) || $paymentMethod['customer'] === '') {
                    throw new \DomainException('The Stripe payment method does not identify its customer.');
                }
                $method = $this->paymentMethods->attach($paymentMethod, (string) $event->id, $providerCreatedAt);
                $ledger->workspace_id = $method instanceof ClientStripePaymentMethod && $method->workspace_id > 0
                    ? $method->workspace_id
                    : null;
            } elseif ($event->type === 'payment_method.detached') {
                $providerId = is_string($object['id'] ?? null) ? $object['id'] : '';
                if ($providerId === '') {
                    throw new \DomainException('The Stripe event does not identify a detached payment method.');
                }
                $workspaceId = $this->paymentMethods->detach($providerId, (string) $event->id, $providerCreatedAt);
                $ledger->workspace_id = is_int($workspaceId) && $workspaceId > 0 ? $workspaceId : null;
            } elseif ($event->type === 'customer.updated') {
                $customerId = is_string($object['id'] ?? null) ? $object['id'] : '';
                $settings = is_array($object['invoice_settings'] ?? null) ? $object['invoice_settings'] : [];
                $default = $settings['default_payment_method'] ?? null;
                $defaultId = is_string($default) && $default !== ''
                    ? $default
                    : (is_array($default) && is_string($default['id'] ?? null) ? $default['id'] : null);
                if ($customerId === '') {
                    throw new \DomainException('The Stripe event does not identify an updated customer.');
                }
                $workspaceId = $this->paymentMethods->changeDefault(
                    $customerId,
                    $defaultId,
                    (string) $event->id,
                    $providerCreatedAt,
                );
                $ledger->workspace_id = is_int($workspaceId) && $workspaceId > 0 ? $workspaceId : null;
            } else {
                $this->handleInvoiceEvent($event, $object, $ledger, $paymentWorkspaceId, $standingRefunds);
            }

            $ledger->forceFill(['status' => 'processed', 'processed_at' => $this->clock->now()])->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array{amount: int, currency: string, version: int}|null  $standingRefunds  current standing refunds for a charge.refunded or undone-refund event, and the payment revision read before Stripe was asked
     */
    private function handleInvoiceEvent(Event $event, array $object, ClientStripeEvent $ledger, ?int $paymentWorkspaceId, ?array $standingRefunds): void
    {
        $payment = $paymentWorkspaceId === null ? null : $this->paymentQuery($object)
            ->where('workspace_id', $paymentWorkspaceId)->tap(Locks::forUpdate())->first();
        $providerCreatedAt = max(0, (int) ($event->created ?? 0));
        // Stripe's vocabulary on the left, this application's on the right.
        // The mapping is the one place the two meet, so the right-hand side
        // names enum cases rather than strings that happen to agree today.
        $status = match ((string) $event->type) {
            'payment_intent.succeeded' => InvoicePaymentStatus::Succeeded->value,
            'payment_intent.processing' => InvoicePaymentStatus::Pending->value,
            'payment_intent.payment_failed' => InvoicePaymentStatus::Failed->value,
            'payment_intent.canceled' => InvoicePaymentStatus::Canceled->value,
            'charge.dispute.created' => InvoicePaymentStatus::Disputed->value,
            'charge.dispute.closed' => ($object['status'] ?? null) === 'won'
                ? InvoicePaymentStatus::Succeeded->value
                : InvoicePaymentStatus::Disputed->value,
            default => null,
        };

        if (($event->type === 'charge.refunded' || in_array($event->type, self::REFUND_UPDATES, true)) && $payment !== null) {
            $ledger->workspace_id = $payment->workspace_id;
            // An update unrelated to a failure - a trace reference, metadata -
            // leaves the refund total alone and is not recorded as the latest
            // payment event: it is not evidence of a money change.
            if ($standingRefunds === null) {
                return;
            }
            if ($standingRefunds['currency'] !== $payment->currency) {
                throw new \DomainException('Stripe refund currency does not match the invoice payment.');
            }
            // The total was read before this lock. Had the payment moved since
            // - a newer refund recorded meanwhile - the read may predate it,
            // so it is not applied; Stripe's retry reads afresh.
            if ($payment->lock_version !== $standingRefunds['version']) {
                throw new StripeEventMustBeRetried('The payment changed while Stripe was being read.');
            }
            // Not subject to the payment's event ordering: the total was read
            // from Stripe just now, so it is current whenever this
            // notification was sent, and applying it twice changes nothing.
            // Only a notification newer than the payment's latest becomes it,
            // so an older one cannot let a still older event through.
            $older = $this->isOlderPaymentEvent($payment, $providerCreatedAt, InvoicePaymentStatus::Refunded->value);
            $this->invoices->setRefundedAmount($payment, $standingRefunds['amount']);
            if (! $older) {
                $this->recordPaymentEvent($payment, $providerCreatedAt, (string) $event->id);
            }
        } elseif ($status !== null && $payment !== null) {
            if ($this->isOlderPaymentEvent($payment, $providerCreatedAt, $status)) {
                $ledger->workspace_id = $payment->workspace_id;

                return;
            }
            if ($status === InvoicePaymentStatus::Succeeded->value) {
                $amount = (int) ($object['amount_received'] ?? $object['amount'] ?? 0);
                $currency = strtoupper((string) ($object['currency'] ?? ''));
                if ($event->type === 'charge.dispute.closed') {
                    // A won dispute carries the amount that was disputed, which
                    // may be part of the payment: a partial win restores it.
                    if ($amount < 1 || $amount > (int) $payment->amount || $currency !== $payment->currency) {
                        throw new \DomainException('Stripe dispute does not match the disputed invoice payment.');
                    }
                } elseif ($amount !== (int) $payment->amount || $currency !== $payment->currency) {
                    throw new \DomainException('Stripe payment does not match the pending invoice payment.');
                }
            }
            if (! $this->isStalePaymentTransition($payment->status, $status)) {
                $disputedAmount = $status === InvoicePaymentStatus::Disputed->value ? $this->disputedAmount($object, $payment) : null;
                if ($disputedAmount !== null) {
                    $this->invoices->setPaymentDisputed($payment, $disputedAmount);
                } else {
                    $this->invoices->setPaymentStatus($payment, $status);
                }
                $this->recordPaymentEvent($payment, $providerCreatedAt, (string) $event->id);
            }
            $ledger->workspace_id = $payment->workspace_id;
        } elseif ($event->type === 'payment_intent.succeeded') {
            $this->createSuccessfulPayment($event, $object, $ledger);
        }
    }

    /**
     * The part of the payment a dispute event says is contested, or null when
     * it does not say - the whole payment is then held, as before amounts were
     * recorded. An amount that is not part of the payment is refused.
     *
     * @param  array<string, mixed>  $object
     */
    private function disputedAmount(array $object, ClientInvoicePayment $payment): ?int
    {
        $amount = $object['amount'] ?? null;
        if ($amount === null) {
            return null;
        }
        $currency = is_string($object['currency'] ?? null) ? strtoupper($object['currency']) : '';
        if (! is_int($amount) || $amount < 1 || $amount > (int) $payment->amount || $currency !== $payment->currency) {
            throw new \DomainException('Stripe dispute does not match the disputed invoice payment.');
        }

        return $amount;
    }

    /** @param array<string, mixed> $object */
    private function isUndoneRefund(string $type, array $object): bool
    {
        return in_array($type, self::REFUND_UPDATES, true)
            && ! StripeRefundStatus::stillStands(is_string($object['status'] ?? null) ? $object['status'] : '');
    }

    /**
     * The refunds on the event's charge that still stand - every one that has
     * not failed or been canceled - totalled, as Stripe reports them now.
     *
     * @param  array<string, mixed>  $object  the charge or refund the event reported
     * @return array{amount: int, currency: string}
     *
     * @see https://docs.stripe.com/refunds#handle-failed-refunds
     * @see https://docs.stripe.com/api/refunds/list
     */
    private function standingRefundTotal(array $object, bool $chargeEvent): array
    {
        $charge = $chargeEvent ? ($object['id'] ?? null) : ($object['charge'] ?? null);
        $chargeId = is_array($charge) ? ($charge['id'] ?? null) : $charge;
        if (! is_string($chargeId) || $chargeId === '') {
            throw new \DomainException('The Stripe refund does not identify its charge.');
        }
        $currency = is_string($object['currency'] ?? null) ? strtoupper($object['currency']) : '';
        $total = 0;
        $cursor = null;
        $seen = [];
        try {
            for ($page = 0; $page < self::REFUND_MAX_PAGES; $page++) {
                $params = ['charge' => $chargeId, 'limit' => self::REFUND_PAGE_SIZE];
                if ($cursor !== null) {
                    $params['starting_after'] = $cursor;
                }
                $body = $this->gateway->client()->refunds->all($params)->toArray();
                $refunds = $body['data'] ?? null;
                $hasMore = $body['has_more'] ?? null;
                if (($body['object'] ?? null) !== 'list' || ! is_bool($hasMore)
                    || ! is_array($refunds) || ! array_is_list($refunds)
                    || count($refunds) > self::REFUND_PAGE_SIZE || ($hasMore && $refunds === [])) {
                    throw new StripeEventMustBeRetried('Stripe refunds did not form a complete readable list; retry the event.');
                }
                foreach ($refunds as $refund) {
                    $id = is_array($refund) ? ($refund['id'] ?? null) : null;
                    $amount = is_array($refund) ? ($refund['amount'] ?? null) : null;
                    $status = is_array($refund) ? ($refund['status'] ?? null) : null;
                    $refundCurrency = is_array($refund) ? ($refund['currency'] ?? null) : null;
                    $refundCharge = is_array($refund) ? ($refund['charge'] ?? null) : null;
                    $refundCharge = is_array($refundCharge) ? ($refundCharge['id'] ?? null) : $refundCharge;
                    if (! is_array($refund) || ($refund['object'] ?? null) !== 'refund'
                        || ! is_string($id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,254}$/D', $id) !== 1
                        || isset($seen[$id]) || ! is_int($amount) || $amount < 1
                        || ! is_string($status) || $status === '' || strlen($status) > 64
                        || ! is_string($refundCurrency) || strtoupper($refundCurrency) !== $currency
                        || $refundCharge !== $chargeId) {
                        throw new StripeEventMustBeRetried('Stripe refunds did not form a complete readable list; retry the event.');
                    }
                    $seen[$id] = true;
                    $cursor = $id;
                    if (StripeRefundStatus::stillStands($status)) {
                        if ($amount > PHP_INT_MAX - $total) {
                            throw new StripeEventMustBeRetried('Stripe refunds exceed the supported amount; retry the event.');
                        }
                        $total += $amount;
                    }
                }
                if (! $hasMore) {
                    return ['amount' => $total, 'currency' => $currency];
                }
            }
        } catch (ApiErrorException|\RuntimeException) {
            throw new StripeEventMustBeRetried('Stripe refunds could not be read; retry the event.');
        }

        throw new StripeEventMustBeRetried('Stripe refunds exceed the complete-list page bound; retry the event.');
    }

    private function isOlderPaymentEvent(
        ClientInvoicePayment $payment,
        int $providerCreatedAt,
        string $nextStatus,
    ): bool {
        $lastCreatedAt = $payment->provider_event_created_at;
        if ($lastCreatedAt === null) {
            return false;
        }
        if ($lastCreatedAt > $providerCreatedAt) {
            return true;
        }

        // Stripe event timestamps have one-second precision. Within a tied
        // second, keep a failure rather than returning it to processing.
        return $lastCreatedAt === $providerCreatedAt
            && $payment->status === InvoicePaymentStatus::Failed->value
            && $nextStatus === InvoicePaymentStatus::Pending->value;
    }

    private function recordPaymentEvent(
        ClientInvoicePayment $payment,
        int $providerCreatedAt,
        string $eventId,
    ): void {
        ClientInvoicePayment::query()
            ->where('workspace_id', $payment->workspace_id)
            ->whereKey($payment->id)
            ->update([
                'provider_event_created_at' => max(0, $providerCreatedAt),
                'provider_event_id' => $eventId,
                // A builder update never reaches IncrementsAgentRevision, so the
                // revision a correction checks is moved here, as every write
                // to the row must move it (PaymentRevisionWritersTest).
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_at' => $this->clock->now(),
            ]);
    }

    private function isStalePaymentTransition(string $current, string $next): bool
    {
        $backwards = [InvoicePaymentStatus::Pending->value, InvoicePaymentStatus::Failed->value];

        // Exhaustive over the vocabulary, and the unreadable case is answered
        // deliberately rather than inherited from a `default`. An unknown
        // *current* status is not evidence that a recognised replacement is
        // stale - it is the one transition worth applying, because writing a
        // status this application can read is exactly the repair
        // `InvoiceLifecycleService::setPaymentStatus()` supports. Calling it
        // stale here would strand the row unreadable with no route back, and
        // every balance on that invoice refuses while it stays that way.
        return match (InvoicePaymentStatus::tryFrom($current)) {
            InvoicePaymentStatus::Succeeded,
            InvoicePaymentStatus::Disputed => in_array($next, $backwards, true),
            InvoicePaymentStatus::Refunded => $next !== InvoicePaymentStatus::Refunded->value,
            InvoicePaymentStatus::Canceled => $next !== InvoicePaymentStatus::Canceled->value,
            InvoicePaymentStatus::Pending, InvoicePaymentStatus::Failed, null => false,
        };
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function paymentMethodObject(Event $event, array $object): ?array
    {
        if ($event->type === 'payment_method.attached') {
            return $object;
        }

        $paymentMethod = $object['payment_method'] ?? null;
        if (is_array($paymentMethod)) {
            return $paymentMethod;
        }
        if (! is_string($paymentMethod) || $paymentMethod === '') {
            return null;
        }

        return $this->gateway->client()->paymentMethods->retrieve($paymentMethod, [])->toArray();
    }

    /** @param array<string, mixed> $object
     * @return Builder<ClientInvoicePayment> */
    private function paymentQuery(array $object): Builder
    {
        $ids = array_values(array_filter([
            isset($object['id']) ? (string) $object['id'] : null,
            isset($object['payment_intent']) && is_string($object['payment_intent']) ? $object['payment_intent'] : null,
        ]));

        return ClientInvoicePayment::query()
            ->where('provider', 'stripe')
            ->whereIn('provider_payment_identifier', $ids);
    }

    /** @param array<string, mixed> $object */
    private function createSuccessfulPayment(Event $event, array $object, ClientStripeEvent $ledger): void
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $invoiceId = $metadata['invoice_public_id'] ?? null;
        $workspaceId = $metadata['workspace_public_id'] ?? null;
        if (! is_string($invoiceId) || $invoiceId === ''
            || ! is_string($workspaceId) || $workspaceId === '') {
            return;
        }
        $workspace = Workspace::query()->where('public_id', $workspaceId)->first();
        if ($workspace === null) {
            return;
        }
        $invoice = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $invoiceId)
            ->first();
        if ($invoice === null) {
            return;
        }
        $amount = (int) ($object['amount_received'] ?? $object['amount'] ?? 0);
        $currency = strtoupper((string) ($object['currency'] ?? ''));
        if ($amount <= 0 || $currency !== $invoice->currency) {
            return;
        }

        $payment = $this->invoices->applyPayment($invoice, [
            'amount' => $amount,
            'currency' => $currency,
            'received_on' => $this->clock->today($workspace)->toDateString(),
            'method' => 'stripe',
            'status' => InvoicePaymentStatus::Succeeded->value,
            'provider' => 'stripe',
            'provider_payment_identifier' => (string) ($object['id'] ?? ''),
        ]);
        $this->recordPaymentEvent($payment, max(0, (int) ($event->created ?? 0)), (string) $event->id);
        $ledger->workspace_id = $payment->workspace_id;
    }
}
