<?php

namespace Tests\Unit\Finance;

use App\Support\Finance\ProcessorMovementFacts;
use App\Support\Finance\ProcessorMovementKind;
use Carbon\CarbonImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessorMovementFactsTest extends TestCase
{
    public function test_the_movement_vocabulary_is_explicit(): void
    {
        $this->assertSame([
            'refund', 'refund_reversal', 'dispute_debit', 'dispute_credit', 'fee', 'fee_reversal',
        ], ProcessorMovementKind::all());
    }

    /** @return iterable<string, array{string, string, int, int, int}> */
    public static function validMovements(): iterable
    {
        foreach (['stripe', 'square'] as $provider) {
            foreach ([
                ['refund', -100, -3, -97],
                ['refund_reversal', 100, 0, 100],
                ['dispute_debit', -100, 5, -105],
                ['dispute_credit', 100, -5, 105],
                ['fee', 0, 5, -5],
                ['fee_reversal', 0, -5, 5],
            ] as [$kind, $principal, $fee, $net]) {
                yield $provider.' '.$kind => [$provider, $kind, $principal, $fee, $net];
            }
        }
    }

    #[DataProvider('validMovements')]
    public function test_each_kind_preserves_actual_signed_facts(string $provider, string $kind, int $principal, int $fee, int $net): void
    {
        $facts = $this->facts([
            'provider' => $provider,
            'dateBasis' => $provider === 'stripe' ? 'stripe_balance_created' : 'square_effective_at',
            'kind' => ProcessorMovementKind::from($kind),
            'principalMinor' => $principal,
            'feeMinor' => $fee,
            'netMinor' => $net,
        ]);

        $this->assertSame($provider, $facts->provider);
        $this->assertSame($kind, $facts->kind->value);
        $this->assertSame([$principal, $fee, $net], [$facts->principalMinor, $facts->feeMinor, $facts->netMinor]);
        $this->assertSame('USD', $facts->currency);
        $this->assertSame(['synthetic_payment_1'], $facts->paymentIdentifiers);
        $this->assertSame('synthetic_entry_1', $facts->identifier);
        $this->assertSame('synthetic_refund_1', $facts->sourceIdentifier);
    }

    public function test_dates_are_immutable_utc_and_optional_facts_remain_unknown(): void
    {
        $occurred = CarbonImmutable::parse('2030-01-02T23:30:00-08:00');
        $available = CarbonImmutable::parse('2030-01-05T01:00:00+02:00');
        $facts = $this->facts(['occurredAt' => $occurred, 'availableOn' => $available]);

        $this->assertSame('2030-01-03T07:30:00+00:00', $facts->occurredAt->toIso8601String());
        $this->assertSame('2030-01-04T23:00:00+00:00', $facts->availableOn?->toIso8601String());
        $this->assertSame('-08:00', $occurred->format('P'));
        $this->assertSame('+02:00', $available->format('P'));
        $this->assertNull($facts->payoutIdentifier);
        $unknown = $this->facts(['sourceIdentifier' => null]);
        $this->assertNull($unknown->sourceIdentifier);
        $this->assertNull($unknown->availableOn);
        $this->assertSame(['synthetic_payment_1'], $unknown->paymentIdentifiers);
    }

    public function test_safe_public_integer_and_identity_edges_are_accepted(): void
    {
        $max = 9007199254740991;
        $facts = $this->facts([
            'identifier' => str_repeat('e', 255),
            'sourceIdentifier' => str_repeat('s', 255),
            'payoutIdentifier' => str_repeat('p', 255),
            'paymentIdentifiers' => ['synthetic_payment_1', 'synthetic_payment_2', 'synthetic_payment_3', str_repeat('i', 255)],
            'principalMinor' => -$max,
            'feeMinor' => 0,
            'netMinor' => -$max,
        ]);
        $this->assertSame(-$max, $facts->netMinor);
        $this->assertCount(4, $facts->paymentIdentifiers);
        $this->assertSame(255, strlen($facts->identifier));
        $this->assertSame(255, strlen($facts->sourceIdentifier ?? ''));
        $this->assertSame(255, strlen($facts->payoutIdentifier ?? ''));
        $credit = $this->facts(['kind' => ProcessorMovementKind::RefundReversal, 'principalMinor' => $max, 'netMinor' => $max]);
        $this->assertSame($max, $credit->netMinor);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidFacts(): iterable
    {
        foreach (['provider', 'identifier', 'paymentIdentifiers', 'kind', 'principalMinor', 'feeMinor', 'netMinor', 'currency', 'occurredAt', 'dateBasis'] as $field) {
            yield 'null '.$field => [[$field => null]];
        }
        foreach (['identifier', 'sourceIdentifier', 'payoutIdentifier'] as $field) {
            yield 'empty '.$field => [[$field => '']];
            yield 'blank '.$field => [[$field => '  ']];
            yield 'overlong '.$field => [[$field => str_repeat('x', 256)]];
            yield 'nonscalar '.$field => [[$field => ['synthetic_canary']]];
        }
        yield 'unknown provider' => [['provider' => 'synthetic_provider']];
        yield 'empty payments' => [['paymentIdentifiers' => []]];
        yield 'associative payments' => [['paymentIdentifiers' => ['a' => 'synthetic_payment_1']]];
        yield 'duplicate payments' => [['paymentIdentifiers' => ['synthetic_payment_1', 'synthetic_payment_1']]];
        yield 'too many payments' => [['paymentIdentifiers' => ['a', 'b', 'c', 'd', 'e']]];
        yield 'empty payment' => [['paymentIdentifiers' => ['']]];
        yield 'blank payment' => [['paymentIdentifiers' => [' ']]];
        yield 'overlong payment' => [['paymentIdentifiers' => [str_repeat('x', 256)]]];
        yield 'nonscalar payment' => [['paymentIdentifiers' => [['synthetic_canary']]]];
        yield 'lower currency' => [['currency' => 'usd']];
        yield 'invalid currency' => [['currency' => 'US1']];
        yield 'wrong currency width' => [['currency' => 'USDD']];
        yield 'wrong date basis' => [['dateBasis' => 'square_effective_at']];
        yield 'wrong square date basis' => [['provider' => 'square']];
        yield 'unknown date basis' => [['dateBasis' => 'synthetic_now']];
        yield 'raw occurred date' => [['occurredAt' => '2030-01-01']];
        yield 'raw available date' => [['availableOn' => '2030-01-01']];
        yield 'raw kind' => [['kind' => 'refund']];
        yield 'net does not reconcile' => [['netMinor' => -99]];
        yield 'unsafe derived negative net' => [['principalMinor' => -9007199254740991, 'feeMinor' => 1, 'netMinor' => -9007199254740991]];
        yield 'unsafe derived positive net' => [['principalMinor' => 9007199254740991, 'feeMinor' => -1, 'netMinor' => 9007199254740991]];
        foreach (['principalMinor', 'feeMinor', 'netMinor'] as $field) {
            foreach ([9007199254740992, -9007199254740992, PHP_INT_MAX, PHP_INT_MIN, 1.5, '100', true, []] as $index => $value) {
                yield 'unsafe '.$field.' '.$index => [[$field => $value]];
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidFacts')]
    public function test_invalid_facts_are_refused_without_private_values(array $overrides): void
    {
        try {
            $this->facts($overrides);
            $this->fail('Invalid processor facts were accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('The processor movement facts are unreadable.', $exception->getMessage());
        }
    }

    public function test_missing_required_facts_are_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The processor movement facts are unreadable.');
        new ProcessorMovementFacts;
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function wrongSigns(): iterable
    {
        yield 'positive refund' => ['refund', 1, 0];
        yield 'zero refund' => ['refund', 0, 0];
        yield 'negative refund reversal' => ['refund_reversal', -1, 0];
        yield 'zero refund reversal' => ['refund_reversal', 0, 0];
        yield 'positive dispute debit' => ['dispute_debit', 1, 0];
        yield 'zero dispute debit' => ['dispute_debit', 0, 0];
        yield 'negative dispute credit' => ['dispute_credit', -1, 0];
        yield 'zero dispute credit' => ['dispute_credit', 0, 0];
        yield 'fee has principal' => ['fee', 1, 1];
        yield 'negative fee' => ['fee', 0, -1];
        yield 'zero fee' => ['fee', 0, 0];
        yield 'fee reversal has principal' => ['fee_reversal', -1, -1];
        yield 'positive fee reversal' => ['fee_reversal', 0, 1];
        yield 'zero fee reversal' => ['fee_reversal', 0, 0];
    }

    #[DataProvider('wrongSigns')]
    public function test_kind_requires_its_own_principal_or_fee_sign(string $kind, int $principal, int $fee): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The processor movement facts are unreadable.');
        $this->facts(['kind' => ProcessorMovementKind::from($kind), 'principalMinor' => $principal, 'feeMinor' => $fee, 'netMinor' => $principal - $fee]);
    }

    /** @param array<string, mixed> $overrides */
    private function facts(array $overrides = []): ProcessorMovementFacts
    {
        return new ProcessorMovementFacts(...array_replace([
            'provider' => 'stripe',
            'identifier' => 'synthetic_entry_1',
            'paymentIdentifiers' => ['synthetic_payment_1'],
            'sourceIdentifier' => 'synthetic_refund_1',
            'kind' => ProcessorMovementKind::Refund,
            'principalMinor' => -100,
            'feeMinor' => 0,
            'netMinor' => -100,
            'currency' => 'USD',
            'occurredAt' => CarbonImmutable::parse('2030-01-02T12:00:00Z'),
            'dateBasis' => 'stripe_balance_created',
        ], $overrides));
    }
}
