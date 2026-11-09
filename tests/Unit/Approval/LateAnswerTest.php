<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\LateAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LateAnswerTest extends TestCase
{
    /** @return iterable<string, array{string|null, bool, bool, bool}> */
    public static function cases(): iterable
    {
        yield 'consultant time, period reconciled' => [null, true, false, true];
        yield 'retainer subcontractor time, period reconciled' => ['retainer', true, false, true];
        yield 'already charged consultant time keeps its charge' => [null, true, true, false];
        yield 'already charged retainer time keeps its charge' => ['retainer', true, true, false];
        yield 'flat-hourly time is carried, not deferred' => ['flat_hourly', true, false, false];
        yield 'direct billing never reaches the client' => ['direct', true, false, false];
        yield 'an unknown mode is left alone' => ['synthetic-unknown', true, false, false];
        yield 'consultant time, period still open' => [null, false, false, false];
        yield 'retainer subcontractor time, period still open' => ['retainer', false, false, false];
    }

    #[DataProvider('cases')]
    public function test_only_held_ledger_work_whose_period_was_reconciled_is_deferred(?string $mode, bool $periodCharged, bool $charged, bool $defers): void
    {
        $this->assertSame($defers, LateAnswer::defers($mode, $periodCharged, $charged));
    }
}
