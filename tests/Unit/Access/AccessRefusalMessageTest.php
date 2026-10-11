<?php

namespace Tests\Unit\Access;

use App\Exceptions\AccessMutationRefused;
use App\Support\Access\AccessRefusal;
use PHPUnit\Framework\TestCase;

final class AccessRefusalMessageTest extends TestCase
{
    public function test_existing_portal_access_directs_the_caller_to_change_its_scope(): void
    {
        $exception = $this->exceptionFor(AccessRefusal::AlreadyPortalMember);
        $this->assertSame(AccessRefusal::AlreadyPortalMember, $exception->reason);
        $this->assertStringContainsString('change its scope', $exception->getMessage());
    }

    public function test_missing_portal_access_explains_that_there_is_no_membership(): void
    {
        $exception = $this->exceptionFor(AccessRefusal::NotPortalMember);
        $this->assertSame(AccessRefusal::NotPortalMember, $exception->reason);
        $this->assertStringContainsString('no portal access', $exception->getMessage());
    }

    public function test_a_stale_confirmation_directs_the_caller_to_read_again(): void
    {
        $exception = $this->exceptionFor(AccessRefusal::PortalRevisionConflict);
        $this->assertSame(AccessRefusal::PortalRevisionConflict, $exception->reason);
        $this->assertStringContainsString('Read it again before confirming', $exception->getMessage());
    }

    private function exceptionFor(AccessRefusal $reason): AccessMutationRefused
    {
        try {
            return new AccessMutationRefused($reason);
        } catch (\UnhandledMatchError) {
            $this->fail('The refusal '.$reason->value.' must construct a safe caller-facing exception.');
        }
    }
}
