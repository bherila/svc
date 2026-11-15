<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\ManualClientEmailBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The budget's arithmetic, without a database: what the snapshot counted, plus
 * the admissions it could not see, plus this send, against the limit.
 */
#[CoversClass(ManualClientEmailBudget::class)]
final class ManualClientEmailBudgetFitsTest extends TestCase
{
    /** @return iterable<string, array{int, int, int, int, int, bool}> */
    public static function cases(): iterable
    {
        yield 'room left' => [1, 4, 4, 1, 3, true];
        yield 'exactly at the limit' => [2, 4, 4, 1, 3, true];
        yield 'one over the limit' => [2, 4, 4, 2, 3, false];
        // A send committed after this snapshot: its rows are not in the count,
        // but the key's total under the lock says it happened.
        yield 'an unseen admission counts' => [0, 4, 6, 2, 3, false];
        yield 'an unseen admission that still leaves room' => [0, 4, 5, 2, 3, true];
        // Never happens; if it did, it must not hand sends back.
        yield 'a reset key adds nothing' => [1, 9, 0, 2, 3, true];
    }

    #[DataProvider('cases')]
    public function test_a_send_fits_only_with_every_admission_counted(int $counted, int $seen, int $locked, int $recipients, int $limit, bool $fits): void
    {
        $this->assertSame($fits, ManualClientEmailBudget::fits($counted, $seen, $locked, $recipients, $limit));
    }
}
