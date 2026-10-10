<?php

namespace Tests\Unit\Access;

use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\AccessRefusal;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\PortalAccessState;
use App\Support\Access\ProjectAssignment;
use App\Support\AgentApi\ProjectRole;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessMutationContextTest extends TestCase
{
    public function test_a_user_context_carries_its_actor_and_both_ids_separately(): void
    {
        $user = new User;
        $user->id = 7;
        $operation = str_repeat('a', 32);
        $request = 'req_12345';

        $context = AccessMutationContext::user($user, $operation, $request);

        $this->assertFalse($context->isSystem());
        $this->assertSame(7, $context->actorId());
        $this->assertSame($user, $context->actor);
        $this->assertSame($operation, $context->operationId);
        $this->assertSame($request, $context->requestId);
    }

    public function test_the_system_context_has_no_actor(): void
    {
        $context = AccessMutationContext::system(str_repeat('b', 64), 'request-1');

        $this->assertTrue($context->isSystem());
        $this->assertNull($context->actorId());
        $this->assertNull($context->actor);
        $this->assertSame(str_repeat('b', 64), $context->operationId);
        $this->assertSame('request-1', $context->requestId);
        $this->assertNull(AccessMutationContext::system()->operationId);
        $this->assertNull(AccessMutationContext::system()->requestId);
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedOperationIds(): iterable
    {
        yield 'shortest' => [str_repeat('a', 32)];
        yield 'longest' => [str_repeat('Z', 64)];
        yield 'every permitted punctuation' => ['op.2026:access_change-'.str_repeat('9', 10)];
    }

    #[DataProvider('acceptedOperationIds')]
    public function test_operation_ids_of_32_to_64_safe_characters_are_accepted(string $operationId): void
    {
        $this->assertSame($operationId, AccessMutationContext::system($operationId)->operationId);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedOperationIds(): iterable
    {
        yield 'one short' => [str_repeat('a', 31)];
        yield 'one long' => [str_repeat('a', 65)];
        yield 'a space' => [str_repeat('a', 31).' '];
        yield 'an email-like value' => [str_repeat('a', 30).'@example.test'];
        yield 'a trailing newline' => [str_repeat('a', 32)."\n"];
    }

    #[DataProvider('refusedOperationIds')]
    public function test_other_operation_ids_are_refused(string $operationId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An operation id must be 32 to 64');

        AccessMutationContext::system($operationId);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function requestIds(): iterable
    {
        yield 'shortest' => [str_repeat('r', 8), true];
        yield 'longest' => [str_repeat('r', 128), true];
        yield 'hyphen and underscore' => ['req_1-abc', true];
        yield 'one short' => [str_repeat('r', 7), false];
        yield 'one long' => [str_repeat('r', 129), false];
        yield 'a dot' => ['request.1', false];
        yield 'a trailing newline' => ["request-1\n", false];
    }

    #[DataProvider('requestIds')]
    public function test_request_ids_are_opaque_tokens_of_8_to_128_characters(string $requestId, bool $accepted): void
    {
        if (! $accepted) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('A request id must be 8 to 128');
        }

        $this->assertSame($requestId, AccessMutationContext::system(null, $requestId)->requestId);
    }

    public function test_portal_scopes_are_the_values_memberships_store(): void
    {
        $this->assertSame(ClientCompanyMembership::SCOPE_COMPANY, PortalAccessScope::Company->value);
        $this->assertSame(ClientCompanyMembership::SCOPE_PROJECTS, PortalAccessScope::Projects->value);
    }

    public function test_access_values_serialise_to_ids_roles_and_scopes_only(): void
    {
        $this->assertSame(
            ['project' => 'project-public-id', 'role' => 'viewer'],
            (new ProjectAssignment('project-public-id', ProjectRole::Viewer))->toArray(),
        );
        $this->assertSame(
            ['scope' => 'projects', 'projects' => ['a', 'b']],
            (new PortalAccessState(PortalAccessScope::Projects, ['a', 'b']))->toArray(),
        );
    }

    public function test_every_refusal_has_its_own_message(): void
    {
        $messages = array_map(static fn (AccessRefusal $refusal): string => $refusal->message(), AccessRefusal::cases());

        $this->assertSame($messages, array_values(array_unique($messages)));
        $this->assertNotContains('', $messages);
        $this->assertSame('One or more projects do not belong to that client.', AccessRefusal::ProjectOutsideCompany->message());
    }
}
