<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\CashEntries;
use App\Support\Accounting\FeedPosition;
use App\Support\Accounting\PaymentRef;
use App\Support\Billing\PaymentRefundKind;
use App\Support\Finance\ProcessorMovementKind;
use PHPUnit\Framework\TestCase;

/** The cash-income feed's entries from facts alone, in synthetic amounts. */
final class CashEntriesTest extends TestCase
{
    private function ref(): PaymentRef
    {
        return new PaymentRef('pay-1', 'inv-1', 'SYN-1', 'client-1', 'USD');
    }

    public function test_a_received_payment_is_one_entry_for_the_whole_amount(): void
    {
        $position = new FeedPosition('2030-02-05 09:00:00.000000', 1, 3);

        $entry = CashEntries::payment($position, $this->ref(), 'pay-1', 'version-hash', 20000, 5000, 610, '2030-02-04', 'card', 'stripe', 'partially_refunded');

        $this->assertSame($position, $entry->position);
        $this->assertSame([
            'id' => 'pay-1',
            'version' => 'version-hash',
            'entry_type' => 'payment',
            'entry_type_label' => 'Payment',
            'recorded_at' => '2030-02-05T09:00:00+00:00',
            'effective_on' => '2030-02-04',
            'amount_minor' => 20000,
            'fee_minor' => 610,
            'net_minor' => 19390,
            'currency' => 'USD',
            'payment_id' => 'pay-1',
            'reverses_entry_id' => null,
            'invoice_id' => 'inv-1',
            'invoice_number' => 'SYN-1',
            'client_id' => 'client-1',
            'provider' => 'stripe',
            'method' => 'card',
            'status_label' => 'Partially refunded',
            'refunded_amount_minor' => 5000,
            'needs_review' => false,
        ], $entry->payload);
    }

    public function test_a_payment_recorded_by_hand_has_no_provider_and_no_fee(): void
    {
        $payload = CashEntries::payment(new FeedPosition('2030-02-05 09:00:00.000000', 1, 3), $this->ref(), 'pay-1', 'v', 10000, 0, null, '2030-02-04', null, null, 'succeeded')->payload;

        $this->assertSame([null, null, 10000, null, null, 'Succeeded', 0], [$payload['provider'], $payload['fee_minor'], $payload['net_minor'], $payload['method'], null, $payload['status_label'], $payload['refunded_amount_minor']]);
    }

    public function test_a_refund_recorded_by_hand_is_money_out(): void
    {
        $position = new FeedPosition('2030-02-06 10:00:00.000000', 2, 8);

        $entry = CashEntries::recordedRefund($position, $this->ref(), 'refund-1', PaymentRefundKind::Refund, 2500, '2030-02-06', null);

        $this->assertSame($position, $entry->position);
        $this->assertSame([
            'id' => 'refund-1',
            'version' => null,
            'entry_type' => 'refund',
            'entry_type_label' => 'Refund',
            'recorded_at' => '2030-02-06T10:00:00+00:00',
            'effective_on' => '2030-02-06',
            'amount_minor' => -2500,
            'fee_minor' => null,
            'net_minor' => -2500,
            'currency' => 'USD',
            'payment_id' => 'pay-1',
            'reverses_entry_id' => null,
            'invoice_id' => 'inv-1',
            'invoice_number' => 'SYN-1',
            'client_id' => 'client-1',
            'provider' => null,
            'method' => null,
            'status_label' => null,
            'refunded_amount_minor' => null,
            'needs_review' => false,
        ], $entry->payload);
    }

    public function test_a_reversal_is_money_back_in_and_names_the_refund_it_answers(): void
    {
        $payload = CashEntries::recordedRefund(new FeedPosition('2030-02-07 10:00:00.000000', 2, 9), $this->ref(), 'reversal-1', PaymentRefundKind::Reversal, 1000, '2030-02-07', 'refund-1')->payload;

        $this->assertSame(['refund_reversal', 'Refund reversal', 1000, 1000, 'refund-1'], [$payload['entry_type'], $payload['entry_type_label'], $payload['amount_minor'], $payload['net_minor'], $payload['reverses_entry_id']]);
    }

    public function test_a_processor_movement_keeps_its_signs_and_its_fee(): void
    {
        $position = new FeedPosition('2030-02-08 11:00:00.123456', 3, 5);

        $entry = CashEntries::movement($position, $this->ref(), 'move-1', ProcessorMovementKind::DisputeDebit, -4000, 1500, '2030-02-08', 'stripe', true);

        $this->assertSame($position, $entry->position);
        $this->assertSame([
            'id' => 'move-1',
            'version' => null,
            'entry_type' => 'dispute_debit',
            'entry_type_label' => 'Dispute debit',
            'recorded_at' => '2030-02-08T11:00:00+00:00',
            'effective_on' => '2030-02-08',
            'amount_minor' => -4000,
            'fee_minor' => 1500,
            'net_minor' => -5500,
            'currency' => 'USD',
            'payment_id' => 'pay-1',
            'reverses_entry_id' => null,
            'invoice_id' => 'inv-1',
            'invoice_number' => 'SYN-1',
            'client_id' => 'client-1',
            'provider' => 'stripe',
            'method' => null,
            'status_label' => null,
            'refunded_amount_minor' => null,
            'needs_review' => true,
        ], $entry->payload);
    }

    public function test_a_fee_movement_moves_no_principal(): void
    {
        $payload = CashEntries::movement(new FeedPosition('2030-02-08 11:00:00.000000', 3, 6), $this->ref(), 'move-2', ProcessorMovementKind::FeeReversal, 0, -300, '2030-02-08', 'square', false)->payload;

        $this->assertSame(['fee_reversal', 0, -300, 300], [$payload['entry_type'], $payload['amount_minor'], $payload['fee_minor'], $payload['net_minor']]);
    }
}
