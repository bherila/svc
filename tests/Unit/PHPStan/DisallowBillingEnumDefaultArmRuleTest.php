<?php

namespace Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\PHPStan\DisallowBillingEnumDefaultArmRule;

/** @extends RuleTestCase<DisallowBillingEnumDefaultArmRule> */
final class DisallowBillingEnumDefaultArmRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DisallowBillingEnumDefaultArmRule(
            self::createReflectionProvider(),
            ['App\Support\Billing\\', 'Tests\Fixtures\PHPStan\enums\\'],
        );
    }

    /**
     * The refused arms and the allowed matches, in one fixture.
     *
     * The exhaustive match and the match over a plain string are what make the
     * expectation list meaningful: a rule that flagged every `default` anywhere
     * would produce the same two errors plus two more, and the list being
     * exactly two long is the assertion that it does not.
     */
    public function test_it_rejects_default_arms_over_billing_enums(): void
    {
        $message = static fn (string $enum): string => "A default arm on a match over {$enum} silently absorbs any case added later; "
            .'name every case explicitly so a new one fails to compile instead.';

        $this->analyse([
            __DIR__.'/../../Fixtures/PHPStan/enums/FutureBillingEnum.php',
            __DIR__.'/../../Fixtures/PHPStan/enums/Matches.php',
        ], [
            [$message('BillingCadence'), 18],
            [$message('FutureBillingEnum'), 18],
            [$message('InvoiceKind'), 32],
        ]);
    }
}
