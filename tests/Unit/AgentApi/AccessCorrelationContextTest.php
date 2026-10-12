<?php

namespace Tests\Unit\AgentApi;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Support\AgentApi\AgentMutationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessCorrelationContextTest extends TestCase
{
    public static function requestIds(): iterable
    {
        yield 'absent' => [null, false];
        yield 'short' => [str_repeat('a', 7), false];
        yield 'minimum' => [str_repeat('a', 8), true];
        yield 'maximum' => [str_repeat('a', 128), true];
        yield 'long' => [str_repeat('a', 129), false];
        yield 'invalid character' => ['synthetic.request', false];
        yield 'trailing newline' => ["synthetic_request\n", false];
    }

    #[DataProvider('requestIds')]
    public function test_only_opaque_request_ids_are_carried_or_a_new_uuid_is_used(?string $id, bool $accepted): void
    {
        $user = (new User)->forceFill(['id' => 1, 'public_id' => 'synthetic-user']);
        $workspace = (new Workspace)->forceFill(['public_id' => 'synthetic-workspace']);
        $request = Request::create('/synthetic', 'PUT');
        if ($id !== null) {
            $request->headers->set('X-Request-Id', $id);
        }
        $mutation = new AgentMutationContext($user, 'synthetic-client', 'synthetic-key');
        $context = (new AgentMutationContextFactory)->forAccess($request, $workspace, $mutation, 'projects.members.update');
        $this->assertSame($user->id, $context->actorId());
        $this->assertSame(hash('sha256', 'synthetic-workspace|synthetic-user|synthetic-client|projects.members.update|synthetic-key'), $context->operationId);
        $accepted ? $this->assertSame($id, $context->requestId) : $this->assertTrue(Str::isUuid($context->requestId));
    }

    public function test_each_logical_mutation_namespace_part_changes_the_operation_id_but_a_retry_does_not(): void
    {
        $user = (new User)->forceFill(['id' => 1, 'public_id' => 'synthetic-user']);
        $workspace = (new Workspace)->forceFill(['public_id' => 'synthetic-workspace']);
        $request = Request::create('/synthetic', 'PUT');
        $factory = new AgentMutationContextFactory;
        $mutation = new AgentMutationContext($user, 'synthetic-client', 'synthetic-key');
        $first = $factory->forAccess($request, $workspace, $mutation, 'projects.members.update');
        $retry = $factory->forAccess($request, $workspace, $mutation, 'projects.members.update');
        $this->assertSame($first->operationId, $retry->operationId);
        $this->assertNotSame($first->requestId, $retry->requestId);
        foreach ([
            [$workspace, new AgentMutationContext($user, 'another-client', 'synthetic-key'), 'projects.members.update'],
            [$workspace, new AgentMutationContext($user, 'synthetic-client', 'another-key'), 'projects.members.update'],
            [$workspace, $mutation, 'projects.update'],
            [(new Workspace)->forceFill(['public_id' => 'another-workspace']), $mutation, 'projects.members.update'],
            [$workspace, new AgentMutationContext((new User)->forceFill(['id' => 2, 'public_id' => 'another-user']), 'synthetic-client', 'synthetic-key'), 'projects.members.update'],
        ] as [$tenant, $changed, $operation]) {
            $this->assertNotSame($first->operationId, $factory->forAccess($request, $tenant, $changed, $operation)->operationId);
        }
    }
}
