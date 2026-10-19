<?php

namespace Tests\Unit\Square;

use App\Services\Square\SquareRequestFailed;
use App\Support\Square\SquarePaymentFacts;
use PHPUnit\Framework\TestCase;

final class SquarePaymentFactsTest extends TestCase
{
    public function test_received_date_is_capture_time_and_missing_completion_fails_closed(): void
    {
        $raw = $this->fixture();
        $raw['created_at'] = '2030-01-31T23:00:00Z';
        $raw['card_details'] = ['card_payment_timeline' => ['captured_at' => '2030-02-01T01:00:00Z']];
        $this->assertSame('2030-02-01', SquarePaymentFacts::from($raw, 'pay_fixture_1')->receivedAt->toDateString());
        unset($raw['card_details']);
        $raw['source_type'] = 'BANK_ACCOUNT';
        $this->expectException(SquareRequestFailed::class);
        SquarePaymentFacts::from($raw, 'pay_fixture_1');
    }

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
        $raw['card_details']['card_payment_timeline']['captured_at'] = '2030-02-01T16:00:00.123Z';
        $this->assertSame('123000', SquarePaymentFacts::from($raw, 'pay_fixture_1')->receivedAt->format('u'));
        $raw['card_details']['card_payment_timeline']['captured_at'] = '2030-02-01T16:00:00-05:00';
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

    public function test_capture_metadata_must_belong_to_a_card_payment_and_be_an_object_shape(): void
    {
        foreach ([['source_type', 'BANK_ACCOUNT'], ['source_type', null], ['card_details', 'invalid'], ['card_details', new \ArrayObject(['card_payment_timeline' => ['captured_at' => '2030-02-01T16:00:00Z']])], ['card_details', ['card_payment_timeline' => new \ArrayObject(['captured_at' => '2030-02-01T16:00:00Z'])]]] as [$field, $value]) {
            $raw = $this->fixture();
            $raw[$field] = $value;
            try {
                SquarePaymentFacts::from($raw, 'pay_fixture_1');
                $this->fail('Invalid capture metadata was accepted.');
            } catch (SquareRequestFailed $exception) {
                $this->assertSame(502, $exception->getStatusCode());
            }
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
            ['captured_at', null], ['captured_at', 'tomorrow'], ['captured_at', '2030-02-30T16:00:00Z'],
            ['captured_at', '2030-02-01T99:00:00Z'],
            ['captured_at', '2030-02-01T16:00:00 UTC'],
            ['captured_at', ' 2030-02-01T16:00:00Z'],
            ['captured_at', "2030-02-01T16:00:00Z\n"],
        ] as [$field, $value]) {
            $raw = $this->fixture();
            if ($field === 'captured_at') {
                $raw['card_details']['card_payment_timeline']['captured_at'] = $value;
            } else {
                $raw[$field] = $value;
            }
            try {
                SquarePaymentFacts::from($raw, 'pay_fixture_1');
                $this->fail('Invalid payment facts were accepted: '.$field);
            } catch (SquareRequestFailed $exception) {
                $this->assertSame(502, $exception->getStatusCode());
            }
        }
    }

    /** @return array<string, mixed> */
    /** S7: Square's processing fees so far, summed; unknown rather than refused when unreadable. */
    public function test_the_processing_fee_is_summed_and_unknown_when_it_cannot_be_read(): void
    {
        $raw = $this->fixture();
        $this->assertNull(SquarePaymentFacts::from($raw, 'pay_fixture_1')->processingFee);
        $raw['processing_fee'] = [];
        $this->assertNull(SquarePaymentFacts::from($raw, 'pay_fixture_1')->processingFee);
        $raw['processing_fee'] = [
            ['type' => 'INITIAL', 'amount_money' => ['amount' => 65, 'currency' => 'USD']],
            ['type' => 'ADJUSTMENT', 'amount_money' => ['amount' => -5, 'currency' => 'USD']],
        ];
        $this->assertSame(60, SquarePaymentFacts::from($raw, 'pay_fixture_1')->processingFee);
        foreach ([
            [['amount_money' => ['amount' => 65, 'currency' => 'EUR']]],
            [['amount_money' => ['amount' => '65', 'currency' => 'USD']]],
            [['type' => 'INITIAL']],
            ['initial' => ['amount_money' => ['amount' => 65, 'currency' => 'USD']]],
            'INITIAL',
        ] as $unreadable) {
            $raw['processing_fee'] = $unreadable;
            $facts = SquarePaymentFacts::from($raw, 'pay_fixture_1');
            $this->assertNull($facts->processingFee, (string) json_encode($unreadable));
            // The payment itself is still read.
            $this->assertSame(1200, $facts->amount);
        }
    }

    private function fixture(): array
    {
        return ['id' => 'pay_fixture_1', 'status' => 'COMPLETED', 'order_id' => 'order_fixture_1', 'location_id' => 'LOCFIXTURE01', 'amount_money' => ['amount' => 1200, 'currency' => 'USD'], 'created_at' => '2030-02-01T16:00:00Z', 'source_type' => 'CARD', 'card_details' => ['card_payment_timeline' => ['captured_at' => '2030-02-01T16:00:00Z']]];
    }
}
