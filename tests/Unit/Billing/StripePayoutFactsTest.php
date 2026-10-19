<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\StripeBalanceEntry;
use App\Support\Billing\StripePayoutFacts;
use App\Support\Finance\PayoutStatus;
use App\Support\Finance\PayoutType;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Stripe's payout and balance-transaction objects read as svc facts (S7). */
final class StripePayoutFactsTest extends TestCase
{
    public function test_a_payout_is_read_in_svcs_vocabulary(): void
    {
        $facts = StripePayoutFacts::from(['id' => 'po_synthetic_1', 'amount' => -500, 'currency' => 'usd', 'arrival_date' => 1_760_918_400, 'status' => 'in_transit', 'automatic' => true, 'method' => 'standard'], 42);

        $this->assertSame(['stripe', 'po_synthetic_1', -500, 'USD', '2025-10-20', PayoutType::Automatic, PayoutStatus::InTransit, 42],
            [$facts->provider, $facts->identifier, $facts->amount, $facts->currency, $facts->arrivalDate, $facts->type, $facts->status, $facts->observedAtMs]);
        $this->assertNull(StripePayoutFacts::from(['id' => 'po_1', 'amount' => 1, 'currency' => 'usd', 'status' => 'pending'], 0)->arrivalDate);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: PayoutType}> */
    public static function types(): iterable
    {
        yield 'instant outranks automatic' => [['automatic' => true, 'method' => 'instant'], PayoutType::Instant];
        yield 'automatic' => [['automatic' => true, 'method' => 'standard'], PayoutType::Automatic];
        yield 'manual' => [['automatic' => false, 'method' => 'standard'], PayoutType::Manual];
        yield 'unsaid is manual' => [[], PayoutType::Manual];
        yield 'truthy is not automatic' => [['automatic' => 1], PayoutType::Manual];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('types')]
    public function test_the_type_follows_the_method_and_who_started_it(array $fields, PayoutType $type): void
    {
        $this->assertSame($type, StripePayoutFacts::from(['id' => 'po_1', 'amount' => 1, 'currency' => 'usd', 'status' => 'paid', ...$fields], 0)->type);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function unreadable(): iterable
    {
        $payout = ['id' => 'po_1', 'amount' => 1, 'currency' => 'usd', 'status' => 'paid'];
        yield 'no id' => [[...$payout, 'id' => null], 'The Stripe payout does not identify itself.'];
        yield 'not a payout id' => [[...$payout, 'id' => 'tr_1'], 'The Stripe payout does not identify itself.'];
        yield 'a payout id with a space' => [[...$payout, 'id' => 'po_1 2'], 'The Stripe payout does not identify itself.'];
        yield 'text amount' => [[...$payout, 'amount' => '1'], 'The Stripe payout has no readable amount.'];
        yield 'no currency' => [[...$payout, 'currency' => null], 'The Stripe payout has no readable amount.'];
        yield 'long currency' => [[...$payout, 'currency' => 'usdx'], 'The Stripe payout has no readable amount.'];
        yield 'text arrival' => [[...$payout, 'arrival_date' => '2025-10-20'], 'The Stripe payout has an unreadable arrival date.'];
        yield 'unknown status' => [[...$payout, 'status' => 'reversed'], 'The Stripe payout has an unrecognised status.'];
    }

    /** @param array<string, mixed> $payout */
    #[DataProvider('unreadable')]
    public function test_an_unreadable_payout_is_refused(array $payout, string $message): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);
        StripePayoutFacts::from($payout, 0);
    }

    public function test_a_charge_entry_names_its_payment_and_carries_its_fee_and_charge_time(): void
    {
        $entry = StripeBalanceEntry::from(['type' => 'charge', 'fee' => 59, 'currency' => 'usd', 'created' => 1_760_000_500,
            'source' => ['id' => 'ch_1', 'payment_intent' => ['id' => 'pi_1'], 'created' => 1_760_000_000]]);

        $this->assertTrue($entry->isCharge);
        $this->assertSame(['ch_1', 'pi_1'], $entry->paymentIdentifiers);
        $this->assertSame([59, 'USD', '2025-10-09T08:53:20+00:00'], [$entry->fee, $entry->currency, $entry->chargedAt?->toIso8601String()]);

        // Without the charge's own time, the transaction's.
        $payment = StripeBalanceEntry::from(['type' => 'payment', 'fee' => 0, 'currency' => 'usd', 'created' => 1_760_000_500, 'source' => 'py_1']);
        $this->assertTrue($payment->isCharge);
        $this->assertSame(['py_1'], $payment->paymentIdentifiers);
        $this->assertSame(1_760_000_500, $payment->chargedAt?->getTimestamp());
    }

    public function test_a_refund_entry_names_its_payment_but_is_no_charge(): void
    {
        $entry = StripeBalanceEntry::from(['type' => 'refund', 'fee' => 0, 'currency' => 'usd', 'created' => 1_760_100_000,
            'source' => ['id' => 're_1', 'charge' => 'ch_1', 'payment_intent' => 'pi_1', 'created' => 1_760_100_000]]);

        $this->assertFalse($entry->isCharge);
        $this->assertSame(['re_1', 'pi_1', 'ch_1'], $entry->paymentIdentifiers);
        $this->assertNull($entry->chargedAt);
        $fee = StripeBalanceEntry::from(['type' => 'stripe_fee', 'fee' => 0, 'currency' => 'usd', 'source' => null]);
        $this->assertSame([], $fee->paymentIdentifiers);
        $this->assertSame([], StripeBalanceEntry::from(['type' => 'charge', 'fee' => 0, 'currency' => 'usd', 'source' => ['id' => '', 'payment_intent' => null]])->paymentIdentifiers);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function unreadableEntries(): iterable
    {
        yield 'no type' => [['fee' => 0, 'currency' => 'usd']];
        yield 'text fee' => [['type' => 'charge', 'fee' => '0', 'currency' => 'usd']];
        yield 'no currency' => [['type' => 'charge', 'fee' => 0]];
        yield 'long currency' => [['type' => 'charge', 'fee' => 0, 'currency' => 'usdx']];
    }

    /** @param array<string, mixed> $entry */
    #[DataProvider('unreadableEntries')]
    public function test_an_unreadable_entry_is_refused(array $entry): void
    {
        $this->expectException(DomainException::class);
        StripeBalanceEntry::from($entry);
    }
}
