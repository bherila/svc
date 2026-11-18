<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\BillingEligibilityPosition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingEligibilityPositionTest extends TestCase
{
    /** @return iterable<string, array{int, int|null, int, int|null, bool}> */
    public static function positions(): iterable
    {
        yield 'lower allocated ID committed later' => [1, 20, 2, 10, true];
        yield 'higher allocated ID committed earlier' => [2, 10, 1, 20, false];
        yield 'same committed event' => [1, 10, 1, 10, false];
        yield 'different IDs in same committed position' => [2, 10, 1, 10, false];
        yield 'pending entry after committed issue' => [1, null, 2, 10, true];
        yield 'committed entry before pending issue' => [2, 10, 1, null, false];
        yield 'pending operations in local order' => [2, null, 1, null, true];
        yield 'pending operations in reverse local order' => [1, null, 2, null, false];
        yield 'same pending event' => [1, null, 1, null, false];
    }

    #[DataProvider('positions')]
    public function test_orders_by_commit_and_preserves_local_transaction_order(int $entryId, ?int $entrySequence, int $issueId, ?int $issueSequence, bool $late): void
    {
        self::assertSame($late, (new BillingEligibilityPosition($entryId, $entrySequence))->after(new BillingEligibilityPosition($issueId, $issueSequence)));
    }
}
