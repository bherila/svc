<?php

namespace Tests\Feature\Billing;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Billing\BillingHistorySequenceCase;

final class BillingHistorySequenceRetainerMonthlyTest extends BillingHistorySequenceCase
{
    protected const string WORLD = 'retainer, monthly';

    #[DataProvider('histories')]
    public function test_a_generated_history_keeps_its_invariants(string $world, int $seed): void
    {
        $this->runHistory($world, $seed);
    }
}
