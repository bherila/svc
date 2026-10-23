<?php

namespace App\Support\Billing;

use App\Support\Finance\ProcessorMovementFacts;
use App\Support\Finance\ProcessorMovementKind;
use Carbon\CarbonImmutable;
use DomainException;
use SensitiveParameter;

/**
 * Actual refund/dispute balance entries, never inferred from status or totals.
 * Other dispute fees may be separate; these entries do not prove complete fees.
 * https://docs.stripe.com/api/refunds/object
 * https://docs.stripe.com/api/disputes/object
 * https://docs.stripe.com/api/balance_transactions/object
 */
final class StripeProcessorMovementFacts
{
    /**
     * A missing pointer means no published movement; an unresolved pointer is
     * refused. Failed refunds keep the initial debit and separate failure credit.
     *
     * @param  array<string, mixed>  $refund
     * @param  array<string, mixed>|null  $balanceTransaction
     */
    public static function refund(#[SensitiveParameter] array $refund, #[SensitiveParameter] ?array $balanceTransaction, bool $failure = false, #[SensitiveParameter] ?string $payoutIdentifier = null): ?ProcessorMovementFacts
    {
        self::parent($refund, 'refund');
        $pointer = $refund[$failure ? 'failure_balance_transaction' : 'balance_transaction'] ?? null;
        if ($pointer === null && $balanceTransaction === null) {
            return null;
        }
        if ($balanceTransaction === null) {
            throw self::unreadable();
        }
        self::assertPointer($pointer, $balanceTransaction, $refund);
        $allowedTypes = $failure ? ['refund_failure', 'payment_failure_refund'] : ['refund', 'payment_refund'];
        if (! in_array($balanceTransaction['type'] ?? null, $allowedTypes, true)) {
            throw self::unreadable();
        }

        return self::facts($refund, $balanceTransaction, $failure ? ProcessorMovementKind::RefundReversal : ProcessorMovementKind::Refund, $payoutIdentifier);
    }

    /**
     * The supplied entry must appear in this dispute's actual balance array.
     * Its signed principal distinguishes withdrawal from reinstatement.
     *
     * @param  array<string, mixed>  $dispute
     * @param  array<string, mixed>|null  $balanceTransaction
     */
    public static function dispute(#[SensitiveParameter] array $dispute, #[SensitiveParameter] ?array $balanceTransaction = null, #[SensitiveParameter] ?string $payoutIdentifier = null): ?ProcessorMovementFacts
    {
        self::parent($dispute, 'dispute');
        $members = $dispute['balance_transactions'] ?? null;
        if (! is_array($members) || ! array_is_list($members) || count($members) > 2) {
            throw self::unreadable();
        }
        if ($members === [] && $balanceTransaction === null) {
            return null;
        }
        if ($balanceTransaction === null) {
            throw self::unreadable();
        }
        $matched = false;
        $seen = [];
        foreach ($members as $member) {
            $id = self::id($member);
            if (in_array($id, $seen, true)) {
                throw self::unreadable();
            }
            $seen[] = $id;
            if ($id === ($balanceTransaction['id'] ?? null)) {
                self::assertPointer($member, $balanceTransaction, $dispute);
                $matched = true;
            }
        }
        $amount = $balanceTransaction['amount'] ?? null;
        if (! $matched || ! is_int($amount) || $amount === 0
            || ! in_array($balanceTransaction['type'] ?? null, ['adjustment', 'payment_reversal'], true)) {
            throw self::unreadable();
        }

        return self::facts($dispute, $balanceTransaction, $amount < 0 ? ProcessorMovementKind::DisputeDebit : ProcessorMovementKind::DisputeCredit, $payoutIdentifier);
    }

    /** @param  array<string, mixed>  $parent */
    private static function parent(#[SensitiveParameter] array &$parent, string $object): void
    {
        self::id($parent['id'] ?? null);
        if (isset($parent['object']) && $parent['object'] !== $object) {
            throw self::unreadable();
        }
        $parent['object'] = $object;
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $parent
     */
    private static function assertPointer(#[SensitiveParameter] mixed $pointer, #[SensitiveParameter] array $transaction, #[SensitiveParameter] array $parent): void
    {
        if (self::id($pointer) !== self::id($transaction['id'] ?? null)) {
            throw self::unreadable();
        }
        if (is_array($pointer)) {
            self::boundReferences($parent, $pointer['source'] ?? null);
            foreach (['amount', 'fee', 'net', 'currency', 'created', 'available_on'] as $column) {
                if (array_key_exists($column, $pointer) && $pointer[$column] !== ($transaction[$column] ?? null)) {
                    throw self::unreadable();
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $parent
     * @param  array<string, mixed>  $transaction
     */
    private static function facts(#[SensitiveParameter] array $parent, #[SensitiveParameter] array $transaction, ProcessorMovementKind $kind, #[SensitiveParameter] ?string $payoutIdentifier): ProcessorMovementFacts
    {
        if (isset($transaction['object']) && $transaction['object'] !== 'balance_transaction') {
            throw self::unreadable();
        }
        $references = self::boundReferences($parent, $transaction['source'] ?? null);
        if ($references === []) {
            throw self::unreadable();
        }
        $currency = $transaction['currency'] ?? null;
        if (! is_string($currency) || preg_match('/^[A-Za-z]{3}$/D', $currency) !== 1) {
            throw self::unreadable();
        }
        if ($payoutIdentifier !== null) {
            self::id($payoutIdentifier);
        }
        try {
            return new ProcessorMovementFacts(
                provider: 'stripe', identifier: self::id($transaction['id'] ?? null),
                paymentIdentifiers: array_values($references), sourceIdentifier: self::id($parent['id'] ?? null),
                kind: $kind, principalMinor: $transaction['amount'] ?? null,
                feeMinor: $transaction['fee'] ?? null, netMinor: $transaction['net'] ?? null,
                currency: strtoupper($currency), occurredAt: self::timestamp($transaction['created'] ?? null),
                dateBasis: 'stripe_balance_created',
                availableOn: isset($transaction['available_on']) ? self::timestamp($transaction['available_on']) : null,
                payoutIdentifier: $payoutIdentifier,
            );
        } catch (DomainException) {
            throw self::unreadable();
        }
    }

    /**
     * @param  array<string, mixed>  $parent
     * @return array<string, string>
     */
    private static function boundReferences(#[SensitiveParameter] array $parent, #[SensitiveParameter] mixed $source): array
    {
        $references = self::paymentReferences($parent);
        if ($source !== null) {
            if (self::id($source) !== $parent['id']) {
                throw self::unreadable();
            }
            if (is_array($source)) {
                if (isset($source['object'], $parent['object']) && $source['object'] !== $parent['object']) {
                    throw self::unreadable();
                }
                foreach (self::paymentReferences($source) as $key => $identifier) {
                    if (isset($references[$key]) && $references[$key] !== $identifier) {
                        throw self::unreadable();
                    }
                    $references[$key] = $identifier;
                }
            }
        }

        return $references;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, string>
     */
    private static function paymentReferences(#[SensitiveParameter] array $source): array
    {
        $references = [];
        $intent = $source['payment_intent'] ?? null;
        if ($intent !== null) {
            $references['intent'] = self::id($intent);
        }
        $charge = $source['charge'] ?? null;
        if ($charge !== null) {
            $references['charge'] = self::id($charge);
            $nested = is_array($charge) ? ($charge['payment_intent'] ?? null) : null;
            if ($nested !== null) {
                $nestedId = self::id($nested);
                if (isset($references['intent']) && $references['intent'] !== $nestedId) {
                    throw self::unreadable();
                }
                $references = ['intent' => $nestedId, ...$references];
            }
        }

        return $references;
    }

    private static function id(#[SensitiveParameter] mixed $value): string
    {
        $value = is_array($value) ? ($value['id'] ?? null) : $value;
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_]{0,254}$/D', $value) !== 1) {
            throw self::unreadable();
        }

        return $value;
    }

    private static function timestamp(#[SensitiveParameter] mixed $value): CarbonImmutable
    {
        if (! is_int($value) || $value < 0 || $value > 253402300799) {
            throw self::unreadable();
        }

        return CarbonImmutable::createFromTimestampUTC($value);
    }

    private static function unreadable(): DomainException
    {
        return new DomainException('The Stripe processor movement is unreadable.');
    }
}
