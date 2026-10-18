<?php

namespace Tests\Unit\Square;

use App\Services\Square\Mirror\SquareSnapshotMapper;
use App\Services\Square\SquarePresenter;
use PHPUnit\Framework\TestCase;

/**
 * Which of an order's tenders the mirror records as payments (#438), over a
 * synthetic order: the billing audit reports any it cannot match.
 */
final class SquareSnapshotPaymentIdsTest extends TestCase
{
    public function test_a_tender_names_its_payment_by_payment_id_else_by_its_own_id_and_voided_or_failed_tenders_took_nothing(): void
    {
        $order = ['id' => 'order_fixture_1', 'tenders' => [
            ['id' => 'tender_fixture_1', 'payment_id' => 'pay_fixture_1', 'card_details' => ['status' => 'CAPTURED']],
            ['id' => 'pay_fixture_2', 'type' => 'CARD'],
            ['id' => 'tender_fixture_3', 'payment_id' => 'pay_fixture_3', 'card_details' => ['status' => 'VOIDED']],
            ['id' => 'tender_fixture_4', 'payment_id' => 'pay_fixture_4', 'card_details' => ['status' => 'FAILED']],
            ['id' => 'tender_fixture_5', 'payment_id' => 'pay_fixture_5', 'card_details' => ['status' => 'AUTHORIZED']],
            ['id' => 'tender_fixture_1', 'payment_id' => 'pay_fixture_1'],
            ['id' => 'bad id with spaces'],
            'not a tender',
        ]];

        $this->assertSame(['pay_fixture_1', 'pay_fixture_2', 'pay_fixture_5'], (new SquareSnapshotMapper(new SquarePresenter))->paymentIds($order));
    }

    public function test_tenders_past_the_presenters_cap_are_not_read(): void
    {
        $tenders = [];
        for ($index = 1; $index <= 51; $index++) {
            $tenders[] = ['id' => 'tender_'.$index, 'payment_id' => 'pay_'.$index];
        }

        $ids = (new SquareSnapshotMapper(new SquarePresenter))->paymentIds(['tenders' => $tenders]);

        $this->assertCount(50, $ids);
        $this->assertSame('pay_50', $ids[49]);
        $this->assertSame([], (new SquareSnapshotMapper(new SquarePresenter))->paymentIds(['tenders' => 'none']));
    }

    public function test_payment_ids_are_current_for_the_status_paid_amount_and_tender_rules_they_were_read_for(): void
    {
        $basis = SquareSnapshotMapper::paymentIdsBasis('PAID', 12_345);

        $this->assertSame('PAID|12345|t2', $basis);
        $this->assertSame('UNPAID||t2', SquareSnapshotMapper::paymentIdsBasis('UNPAID', null));
        $this->assertTrue(SquareSnapshotMapper::paymentIdsCurrent('PAID', 12_345, 'order_fixture_1', $basis));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 12_345, 'order_fixture_1', 'PAID|12345'), 'Read by older tender rules.');
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 12_345, 'order_fixture_1', null));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PARTIALLY_REFUNDED', 12_345, 'order_fixture_1', $basis));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 1, 'order_fixture_1', $basis));
        $this->assertTrue(SquareSnapshotMapper::paymentIdsCurrent('PAID', 12_345, null, null), 'No order to read.');
        $this->assertTrue(SquareSnapshotMapper::paymentIdsCurrent('UNPAID', 0, 'order_fixture_1', null), 'Nothing paid.');
        $this->assertTrue(SquareSnapshotMapper::paymentIdsCurrent('UNPAID', null, 'order_fixture_1', null));
        $this->assertFalse(SquareSnapshotMapper::paymentIdsCurrent('PAID', 1, 'order_fixture_1', null), 'One minor unit paid is a payment.');
    }
}
