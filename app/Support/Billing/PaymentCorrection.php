<?php

namespace App\Support\Billing;

use App\Models\ClientInvoicePayment;
use DomainException;

/**
 * A proposed correction to a payment's descriptive fields, parsed and bounded.
 *
 * A payment's *descriptive* fields - how it arrived, the reference on it, the
 * operator's note and the day it arrived - are bookkeeping about money, not the
 * money. None of them is an input to the invoice balance, the credit pool or a
 * reconciliation, so correcting one rewrites no financial fact. Everything else
 * on the row is money or provenance, and each of those already has its own
 * operation that preserves history instead of overwriting it. So this is a
 * closed allow-list rather than a deny-list: a column added to the table later
 * is refused here until someone decides which side of that line it is on.
 *
 * Parsing happens here, before anything is locked, so a malformed request is
 * refused without touching the database. The one check that cannot happen here
 * is the window around `received_on`, which is measured against the invoice's
 * workspace calendar and so needs the row: the date is kept as a parsed
 * calendar day and bounded by the service.
 *
 * Database-free by construction, so {@see self::diff()} - the question of what
 * a correction would actually change - is unit tested without a row.
 */
final readonly class PaymentCorrection
{
    /** The fields this operation may write, in the order they are reported. */
    public const array FIELDS = ['method', 'reference', 'notes', 'received_on'];

    /** The same bounds the recording form and the payments table apply. */
    public const int REFERENCE_MAX_LENGTH = 255;

    public const int NOTES_MAX_LENGTH = 10000;

    public const int REASON_MAX_LENGTH = 500;

    /**
     * How much of a note the activity keeps on each side of a change.
     *
     * An activity payload is capped at 10,000 bytes, and a note may be 10,000
     * characters, so recording both sides whole would refuse a legitimate
     * correction for the size of its own audit entry. The note itself is on
     * the payment; the activity says that it changed and how it began.
     */
    public const int NOTES_EXCERPT_LENGTH = 120;

    /**
     * Fields that exist on a payment and are deliberately out of reach, each
     * naming the operation that does change what it represents.
     */
    private const array REFUSED = [
        'amount' => 'A payment\'s amount is money, not a description of it. Cancel the payment (setPaymentStatus to canceled) and record the correct one.',
        'currency' => 'A payment\'s currency is money, not a description of it. Cancel the payment (setPaymentStatus to canceled) and record the correct one.',
        'status' => 'A payment\'s status is changed with setPaymentStatus, which recomputes the invoice and records the transition.',
        'refunded_amount' => 'A refund is recorded with setRefundedAmount, which checks reconciliations and recomputes the invoice.',
        'client_invoice_id' => 'A payment cannot be moved to another invoice. Cancel it and record it against the right invoice.',
        'invoice_id' => 'A payment cannot be moved to another invoice. Cancel it and record it against the right invoice.',
        'invoice' => 'A payment cannot be moved to another invoice. Cancel it and record it against the right invoice.',
        'workspace_id' => 'A payment cannot be moved to another workspace.',
        'provider' => 'Processor fields are written only by the payment processor integration.',
        'provider_payment_identifier' => 'Processor fields are written only by the payment processor integration.',
        'provider_event_id' => 'Processor fields are written only by the payment processor integration.',
        'provider_event_created_at' => 'Processor fields are written only by the payment processor integration.',
        'external_finance_transaction_uuid' => 'Reconciliation links are written only by the finance reconciliation endpoints.',
        'idempotency_key' => 'A payment\'s idempotency key identifies how it was recorded and cannot be changed.',
    ];

    /**
     * @param  array<string, string|null>  $changes  only the fields that were named, parsed
     */
    private function __construct(public array $changes, public ?string $reason) {}

    /**
     * Parse a correction request.
     *
     * `$reason` is null only for the long-standing date-only door, which has
     * never asked for one; every caller of the general operation must give one.
     *
     * @param  array<array-key, mixed>  $changes
     */
    public static function parse(array $changes, ?string $reason): self
    {
        foreach (array_keys($changes) as $field) {
            // @infection-ignore-all An integer key prints and compares the same as its string: in_array() is strict against a list of strings either way, and concatenation stringifies it.
            $name = (string) $field;
            if (in_array($name, self::FIELDS, true)) {
                continue;
            }

            throw new DomainException(
                'The payment field "'.$name.'" cannot be corrected here. '
                .(self::REFUSED[$name] ?? 'Only '.implode(', ', self::FIELDS).' can be corrected.')
                .' Nothing has been changed.'
            );
        }

        if ($changes === []) {
            throw new DomainException('Name at least one of '.implode(', ', self::FIELDS).' to correct.');
        }

        $parsed = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }
            $parsed[$field] = match ($field) {
                'method' => self::method($changes[$field]),
                'reference' => self::optionalText($changes[$field], 'reference', self::REFERENCE_MAX_LENGTH),
                'notes' => self::optionalText($changes[$field], 'notes', self::NOTES_MAX_LENGTH),
                'received_on' => self::receivedOn($changes[$field]),
            };
        }

        return new self($parsed, $reason === null ? null : self::reason($reason));
    }

    /**
     * What this correction would change on a payment currently reading `$current`.
     *
     * A field named with the value it already has is not a change, so a
     * correction that restates the row is empty and records nothing.
     *
     * @param  array<string, string|null>  $current  the payment's descriptive fields as stored
     * @param  array<string, string|null>  $bounded  the fields with their final, bounded values
     * @return array<string, array{old: string|null, new: string|null}>
     */
    public static function diff(array $current, array $bounded): array
    {
        $diff = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $bounded)) {
                continue;
            }
            $old = $current[$field] ?? null;
            $new = $bounded[$field];
            if ($old !== $new) {
                $diff[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $diff;
    }

    /**
     * The diff as the activity records it: every field whole, except a note,
     * which is kept as an excerpt so the payload stays inside its cap.
     *
     * @param  array<string, array{old: string|null, new: string|null}>  $diff
     * @return array<string, array{old: string|null, new: string|null}>
     */
    public static function forActivity(array $diff): array
    {
        if (isset($diff['notes'])) {
            $diff['notes'] = [
                'old' => self::excerpt($diff['notes']['old']),
                'new' => self::excerpt($diff['notes']['new']),
            ];
        }

        return $diff;
    }

    private static function excerpt(?string $text): ?string
    {
        if ($text === null || mb_strlen($text) <= self::NOTES_EXCERPT_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::NOTES_EXCERPT_LENGTH).'…';
    }

    private static function method(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException('method is required and cannot be cleared.');
        }
        $method = trim($value);
        if (mb_strlen($method) > ClientInvoicePayment::METHOD_MAX_LENGTH) {
            throw new DomainException('method may not be longer than '.ClientInvoicePayment::METHOD_MAX_LENGTH.' characters.');
        }

        return $method;
    }

    /** Null or blank clears the field; anything else is trimmed and bounded. */
    private static function optionalText(mixed $value, string $name, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new DomainException($name.' must be text or null.');
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new DomainException($name.' may not be longer than '.$max.' characters.');
        }

        return $text;
    }

    /**
     * The shape only; the window is the service's, because it needs the clock.
     *
     * Null is refused rather than read as "today", which is what it means on
     * the way in: a correction that names the field and gives no date is not
     * asking for today, and clearing the date is not a correction anyone needs.
     */
    private static function receivedOn(mixed $value): string
    {
        if ($value === null) {
            throw new DomainException('received_on cannot be cleared; give the date the money arrived as YYYY-MM-DD.');
        }

        return PaymentDateBounds::calendarDate($value);
    }

    private static function reason(string $value): string
    {
        $reason = trim($value);
        if ($reason === '') {
            throw new DomainException('A reason is required to correct a payment.');
        }
        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw new DomainException('The reason may not be longer than '.self::REASON_MAX_LENGTH.' characters.');
        }

        return $reason;
    }
}
