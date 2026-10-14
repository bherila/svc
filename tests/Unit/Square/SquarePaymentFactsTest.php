<?php

namespace Tests\Unit\Square;

use App\Services\Square\SquareRequestFailed;
use App\Support\Square\SquarePaymentFacts;
use PHPUnit\Framework\TestCase;

final class SquarePaymentFactsTest extends TestCase
{
    public function test_completed_payment_uses_principal_and_cumulative_refund_only(): void
    {
        $raw = $this->fixture();
        $raw['refunded_money'] = ['amount' => 300, 'currency' => 'USD'];
        $facts = SquarePaymentFacts::from($raw, 'pay_fixture_1');
        $this->assertSame('pay_fixture_1', $facts->id);
        $this->assertSame('order_fixture_1', $facts->orderId);
        $this->assertSame('LOCFIXTURE01', $facts->locationId);
        $this->assertSame('USD', $facts->currency);
        $this->assertSame(1200, $facts->amount);
        $this->assertSame(300, $facts->refundedAmount);
        $this->assertSame('2030-02-01T16:00:00+00:00', $facts->receivedAt->toIso8601String());
        $raw['refunded_money']['amount'] = 1200;
        $this->assertSame(1200, SquarePaymentFacts::from($raw, 'pay_fixture_1')->refundedAmount);
        unset($raw['refunded_money']);
        $this->assertSame(0, SquarePaymentFacts::from($raw, 'pay_fixture_1')->refundedAmount);
        $raw['tip_money'] = ['amount' => 0, 'currency' => 'USD'];
        $raw['created_at'] = '2030-02-01T16:00:00.123Z';
        $this->assertSame('123000', SquarePaymentFacts::from($raw, 'pay_fixture_1')->receivedAt->format('u'));
        $raw['created_at'] = '2030-02-01T16:00:00-05:00';
        $this->assertSame('-05:00', SquarePaymentFacts::from($raw, 'pay_fixture_1')->receivedAt->format('P'));
        $raw = $this->fixture();
        $raw['amount_money']['amount'] = 1;
        $this->assertSame(1, SquarePaymentFacts::from($raw, 'pay_fixture_1')->amount);
    }

    public function test_non_completed_payment_does_not_record_money(): void
    {
        foreach (['APPROVED', 'PENDING', 'CANCELED', 'FAILED'] as $status) {
            $this->assertNull(SquarePaymentFacts::from(['id' => 'pay_fixture_1', 'status' => $status], 'pay_fixture_1'));
        }
    }

    public function test_invalid_or_ambiguous_money_is_refused(): void
    {
        foreach ([
            ['id', 'pay_fixture_other'], ['id', null], ['status', 'UNKNOWN'], ['status', null],
            ['order_id', null], ['order_id', 'bad/id'], ['location_id', null], ['location_id', 'bad/id'],
            ['amount_money', null], ['amount_money', 'invalid'],
            ['amount_money', ['amount' => '1200', 'currency' => 'USD']],
            ['amount_money', ['amount' => 0, 'currency' => 'USD']],
            ['amount_money', ['amount' => -1, 'currency' => 'USD']],
            ['amount_money', ['amount' => 1200, 'currency' => null]],
            ['amount_money', ['amount' => 1200, 'currency' => 'usd']],
            ['amount_money', ['amount' => 1200, 'currency' => 'US']],
            ['amount_money', ['amount' => 1200, 'currency' => 'xUSD']],
            ['amount_money', ['amount' => 1200, 'currency' => 'USDx']],
            ['amount_money', ['amount' => 1200, 'currency' => "USD\n"]],
            ['refunded_money', ['amount' => 1201, 'currency' => 'USD']],
            ['refunded_money', ['amount' => -1, 'currency' => 'USD']],
            ['refunded_money', ['amount' => 300, 'currency' => 'EUR']],
            ['tip_money', ['amount' => 1, 'currency' => 'USD']],
            ['created_at', null], ['created_at', 'tomorrow'], ['created_at', '2030-02-30T16:00:00Z'],
            ['created_at', '2030-02-01T99:00:00Z'],
            ['created_at', '2030-02-01T16:00:00 UTC'],
            ['created_at', ' 2030-02-01T16:00:00Z'],
            ['created_at', "2030-02-01T16:00:00Z\n"],
        ] as [$field, $value]) {
            $raw = $this->fixture();
            $raw[$field] = $value;
            try {
                SquarePaymentFacts::from($raw, 'pay_fixture_1');
                $this->fail('Invalid payment facts were accepted: '.$field);
            } catch (SquareRequestFailed $exception) {
                $this->assertSame(502, $exception->getStatusCode());
            }
        }
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return ['id' => 'pay_fixture_1', 'status' => 'COMPLETED', 'order_id' => 'order_fixture_1', 'location_id' => 'LOCFIXTURE01', 'amount_money' => ['amount' => 1200, 'currency' => 'USD'], 'created_at' => '2030-02-01T16:00:00Z'];
    }
}
