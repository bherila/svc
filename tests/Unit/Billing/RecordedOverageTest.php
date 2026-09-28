<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\RecordedOverage;
use Tests\TestCase;

final class RecordedOverageTest extends TestCase
{
    public function test_termination_charges_for_deferred_work_are_not_part_of_the_recorded_figure(): void
    {
        $this->assertSame(1.75, RecordedOverage::chargedHours([
            ['hours' => 1.25, 'links_deferred' => false],
            ['hours' => 6.0, 'links_deferred' => true],
            ['hours' => 0.5, 'links_deferred' => false],
        ]));
        $this->assertSame(0.0, RecordedOverage::chargedHours([]));
    }

    public function test_the_sum_is_held_to_the_four_places_the_column_stores(): void
    {
        $this->assertSame(0.3, RecordedOverage::chargedHours([
            ['hours' => 0.1, 'links_deferred' => false],
            ['hours' => 0.2, 'links_deferred' => false],
        ]));
    }

    public function test_nothing_finer_than_the_stored_four_places_survives(): void
    {
        $this->assertSame(1.0, RecordedOverage::chargedHours([['hours' => 1.00004, 'links_deferred' => false]]));
        $this->assertSame(0.1236, RecordedOverage::chargedHours([['hours' => 0.1236, 'links_deferred' => false]]));
        $this->assertFalse(RecordedOverage::disagrees(1.0, 1.00004), 'A difference the column cannot hold is not a disagreement');
    }

    public function test_a_missing_or_different_figure_disagrees_and_an_equal_one_does_not(): void
    {
        $this->assertTrue(RecordedOverage::disagrees(null, 0.0));
        $this->assertTrue(RecordedOverage::disagrees(0.0, 9.4167));
        $this->assertTrue(RecordedOverage::disagrees(9.4166, 9.4167));
        $this->assertFalse(RecordedOverage::disagrees(9.4167, 9.4167));
        $this->assertFalse(RecordedOverage::disagrees(0.3, 0.1 + 0.2));
    }
}
