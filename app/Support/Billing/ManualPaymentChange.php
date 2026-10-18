<?php

namespace App\Support\Billing;

use DomainException;
use LogicException;

/**
 * A change to what a hand-recorded payment is worth, parsed, with the rule that
 * decides whether it may happen (#455).
 *
 * Two kinds, each with a reason that is kept in the client history:
 *
 * - **A status change** (`payments.set_status`): a cheque or a transfer that
 *   cleared (`succeeded`), that bounced or was reversed (`failed`), or that was
 *   recorded in error and is withdrawn (`canceled`).
 * - **A refund** (`payments.refund`): money already returned to the client
 *   outside SVC. SVC never sends money; this records that it was sent.
 *
 * The transitions, by the payment's current status:
 *
 * | from | succeeded | failed | canceled | refund |
 * | --- | --- | --- | --- | --- |
 * | `pending` | yes | yes | yes | no: nothing has been received yet |
 * | `succeeded`, nothing refunded | (no change) | yes | yes | yes, up to the amount |
 * | `succeeded`, partly refunded | (no change) | no: refund the rest | no: refund the rest | yes, up to what is left |
 * | `refunded` | no | no | no | no: nothing is left |
 * | `failed`, `canceled` | no | (no change) / no | no / (no change) | no |
 * | `disputed` | no | no | no | no |
 *
 * `failed`, `canceled`, `refunded` and `disputed` are final for a hand-recorded
 * payment: money that arrives after all is a new payment, recorded as one, so
 * the history says both things happened. `refunded` is reached only by
 * refunding the whole amount and `disputed` is a payment processor's word, so
 * neither can be set; and nothing returns to `pending`.
 *
 * Only a payment recorded by hand can change here. A processor's payment - a
 * Stripe or Square one - follows the processor, which reports its failures,
 * disputes and refunds itself; a change made here would be overwritten by the
 * next event, or would contradict it.
 *
 * Database-free by construction, so every row of that table is unit tested
 * without a payment. The service applies the decision under the payment and
 * invoice locks and owns everything a decision implies - the invoice's derived
 * balance and status, the credit pool, reconciliation capacity, the activity.
 */
final readonly class ManualPaymentChange
{
    public const int REASON_MAX_LENGTH = PaymentCorrection::REASON_MAX_LENGTH;

    /** The statuses a hand-recorded payment may be moved to. */
    public const array SETTABLE = [InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled];

    private function __construct(
        public ?InvoicePaymentStatus $status,
        public ?int $refund,
        public string $reason,
    ) {}

    /** Move a hand-recorded payment to `succeeded`, `failed` or `canceled`. */
    public static function status(mixed $status, mixed $reason): self
    {
        $parsed = is_string($status) ? InvoicePaymentStatus::tryFrom($status) : null;
        if ($parsed === null) {
            throw new DomainException('A payment can be set to one of: '.implode(', ', self::settableValues()).'. Nothing has been changed.');
        }

        $settable = match ($parsed) {
            InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled => $parsed,
            InvoicePaymentStatus::Pending => throw new DomainException('A payment cannot be returned to pending. Nothing has been changed.'),
            InvoicePaymentStatus::Refunded => throw new DomainException('A payment is refunded by recording the refund (payments.refund), which keeps the amount returned. Nothing has been changed.'),
            InvoicePaymentStatus::Disputed => throw new DomainException('Disputed is reported by a payment processor and cannot be set on a payment recorded by hand. Nothing has been changed.'),
        };

        return new self($settable, null, self::reason($reason));
    }

    /** Record that `$amount` (minor units) of a hand-recorded payment was returned to the client. */
    public static function refund(mixed $amount, mixed $reason): self
    {
        if (! is_int($amount) || $amount < 1) {
            throw new DomainException('A refund amount is a whole number of minor units, at least 1. Nothing has been changed.');
        }

        return new self(null, $amount, self::reason($reason));
    }

    /** @return list<string> */
    public static function settableValues(): array
    {
        return array_map(static fn (InvoicePaymentStatus $status): string => $status->value, self::SETTABLE);
    }

    public function isRefund(): bool
    {
        return $this->refund !== null;
    }

    /**
     * What this change makes of a payment currently reading so, or null when
     * it already reads that way.
     *
     * @param  string|null  $provider  the processor that recorded it, null for one recorded by hand
     * @return array{status: InvoicePaymentStatus, refunded_amount: int}|null
     *
     * @throws DomainException when the change may not happen
     */
    public function decide(string $currentStatus, int $amount, int $refundedAmount, ?string $provider): ?array
    {
        if ($provider !== null) {
            throw new DomainException('This payment was recorded by '.$provider.', and its status and refunds follow '.$provider.'. Only a payment recorded by hand can be changed here. Nothing has been changed.');
        }
        $current = InvoicePaymentStatus::tryFrom($currentStatus)
            ?? throw new DomainException('The payment carries the unrecognised status "'.$currentStatus.'", so what it is worth cannot be established. Classify or correct it first. Nothing has been changed.');

        return $this->refund === null
            ? $this->decideStatus($current, $refundedAmount)
            : $this->decideRefund($current, $amount, $refundedAmount, $this->refund);
    }

    /** @return array{status: InvoicePaymentStatus, refunded_amount: int}|null */
    private function decideStatus(InvoicePaymentStatus $current, int $refundedAmount): ?array
    {
        // @infection-ignore-all Unreachable: status() always names one and refund() is decided by decideRefund(); the throw only guards the type.
        $next = $this->status ?? throw new LogicException('A status change names its status.');
        if ($current === $next) {
            return null;
        }

        match ($current) {
            InvoicePaymentStatus::Pending => null,
            InvoicePaymentStatus::Succeeded => $refundedAmount === 0
                ? null
                : throw new DomainException('Part of this payment has been refunded, so it cannot be marked '.$next->value.'. Record the rest of the refund instead. Nothing has been changed.'),
            InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled, InvoicePaymentStatus::Refunded, InvoicePaymentStatus::Disputed => throw new DomainException(
                'A '.$current->value.' payment is final. If the money arrives after all, record it as a new payment. Nothing has been changed.'
            ),
        };

        return ['status' => $next, 'refunded_amount' => $refundedAmount];
    }

    /** @return array{status: InvoicePaymentStatus, refunded_amount: int} */
    private function decideRefund(InvoicePaymentStatus $current, int $amount, int $refundedAmount, int $refund): array
    {
        match ($current) {
            InvoicePaymentStatus::Succeeded => null,
            InvoicePaymentStatus::Refunded => throw new DomainException('This payment has already been refunded in full. Nothing has been changed.'),
            InvoicePaymentStatus::Pending => throw new DomainException('A pending payment has not been received, so there is nothing to refund. Mark it failed or canceled instead. Nothing has been changed.'),
            InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled, InvoicePaymentStatus::Disputed => throw new DomainException(
                'A '.$current->value.' payment holds no money to refund. Nothing has been changed.'
            ),
        };

        $total = $refundedAmount + $refund;
        if ($total > $amount) {
            throw new DomainException('At most '.($amount - $refundedAmount).' of this payment is left to refund. Nothing has been changed.');
        }

        return [
            'status' => $total === $amount ? InvoicePaymentStatus::Refunded : InvoicePaymentStatus::Succeeded,
            'refunded_amount' => $total,
        ];
    }

    private static function reason(mixed $value): string
    {
        $reason = is_string($value) ? trim($value) : '';
        if ($reason === '') {
            throw new DomainException('A reason is required to change a payment. Nothing has been changed.');
        }
        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw new DomainException('The reason may not be longer than '.self::REASON_MAX_LENGTH.' characters. Nothing has been changed.');
        }

        return $reason;
    }
}
