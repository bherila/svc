<?php

namespace Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\PHPStan\DisallowStatusNegationRule;

/** @extends RuleTestCase<DisallowStatusNegationRule> */
final class DisallowStatusNegationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DisallowStatusNegationRule;
    }

    public function test_it_rejects_fail_open_status_queries_only_in_billing_paths(): void
    {
        $message = static fn (string $method): string => "Calling {$method}() on a status column is forbidden; enumerate allowed statuses explicitly with whereIn() instead.";

        $this->analyse([
            __DIR__.'/../../Fixtures/PHPStan/app/Services/Billing/Query.php',
            __DIR__.'/../../Fixtures/PHPStan/app/Services/Billing/StaticQuery.php',
            __DIR__.'/../../Fixtures/PHPStan/app/Services/Engagement/Query.php',
        ], [
            [$message('whereNotIn'), 42],
            [$message('orWhereNotIn'), 43],
            [$message('where'), 44],
            [$message('orWhere'), 45],
            [$message('where'), 46],
            [$message('where'), 47],
            [$message('where'), 49],
            [$message('orWhere'), 52],
            [$message('whereRaw'), 53],
            [$message('orWhereRaw'), 54],
            [$message('where'), 12],
        ]);
    }
}
