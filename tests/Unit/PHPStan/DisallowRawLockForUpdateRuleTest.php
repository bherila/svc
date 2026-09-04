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
            // The routed call on line 13 and the shared lock on line 33 are
            // absent by design: one is the replacement this rule asks for, the
            // other is a different lock with a different question behind it.
            // The helper fixture's own call is absent because its path is the
            // exemption, which is the half of the rule an assertion about the
            // flagged lines alone would not reach.
            [$message, 18],
            [$message, 23],
            [$message, 28],
        ]);
    }
}
