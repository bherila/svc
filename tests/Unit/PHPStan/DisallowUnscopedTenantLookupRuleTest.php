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
     * obeyed. The expectation list being exactly seven long is the assertion
     * that the allowed call sites pass - including
     * `WorkspaceInvoiceCounter::find($workspace->id)`, whose primary key is the
     * tenant column, so the bare key names a workspace by construction.
     *
     * `destroy()` carries its own wording. It is not a lookup, and a message
     * that called an unscoped cross-tenant delete a lookup would understate it.
     */
    public function test_it_rejects_key_lookups_that_carry_no_workspace(): void
    {
        $message = static fn (string $model, string $method, string $verb): string => "{$model}::{$method}() {$verb}; "
            .'start from a query that already names the workspace - the workspace relation, or '
            ."where('workspace_id', ...) - and resolve the key inside it.";
        $read = 'looks a row up by key alone, so it reaches every workspace';
        $write = 'deletes rows by key alone, so it can delete from any workspace';

        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/tenant/Lookups.php'], [
            [$message('ClientInvoice', 'find', $read), 28],
            [$message('ClientInvoice', 'findOrFail', $read), 33],
            [$message('ExternalImportRun', 'find', $read), 39],
            [$message('WorkspaceMembership', 'find', $read), 44],
            [$message('ClientProjectMembership', 'find', $read), 49],
            [$message('TenantInvoiceSubclass', 'find', $read), 55],
            [$message('ClientInvoice', 'destroy', $write), 61],
        ]);
    }
}
