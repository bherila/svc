<?php

namespace Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\PHPStan\DisallowUnscopedTenantLookupRule;

/** @extends RuleTestCase<DisallowUnscopedTenantLookupRule> */
final class DisallowUnscopedTenantLookupRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DisallowUnscopedTenantLookupRule(self::createReflectionProvider());
    }

    /**
     * The refused forms and the allowed ones, in one fixture.
     *
     * Both halves are asserted because the allowed shapes are what decide
     * whether the rule survives contact with the codebase: one that flagged
     * `$workspace->clientCompanies()->find()` would be switched off rather than
     * obeyed. The expectation list being exactly three long is the assertion
     * that the other four call sites pass.
     */
    public function test_it_rejects_key_lookups_that_carry_no_workspace(): void
    {
        $message = static fn (string $model, string $method): string => "{$model}::{$method}() looks a row up by key alone, so it reaches every workspace; "
            .'start from a query that already names the workspace - the workspace relation, or '
            ."where('workspace_id', ...) - and resolve the key inside it.";

        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/tenant/Lookups.php'], [
            [$message('ClientInvoice', 'find'), 24],
            [$message('ClientInvoice', 'findOrFail'), 29],
            [$message('ClientCompany', 'whereKey'), 34],
        ]);
    }
}
