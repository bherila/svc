<?php

namespace Tests\Unit\Tasks;

use App\Services\Tasks\TaskAccessFacts;
use App\Support\AgentApi\ProjectRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskAccessFacts::class)]
#[CoversClass(ProjectRole::class)]
final class TaskAccessFactsTest extends TestCase
{
    public static function roles(): iterable
    {
        yield 'owner reaches any project' => ['owner', null, true, true];
        yield 'admin reaches any project' => ['admin', null, true, true];
        yield 'manager manages and may be assigned' => ['member', ProjectRole::Manager, true, true];
        yield 'project owner manages' => ['member', ProjectRole::Owner, true, true];
        yield 'contributor responsibility grants no management' => ['member', ProjectRole::Contributor, false, true];
        yield 'viewer may be responsible without management' => ['member', ProjectRole::Viewer, false, true];
        yield 'unassigned staff have no project role' => ['member', null, false, false];
        yield 'project grant without staff membership grants nothing' => [null, ProjectRole::Manager, false, false];
        yield 'portal or contact account without staff membership' => [null, null, false, false];
    }

    #[DataProvider('roles')]
    public function test_access_and_responsibility_follow_current_internal_memberships(?string $staffRole, ?ProjectRole $projectRole, bool $manage, bool $assign): void
    {
        $facts = new TaskAccessFacts(['synthetic-account' => 17], $staffRole === null ? [] : [17 => $staffRole], $projectRole === null ? [] : [17 => $projectRole]);
        $this->assertFalse($facts->portalMember);
        $this->assertSame($manage, $facts->canManage(17));
        $this->assertSame($assign, $facts->canAssign('synthetic-account'));
        $this->assertFalse($facts->canManage(99));
        $this->assertFalse($facts->canAssign('unknown-account'));
    }

    public function test_portal_membership_is_an_independent_current_fact_for_agent_origin_checks(): void
    {
        $facts = new TaskAccessFacts(['synthetic-account' => 17], [17 => 'owner'], [], portalMember: true);
        $this->assertTrue($facts->portalMember);
        $this->assertTrue($facts->canManage(17), 'Trusted internal staff retain their native role; Agent origin separately refuses the Portal fact.');
    }
}
