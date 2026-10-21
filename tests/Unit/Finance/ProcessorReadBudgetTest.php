<?php

namespace Tests\Unit\Finance;

use App\Support\Finance\ProcessorReadBudget;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ProcessorReadBudgetTest extends TestCase
{
    public function test_every_read_shares_one_limit_and_refusal_does_not_reset_it(): void
    {
        $budget = new ProcessorReadBudget(2);
        $budget->consume();
        $budget->consume();
        for ($retry = 0; $retry < 2; $retry++) {
            try {
                $budget->consume();
                $this->fail('A spent read budget must refuse another call.');
            } catch (DomainException $failure) {
                $this->assertSame('The processor read budget is exhausted; continue the sync in another run.', $failure->getMessage());
                $this->assertNull($failure->getPrevious());
            }
        }
        $this->assertSame(2, $budget->used());
    }

    public function test_an_expired_run_refuses_before_consuming_another_call(): void
    {
        $budget = new ProcessorReadBudget(2, maxSeconds: 0);
        try {
            $budget->consume();
            $this->fail('An elapsed run must not begin another provider request.');
        } catch (DomainException $failure) {
            $this->assertSame('The processor read budget is exhausted; continue the sync in another run.', $failure->getMessage());
            $this->assertSame(0, $budget->used());
        }
    }

    public function test_refused_is_set_only_by_an_actual_consume_denial(): void
    {
        $budget = new ProcessorReadBudget(1);
        $this->assertFalse($budget->refused());
        $budget->consume();
        $this->assertFalse($budget->refused());
        try {
            $budget->consume();
        } catch (DomainException) {
            $this->assertTrue($budget->refused());
        }
        $expired = new ProcessorReadBudget(1, maxSeconds: 0);
        $this->assertFalse($expired->refused());
        try {
            $expired->consume();
        } catch (DomainException) {
            $this->assertTrue($expired->refused());
        }
    }

    public function test_a_zero_or_excessive_limit_is_refused(): void
    {
        foreach ([0, -1, 1001] as $limit) {
            try {
                new ProcessorReadBudget($limit);
                $this->fail('An invalid budget was accepted.');
            } catch (DomainException $failure) {
                $this->assertSame('The processor read budget must be between 1 and 1000 calls.', $failure->getMessage());
            }
        }
    }
}
