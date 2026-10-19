<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\PaymentCollectionGuard;
use App\Support\Billing\InvoicePaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which stored payments keep payments.record refused (G6), classified in PHP
 * with the enum's exact parser - never by a collation-dependent SQL compare.
 */
final class PaymentCollectionGuardTest extends TestCase
{
    /** @return iterable<string, array{mixed, mixed, bool}> */
    public static function payments(): iterable
    {
        yield 'pending, by hand' => ['pending', null, true];
        yield 'pending, Stripe' => ['pending', 'stripe', true];
        yield 'pending, Square' => ['pending', 'square', true];
        // A declined intent returns to requires_payment_method and can be confirmed again.
        yield 'failed, Stripe' => ['failed', 'stripe', true];
        yield 'failed, by hand' => ['failed', null, false];
        yield 'failed, Square' => ['failed', 'square', false];
        yield 'failed, provider spelled otherwise' => ['failed', 'Stripe', false];
        foreach (['succeeded', 'refunded', 'disputed', 'canceled'] as $settled) {
            yield "{$settled}, by hand" => [$settled, null, false];
            yield "{$settled}, Stripe" => [$settled, 'stripe', false];
        }
        // Unreadable: anything the enum does not parse exactly, whatever a
        // case-insensitive collation would equate it with.
        yield 'SUCCEEDED' => ['SUCCEEDED', null, true];
        yield 'Succeeded' => ['Succeeded', 'stripe', true];
        yield 'CANCELED' => ['CANCELED', null, true];
        yield 'trailing space' => ['succeeded ', null, true];
        yield 'empty' => ['', null, true];
        yield 'null' => [null, null, true];
        yield 'not a string' => [1, null, true];
        yield 'unknown word' => ['synthetic-unknown', null, true];
    }

    #[DataProvider('payments')]
    public function test_a_payment_is_in_flight_exactly_when_it_may_still_bring_money(mixed $status, mixed $provider, bool $inFlight): void
    {
        $this->assertSame($inFlight, PaymentCollectionGuard::inFlight($status, $provider));
    }

    public function test_every_readable_status_is_classified(): void
    {
        $inFlight = array_values(array_filter(InvoicePaymentStatus::all(), static fn (string $status): bool => PaymentCollectionGuard::inFlight($status, null)));
        $this->assertSame(['pending'], $inFlight);
        $stripe = array_values(array_filter(InvoicePaymentStatus::all(), static fn (string $status): bool => PaymentCollectionGuard::inFlight($status, 'stripe')));
        $this->assertSame(['pending', 'failed'], $stripe);
    }
}
