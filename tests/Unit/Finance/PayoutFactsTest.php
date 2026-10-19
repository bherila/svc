<?php

namespace Tests\Unit\Finance;

use App\Support\Finance\PaymentSettlement;
use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutStatus;
use App\Support\Finance\PayoutSyncResult;
use App\Support\Finance\PayoutType;
use Carbon\CarbonImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The payout and settlement facts of S7, without a database. */
final class PayoutFactsTest extends TestCase
{
    public function test_the_vocabularies_are_exactly_these(): void
    {
        $this->assertSame(['pending', 'in_transit', 'paid', 'failed', 'canceled'], PayoutStatus::all());
        $this->assertSame(['automatic', 'manual', 'instant'], PayoutType::all());
        $this->assertSame([0, 1, 2, 3, 3], array_map(static fn (PayoutStatus $status): int => $status->progress(), PayoutStatus::cases()));
    }

    public function test_a_newer_report_supersedes_and_an_older_one_never_does(): void
    {
        $paid = $this->facts(PayoutStatus::Paid, 2000);
        $this->assertTrue($paid->supersedes(1999, PayoutStatus::Failed));
        $this->assertFalse($paid->supersedes(2001, PayoutStatus::Pending));
        // Stamped alike: the report further along wins, and the same one again applies.
        $this->assertTrue($paid->supersedes(2000, PayoutStatus::InTransit));
        $this->assertTrue($paid->supersedes(2000, PayoutStatus::Paid));
        $this->assertFalse($paid->supersedes(2000, PayoutStatus::Failed));
        $this->assertFalse($paid->supersedes(2000, PayoutStatus::Canceled));
        $this->assertTrue($this->facts(PayoutStatus::Failed, 2000)->supersedes(2000, PayoutStatus::Canceled));
        $this->assertFalse($this->facts(PayoutStatus::Pending, 2000)->supersedes(2000, PayoutStatus::InTransit));
        // An unreadable stored status is replaced by any readable one.
        $this->assertTrue($this->facts(PayoutStatus::Pending, 2000)->supersedes(2000, null));
    }

    /** @return iterable<string, array{0: array<int, mixed>}> */
    public static function invalidPayouts(): iterable
    {
        yield 'provider' => [['paypal', 'po_1', 1, 'USD', null, 0]];
        yield 'empty id' => [['stripe', '', 1, 'USD', null, 0]];
        yield 'long id' => [['stripe', str_repeat('x', 256), 1, 'USD', null, 0]];
        yield 'lower currency' => [['stripe', 'po_1', 1, 'usd', null, 0]];
        yield 'date with time' => [['stripe', 'po_1', 1, 'USD', '2026-10-20 00:00:00', 0]];
        yield 'before the epoch' => [['stripe', 'po_1', 1, 'USD', null, -1]];
    }

    /** @param array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: int} $fields */
    #[DataProvider('invalidPayouts')]
    public function test_a_payout_that_is_not_one_is_refused(array $fields): void
    {
        $this->expectException(DomainException::class);
        new PayoutFacts($fields[0], $fields[1], $fields[2], $fields[3], $fields[4], PayoutType::Automatic, PayoutStatus::Paid, $fields[5]);
    }

    public function test_the_longest_identifier_and_the_epoch_are_accepted(): void
    {
        $facts = new PayoutFacts('square', str_repeat('x', 255), -1, 'USD', '2026-10-20', PayoutType::Instant, PayoutStatus::Failed, 0);
        $this->assertSame([-1, '2026-10-20', 0], [$facts->amount, $facts->arrivalDate, $facts->observedAtMs]);
    }

    public function test_a_settlement_carries_what_it_was_given_in_utc(): void
    {
        $charge = PaymentSettlement::charge(CarbonImmutable::parse('2026-10-18T09:30:00-07:00'), 59, 'USD');
        $this->assertSame('2026-10-18T16:30:00+00:00', $charge->chargedAt?->toIso8601String());
        $this->assertSame([59, 'USD', null, null, false], [$charge->feeAmount, $charge->feeCurrency, $charge->payoutIdentifier, $charge->payoutArrivalDate, $charge->fromPayout]);

        $payout = PaymentSettlement::payout('po_1', '2026-10-21', null, null, null);
        $this->assertSame([null, null, 'po_1', '2026-10-21', true], [$payout->chargedAt, $payout->feeAmount, $payout->payoutIdentifier, $payout->payoutArrivalDate, $payout->fromPayout]);
        $this->assertNull(PaymentSettlement::payout('po_1', null, null, -3, 'USD')->payoutArrivalDate);
    }

    /** @return iterable<string, array{0: \Closure(): PaymentSettlement}> */
    public static function invalidSettlements(): iterable
    {
        yield 'fee without currency' => [static fn (): PaymentSettlement => PaymentSettlement::charge(null, 59, null)];
        yield 'currency without fee' => [static fn (): PaymentSettlement => PaymentSettlement::charge(null, null, 'USD')];
        yield 'lower currency' => [static fn (): PaymentSettlement => PaymentSettlement::charge(null, 59, 'usd')];
        yield 'empty payout' => [static fn (): PaymentSettlement => PaymentSettlement::payout('', null, null, null, null)];
        yield 'long payout' => [static fn (): PaymentSettlement => PaymentSettlement::payout(str_repeat('x', 256), null, null, null, null)];
        yield 'arrival with time' => [static fn (): PaymentSettlement => PaymentSettlement::payout('po_1', '2026-10-21T00:00:00Z', null, null, null)];
    }

    /** @param \Closure(): PaymentSettlement $build */
    #[DataProvider('invalidSettlements')]
    public function test_a_settlement_that_is_not_one_is_refused(\Closure $build): void
    {
        $this->expectException(DomainException::class);
        $build();
    }

    public function test_a_sync_result_names_its_workspace_only_when_there_is_one(): void
    {
        $this->assertSame(7, (new PayoutSyncResult([7], 1, 2))->soleWorkspaceId());
        $this->assertNull((new PayoutSyncResult([], 0, 0))->soleWorkspaceId());
        $this->assertNull((new PayoutSyncResult([7, 8], 2, 0))->soleWorkspaceId());
    }

    private function facts(PayoutStatus $status, int $observedAtMs): PayoutFacts
    {
        return new PayoutFacts('stripe', 'po_1', 941, 'USD', '2026-10-20', PayoutType::Automatic, $status, $observedAtMs);
    }
}
