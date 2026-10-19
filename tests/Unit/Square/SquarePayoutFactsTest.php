<?php

namespace Tests\Unit\Square;

use App\Services\Square\SquareRequestFailed;
use App\Support\Finance\PayoutStatus;
use App\Support\Finance\PayoutType;
use App\Support\Square\SquarePayoutEntry;
use App\Support\Square\SquarePayoutFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Square's payout and payout-entry objects read as svc facts (S7). */
final class SquarePayoutFactsTest extends TestCase
{
    public function test_a_payout_is_read_in_svcs_vocabulary(): void
    {
        $facts = SquarePayoutFacts::from($this->payout(), 'po_fixture_1');

        $this->assertSame(['square', 'po_fixture_1', -2_500, 'USD', '2030-02-03', PayoutType::Automatic, PayoutStatus::InTransit, 1_896_253_200_123],
            [$facts->provider, $facts->identifier, $facts->amount, $facts->currency, $facts->arrivalDate, $facts->type, $facts->status, $facts->observedAtMs]);
        $simple = SquarePayoutFacts::from([...$this->payout(), 'type' => 'SIMPLE', 'status' => 'FAILED', 'arrival_date' => null], 'po_fixture_1');
        $this->assertSame([PayoutType::Instant, PayoutStatus::Failed, null], [$simple->type, $simple->status, $simple->arrivalDate]);
        $this->assertSame(PayoutStatus::Paid, SquarePayoutFacts::from([...$this->payout(), 'status' => 'PAID'], 'po_fixture_1')->status);
        // Without an update time, its creation time.
        $created = $this->payout();
        unset($created['updated_at']);
        $this->assertSame(1_896_249_600_000, SquarePayoutFacts::from($created, 'po_fixture_1')->observedAtMs);
        // Square's reference spelling of the currency is read too.
        $this->assertSame('USD', SquarePayoutFacts::from([...$this->payout(), 'amount_money' => ['amount' => 1, 'currency_code' => 'USD']], 'po_fixture_1')->currency);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1?: string}> */
    public static function unreadable(): iterable
    {
        yield 'another payout' => [['id' => 'po_fixture_2']];
        yield 'unknown status' => [['status' => 'PENDING']];
        yield 'unknown type' => [['type' => 'INSTANT']];
        yield 'no money' => [['amount_money' => null]];
        yield 'text amount' => [['amount_money' => ['amount' => '1', 'currency' => 'USD']]];
        yield 'lower currency' => [['amount_money' => ['amount' => 1, 'currency' => 'usd']]];
        yield 'arrival with a time' => [['arrival_date' => '2030-02-03T00:00:00Z']];
        yield 'impossible arrival' => [['arrival_date' => '2030-02-31']];
        yield 'unreadable update time' => [['updated_at' => '2030-02-02 09:00:00']];
        yield 'no time at all' => [['updated_at' => null, 'created_at' => null]];
        yield 'an unsafe id' => [['id' => 'po fixture'], 'po fixture'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('unreadable')]
    public function test_an_unreadable_payout_is_refused(array $override, string $expected = 'po_fixture_1'): void
    {
        $this->expectException(SquareRequestFailed::class);
        SquarePayoutFacts::from([...$this->payout(), ...$override], $expected);
    }

    public function test_a_charge_entry_names_its_payment_and_its_fee(): void
    {
        $entry = SquarePayoutEntry::from($this->charge(), 'po_fixture_1');
        $this->assertSame(['pay_fixture_1', 388, 'USD'], [$entry->paymentId, $entry->fee, $entry->currency]);

        // Without Square's fee, gross less net.
        $derived = SquarePayoutEntry::from([...$this->charge(), 'fee_amount_money' => null, 'net_amount_money' => ['amount' => 11_900, 'currency' => 'USD']], 'po_fixture_1');
        $this->assertSame(445, $derived->fee);
        // An entry that does not say its payout is read as this one's.
        $unsaid = $this->charge();
        unset($unsaid['payout_id']);
        $this->assertSame('pay_fixture_1', SquarePayoutEntry::from($unsaid, 'po_fixture_1')->paymentId);
    }

    public function test_any_other_entry_settles_no_payment(): void
    {
        foreach (['REFUND', 'FEE', 'ADJUSTMENT', 'DISPUTE'] as $type) {
            $entry = SquarePayoutEntry::from(['payout_id' => 'po_fixture_1', 'type' => $type], 'po_fixture_1');
            $this->assertSame([null, null, null], [$entry->paymentId, $entry->fee, $entry->currency], $type);
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function unreadableEntries(): iterable
    {
        yield 'another payout' => [['payout_id' => 'po_fixture_2']];
        yield 'no type' => [['type' => null]];
        yield 'no payment' => [['type_charge_details' => []]];
        yield 'an unsafe payment id' => [['type_charge_details' => ['payment_id' => 'pay fixture']]];
        yield 'no gross' => [['gross_amount_money' => null]];
        yield 'fee in another currency' => [['fee_amount_money' => ['amount' => 1, 'currency' => 'EUR']]];
        yield 'neither fee nor net' => [['fee_amount_money' => null, 'net_amount_money' => null]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('unreadableEntries')]
    public function test_an_unreadable_entry_is_refused(array $override): void
    {
        $this->expectException(SquareRequestFailed::class);
        SquarePayoutEntry::from([...$this->charge(), ...$override], 'po_fixture_1');
    }

    /** @return array<string, mixed> */
    private function payout(): array
    {
        return [
            'id' => 'po_fixture_1', 'status' => 'SENT', 'type' => 'BATCH', 'arrival_date' => '2030-02-03',
            'created_at' => '2030-02-02T08:00:00Z', 'updated_at' => '2030-02-02T09:00:00.123Z',
            'amount_money' => ['amount' => -2_500, 'currency' => 'USD'],
        ];
    }

    /** @return array<string, mixed> */
    private function charge(): array
    {
        return [
            'payout_id' => 'po_fixture_1', 'type' => 'CHARGE', 'type_charge_details' => ['payment_id' => 'pay_fixture_1'],
            'gross_amount_money' => ['amount' => 12_345, 'currency' => 'USD'], 'fee_amount_money' => ['amount' => 388, 'currency' => 'USD'],
            'net_amount_money' => ['amount' => 11_957, 'currency' => 'USD'],
        ];
    }
}
