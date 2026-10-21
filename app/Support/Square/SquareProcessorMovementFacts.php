<?php

namespace App\Support\Square;

use App\Support\Finance\ProcessorMovementFacts;
use App\Support\Finance\ProcessorMovementKind;
use Carbon\CarbonImmutable;
use DomainException;
use SensitiveParameter;
use Throwable;

/**
 * Signed actual payout entries, with explicit payment provenance.
 * A reported fee describes this entry, never completeness of all payment fees.
 * https://developer.squareup.com/reference/square/objects/PayoutEntry
 * https://developer.squareup.com/reference/square/enums/ActivityType
 */
final class SquareProcessorMovementFacts
{
    /** Activity types outside this refund/dispute/payment-fee feed. */
    private const array NON_MOVEMENTS = [
        'CHARGE', 'PAYOUT', 'DEPOSIT_FEE', 'DEPOSIT_FEE_REVERSED',
        'MONEY_TRANSFER', 'MONEY_TRANSFER_REVERSAL',
        'AUTOMATIC_SAVINGS', 'AUTOMATIC_SAVINGS_REVERSED',
        'BALANCE_FOLDERS_TRANSFER', 'BALANCE_FOLDERS_TRANSFER_REVERSED',
    ];

    /** Inspect only published detail fields actually present; omitted bindings stay unknown. */
    private const array DETAILS = [
        'REFUND' => 'type_refund_details',
        'OPEN_DISPUTE' => 'type_open_dispute_details',
        'DISPUTE' => 'type_dispute_details',
        'FEE' => 'type_fee_details',
        'PROCESSING_FEE' => 'type_other_details',
        'PROCESSING_FEE_REFUND' => 'type_other_details',
        'UNDO_PROCESSING_FEE_REFUND' => 'type_other_details',
        'THIRD_PARTY_FEE' => 'type_third_party_fee_details',
        'THIRD_PARTY_FEE_REFUND' => 'type_third_party_fee_refund_details',
        'TAX_ON_FEE' => 'type_tax_on_fee_details',
    ];

    /**
     * A supplied source must be the refund/dispute actually named by this entry;
     * it cannot supply an arbitrary payment binding chosen by the caller.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed>|null $source
     */
    public static function from(#[SensitiveParameter] array $entry, #[SensitiveParameter] ?array $source = null): ?ProcessorMovementFacts
    {
        $type = $entry['type'] ?? null;
        if (in_array($type, self::NON_MOVEMENTS, true)) {
            return null;
        }
        if (! is_string($type) || ! isset(self::DETAILS[$type])) {
            throw self::unreadable();
        }
        $details = $entry[self::DETAILS[$type]] ?? null;
        if (! is_array($details)) {
            throw self::unreadable();
        }
        $referenceKey = match ($type) {
            'REFUND' => 'refund_id',
            'OPEN_DISPUTE', 'DISPUTE' => 'dispute_id',
            default => null,
        };
        $reference = $referenceKey === null ? null : ($details[$referenceKey] ?? null);
        $reference = $reference === null ? null : self::id($reference);
        $paymentId = $details['payment_id'] ?? null;
        $paymentId = $paymentId === null ? null : self::id($paymentId);
        if ($source !== null) {
            if ($reference === null || self::id($source['id'] ?? null) !== $reference) {
                throw self::unreadable();
            }
            $disputedPayment = $source['disputed_payment'] ?? null;
            $resolvedPayment = $referenceKey === 'refund_id'
                ? ($source['payment_id'] ?? null)
                : (is_array($disputedPayment) ? ($disputedPayment['payment_id'] ?? null) : null);
            $resolvedPayment = self::id($resolvedPayment);
            if ($paymentId !== null && $paymentId !== $resolvedPayment) {
                throw self::unreadable();
            }
            $paymentId = $resolvedPayment;
        }
        if ($paymentId === null) {
            throw self::unreadable();
        }
        [$principal, $fee, $net, $currency] = self::money($entry);
        $kind = match ($type) {
            'REFUND' => $principal < 0 ? ProcessorMovementKind::Refund : ProcessorMovementKind::RefundReversal,
            'OPEN_DISPUTE', 'DISPUTE' => $principal < 0 ? ProcessorMovementKind::DisputeDebit : ProcessorMovementKind::DisputeCredit,
            // A standalone fee entry's whole net is fee expense, not principal.
            default => $net < 0 ? ProcessorMovementKind::Fee : ProcessorMovementKind::FeeReversal,
        };
        if (in_array($kind, [ProcessorMovementKind::Fee, ProcessorMovementKind::FeeReversal], true)) {
            $principal = 0;
            $fee = -$net;
        }
        $payout = $entry['payout_id'] ?? null;
        try {
            return new ProcessorMovementFacts(
                provider: 'square', identifier: self::id($entry['id'] ?? null),
                paymentIdentifiers: [$paymentId], sourceIdentifier: $reference,
                kind: $kind, principalMinor: $principal, feeMinor: $fee, netMinor: $net,
                currency: $currency, occurredAt: self::effectiveAt($entry['effective_at'] ?? null),
                dateBasis: 'square_effective_at',
                payoutIdentifier: $payout === null ? null : self::id($payout),
            );
        } catch (DomainException) {
            throw self::unreadable();
        }
    }

    /**
     * Any two published components determine the third exactly via net=gross-fee.
     * One component alone never means that the missing fee was zero.
     *
     * @param array<string, mixed> $entry
     * @return array{int, int, int, string}
     */
    private static function money(#[SensitiveParameter] array $entry): array
    {
        $amounts = [];
        $currency = null;
        foreach (['gross', 'fee', 'net'] as $component) {
            $money = $entry[$component.'_amount_money'] ?? null;
            if ($money === null) {
                continue;
            }
            if (! is_array($money)) {
                throw self::unreadable();
            }
            $unit = $money['currency'] ?? $money['currency_code'] ?? null;
            if (! is_string($unit) || preg_match('/^[A-Z]{3}$/D', $unit) !== 1
                || (isset($money['currency'], $money['currency_code']) && $money['currency'] !== $money['currency_code'])
                || ($currency !== null && $currency !== $unit)) {
                throw self::unreadable();
            }
            $currency = $unit;
            $amounts[$component] = self::minor($money['amount'] ?? null);
        }
        if (count($amounts) < 2 || $currency === null) {
            throw self::unreadable();
        }
        $gross = $amounts['gross'] ?? null;
        $fee = $amounts['fee'] ?? null;
        $net = $amounts['net'] ?? null;
        if ($gross === null) {
            if ($fee === null || $net === null) {
                throw self::unreadable();
            }
            $gross = self::minor($net + $fee);
        }
        if ($fee === null) {
            if ($net === null) {
                throw self::unreadable();
            }
            $fee = self::minor($gross - $net);
        }
        $net ??= self::minor($gross - $fee);
        if ($net !== $gross - $fee) {
            throw self::unreadable();
        }

        return [$gross, $fee, $net, $currency];
    }

    private static function minor(#[SensitiveParameter] mixed $value): int
    {
        if (! is_int($value) || $value < -ProcessorMovementFacts::MAX_MINOR || $value > ProcessorMovementFacts::MAX_MINOR) {
            throw self::unreadable();
        }

        return $value;
    }

    private static function id(#[SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,254}$/D', $value) !== 1) {
            throw self::unreadable();
        }

        return $value;
    }

    /** Normalize published nanoseconds to the persistence boundary's microseconds. */
    private static function effectiveAt(#[SensitiveParameter] mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || ($parts[8] !== 'Z' && ((int) substr($parts[8], 1, 2) > 23 || (int) substr($parts[8], 4, 2) > 59))) {
            throw self::unreadable();
        }
        $fraction = str_pad(substr($parts[7] ?? '', 0, 6), 6, '0');
        $normalized = $parts[1].'-'.$parts[2].'-'.$parts[3].'T'.$parts[4].':'.$parts[5].':'.$parts[6].'.'.$fraction.$parts[8];
        try {
            $at = CarbonImmutable::parse($normalized)->utc();
        } catch (Throwable) {
            throw self::unreadable();
        }
        if ($at->year < 1000 || $at->year > 9999) {
            throw self::unreadable();
        }

        return $at;
    }

    private static function unreadable(): DomainException
    {
        return new DomainException('The Square processor movement is unreadable.');
    }
}
