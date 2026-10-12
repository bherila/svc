<?php

namespace Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\PHPStan\DisallowRawLockForUpdateRule;

/** @extends RuleTestCase<DisallowRawLockForUpdateRule> */
final class DisallowRawLockForUpdateRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DisallowRawLockForUpdateRule;
    }

    public function test_it_rejects_every_raw_lock_except_the_helpers_own(): void
    {
        $message = 'lockForUpdate() taken outside the lock-order registry; a lock nobody records is ordered against '
            .'nothing. Write ->tap(Locks::forUpdate()) in its place - it goes in the same chain and returns the '
            .'same builder - and add a LockResource case if this table has none.';

        $this->analyse([
            __DIR__.'/../../Fixtures/PHPStan/locks/app/Services/Billing/Locking.php',
            __DIR__.'/../../Fixtures/PHPStan/locks/app/Support/Concurrency/Locks.php',
        ], [
            // The routed call on line 13 is the recorded replacement.
            // The helper fixture's own call is absent because its path is the
            // exemption, which is the half of the rule an assertion about the
            // flagged lines alone would not reach.
            [$message, 18],
            [$message, 23],
            [$message, 28],
            ['sharedLock() taken outside the lock-order registry; write ->tap(Locks::forShare()) in its place.', 33],
        ]);
    }
}
