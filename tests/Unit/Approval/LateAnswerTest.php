<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\LateAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LateAnswerTest extends TestCase
{
    /** @return iterable<string, array{string|null, bool, bool}> */
    public static function cases(): iterable
    {
        yield 'consultant time, period reconciled' => [null, true, true];
        yield 'retainer subcontractor time, period reconciled' => ['retainer', true, true];
        yield 'flat-hourly time is carried, not deferred' => ['flat_hourly', true, false];
        yield 'direct billing never reaches the client' => ['direct', true, false];
        yield 'an unknown mode is left alone' => ['synthetic-unknown', true, false];
        yield 'consultant time, period still open' => [null, false, false];
        yield 'retainer subcontractor time, period still open' => ['retainer', false, false];
    }

    #[DataProvider('cases')]
    public function test_only_ledger_work_whose_period_was_reconciled_is_deferred(?string $mode, bool $periodCharged, bool $defers): void
    {
        $this->assertSame($defers, LateAnswer::defers($mode, $periodCharged));
    }
}
