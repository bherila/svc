<?php

namespace App\Support\Billing;

use App\Services\Billing\InvoiceLifecycleService;

/**
 * What state a payment against a client invoice is in.
 *
 * The vocabulary was already written down four times - two validation rule
 * lists, an `in_array` in {@see InvoiceLifecycleService::setPaymentStatus()},
 * and the literal `'succeeded'` the balance recomputation filters on - and the
 * four did not agree about who enforced it. The HTTP layer constrained the
 * value; the service and the console command did not. `svc:billing:payment
 * --status=paid` was accepted end to end, wrote `paid` into an unconstrained
 * `varchar(24)`, and produced a row that the recomputation below reads as
 * having contributed no money.
 *
 * That is the fail-open direction, and it is the one that loses money rather
 * than blocking a run: the invoice stays `issued` for its full balance, so a
 * payment genuinely received is invisible and the same balance can be
 * collected a second time. Compare {@see InvoiceStatus::hasChargedValue()},
 * which answers *yes* for an unrecognised invoice status precisely so that an
 * uninterpretable state cannot be acted on. A payment row has to fail closed
 * the same way, and it cannot do that while "which values exist" is a fact
 * spread across four files.
 *
 * So it lives here once. Both sides now parse rather than compare: nothing
 * writes a status this enum does not carry, and nothing sums a set of payments
 * containing one.
 */
enum InvoicePaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
    case Canceled = 'canceled';

    /**
     * Every status, for validation and schema documentation.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether money in this state counts toward what the invoice has been paid.
     *
     * Only a succeeded payment does. `refunded` is excluded and would be zero
     * anyway - a full refund sets `refunded_amount` to the whole amount - but
     * saying so here rather than relying on that arithmetic keeps the two
     * facts from having to stay in step.
     *
     * Exhaustive with no `default`, for the same reason
     * {@see InvoiceKind::requiresCompleteServicePeriod()} is: a seventh status
     * must be a compile-time question. A `default` would answer it "contributes
     * nothing", which is the direction that leaves received money outstanding.
     */
    public function contributesToPaidAmount(): bool
    {
        return match ($this) {
            self::Succeeded => true,
            self::Pending, self::Failed, self::Refunded, self::Disputed, self::Canceled => false,
        };
    }
}
