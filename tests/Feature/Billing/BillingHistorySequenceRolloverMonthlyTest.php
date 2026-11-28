<?php

namespace Tests\Feature\Billing;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Billing\BillingHistorySequenceCase;

final class BillingHistorySequenceRolloverMonthlyTest extends BillingHistorySequenceCase
{
    protected const string WORLD = 'retainer with rollover, monthly';

    #[DataProvider('histories')]
    public function test_a_generated_history_keeps_its_invariants(string $world, int $seed): void
    {
        $this->runHistory($world, $seed);
    }

    /** #704: January billed only the retainer before a 1110-minute row arrived. */
    public function test_late_work_after_an_empty_retainer_invoice_seed_6(): void
    {
        $this->runHistory(self::WORLD, 6);
    }
}
