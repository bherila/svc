<?php

namespace Tests\Unit\Square;

use App\Services\Square\Mirror\SquareSnapshotMapper;
use App\Services\Square\SquarePresenter;
use App\Services\Square\SquareRequestFailed;
use PHPUnit\Framework\TestCase;

/**
 * Which of an order's tenders the mirror records as payments (#438), over a
 * synthetic order: the billing audit reports any it cannot match.
 */
final class SquareSnapshotPaymentIdsTest extends TestCase
{
    public function test_a_tender_names_its_payment_by_payment_id_else_by_its_own_id_and_only_captured_card_tenders_count(): void
    {
        $order = ['id' => 'order_fixture_1', 'tenders' => [
            ['id' => 'tender_fixture_1', 'payment_id' => 'pay_fixture_1', 'card_details' => ['status' => 'CAPTURED']],
            ['id' => 'pay_fixture_2', 'type' => 'CARD'],
            ['id' => 'tender_fixture_3', 'payment_id' => 'pay_fixture_3', 'card_details' => ['status' => 'VOIDED']],
            ['id' => 'tender_fixture_4', 'payment_id' => 'pay_fixture_4', 'card_details' => ['status' => 'FAILED']],
            ['id' => 'tender_fixture_5', 'payment_id' => 'pay_fixture_5', 'card_details' => ['status' => 'AUTHORIZED']],
            ['id' => 'tender_fixture_1', 'payment_id' => 'pay_fixture_1'],
            ['id' => 'tender_fixture_6', 'payment_id' => 'pay_fixture_6', 'card_details' => ['entry_method' => 'ON_FILE']],
            ['id' => 'tender_fixture_7', 'payment_id' => 'pay_fixture_7', 'card_details' => 'unreadable'],
        ]];

        $this->assertSame(['pay_fixture_1', 'pay_fixture_2', 'pay_fixture_6', 'pay_fixture_7'], (new SquareSnapshotMapper(new SquarePresenter))->paymentIds($order));
    }

    public function test_an_open_authorization_with_no_known_paid_amount_stays_unresolved(): void
    {
        $order = ['tenders' => [
            ['id' => 'tender_1', 'payment_id' => 'pay_captured', 'card_details' => ['status' => 'CAPTURED']],
            ['id' => 'tender_2', 'payment_id' => 'pay_authorized', 'card_details' => ['status' => 'AUTHORIZED']],
        ]];
        $mapper = new SquareSnapshotMapper(new SquarePresenter);

        $this->assertSame(['pay_captured'], $mapper->paymentIds($order), 'A known amount shows the capture when it comes.');
        $this->assertNull($mapper->paymentIds($order, amountKnown: false));
        $this->assertTrue($mapper->holdsAuthorization($order));
        $this->assertFalse($mapper->holdsAuthorization(['tenders' => 'none']));
        $this->assertFalse($mapper->holdsAuthorization([]));
        $order['tenders'][1]['card_details']['status'] = 'VOIDED';
        $this->assertFalse($mapper->holdsAuthorization($order));
        $this->assertSame(['pay_captured'], $mapper->paymentIds($order, amountKnown: false), 'A voided authorization is final.');
    }

    public function test_every_status_bearing_tender_type_counts_once_settled(): void
    {
        $order = ['tenders' => [
            ['id' => 'pay_cash', 'type' => 'CASH', 'cash_details' => ['buyer_tendered_money' => ['amount' => 100]]],
            ['id' => 'pay_bank_done', 'bank_account_details' => ['status' => 'COMPLETED']],
            ['id' => 'pay_bank_failed', 'bank_account_details' => ['status' => 'FAILED']],
            ['id' => 'pay_bnpl_voided', 'buy_now_pay_later_details' => ['status' => 'VOIDED']],
            ['id' => 'pay_account_captured', 'square_account_details' => ['status' => 'CAPTURED']],
        ]];
        $mapper = new SquareSnapshotMapper(new SquarePresenter);

        $this->assertSame(['pay_cash', 'pay_bank_done', 'pay_account_captured'], $mapper->paymentIds($order, amountKnown: false));
        $this->assertFalse($mapper->holdsAuthorization($order));

        $order['tenders'][] = ['id' => 'pay_bank_pending', 'bank_account_details' => ['status' => 'PENDING']];
        $this->assertTrue($mapper->holdsAuthorization($order));
        $this->assertNull($mapper->paymentIds($order, amountKnown: false));
        $this->assertSame(['pay_cash', 'pay_bank_done', 'pay_account_captured'], $mapper->paymentIds($order));
    }

    public function test_an_order_with_more_tenders_than_the_reconciler_accepts_stays_unresolved(): void
    {
        $tenders = [];
        for ($index = 1; $index <= 100; $index++) {
            $tenders[] = ['id' => 'tender_'.$index, 'payment_id' => 'pay_'.$index];
        }
        $mapper = new SquareSnapshotMapper(new SquarePresenter);

        $ids = $mapper->paymentIds(['tenders' => $tenders]);
        $this->assertNotNull($ids);
        $this->assertCount(100, $ids);
        $this->assertSame('pay_100', $ids[99]);
        $this->assertFalse($mapper->exceedsTenderBound(['tenders' => $tenders]));
        $tenders[] = ['id' => 'tender_101', 'payment_id' => 'pay_101'];
        $this->assertNull($mapper->paymentIds(['tenders' => $tenders]));
        $this->assertTrue($mapper->exceedsTenderBound(['tenders' => $tenders]));
        $this->assertFalse($mapper->exceedsTenderBound(['tenders' => 'none']));
        $this->assertSame([], $mapper->paymentIds([]), 'An order with no tenders has no payments.');
        foreach (['none', ['first' => ['id' => 'pay_1']], ['not a tender'], [['id' => 'bad id with spaces']], [['type' => 'CARD']], [['id' => 'pay_1'], 'not a tender']] as $malformed) {
            try {
                $mapper->paymentIds(['tenders' => $malformed]);
                $this->fail('A malformed tender list must not resolve.');
            } catch (SquareRequestFailed $failure) {
                $this->assertSame(502, $failure->getStatusCode());
            }
        }
    }

    public function test_payment_ids_are_current_for_the_status_paid_amount_and_tender_rules_they_were_read_for(): void
    {
        $basis = SquareSnapshotMapper::paymentIdsBasis('PAID', 12_345);

        $this->assertSame('PAID|12345|t2', $basis);
        $this->assertSame('PAID||t2|p', SquareSnapshotMapper::pendingBasis('PAID', null));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', null, 'order_fixture_1', 'PAID||t2|p'), 'Pending is not current.');
        $this->assertTrue(SquareSnapshotMapper::mayHoldPayment('PAID', 1));
        $this->assertFalse(SquareSnapshotMapper::mayHoldPayment('PAID', 0));
        $this->assertTrue(SquareSnapshotMapper::mayHoldPayment('PARTIALLY_REFUNDED', null));
        $this->assertFalse(SquareSnapshotMapper::mayHoldPayment('CANCELED', null));
        $this->assertSame('UNPAID||t2', SquareSnapshotMapper::paymentIdsBasis('UNPAID', null));
        $this->assertTrue(SquareSnapshotMapper::paymentIdsCurrent('PAID', 12_345, 'order_fixture_1', $basis));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 12_345, 'order_fixture_1', 'PAID|12345'), 'Read by older tender rules.');
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 12_345, 'order_fixture_1', null));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PARTIALLY_REFUNDED', 12_345, 'order_fixture_1', $basis));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 1, 'order_fixture_1', $basis));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 12_345, null, null), 'Paid, and no order to read the payments from.');
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 12_345, null, $basis));
        $this->assertTrue(SquareSnapshotMapper::paymentIdsCurrent('UNPAID', 0, null, null), 'Nothing paid, and no order.');
        $this->assertTrue(SquareSnapshotMapper::paymentIdsCurrent('UNPAID', 0, 'order_fixture_1', null), 'Nothing paid.');
        $this->assertTrue(SquareSnapshotMapper::paymentIdsCurrent('UNPAID', null, 'order_fixture_1', null), 'Unknown amount, and no payment to hold.');
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', null, 'order_fixture_1', null), 'Unknown amount on a paid invoice is read.');
        $this->assertTrue(SquareSnapshotMapper::paymentIdsCurrent('PAID', null, 'order_fixture_1', 'PAID||t2'));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 1, 'order_fixture_1', null), 'One minor unit paid is a payment.');
    }
}
