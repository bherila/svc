<?php

namespace Tests\Unit\SharedProjects;

use App\Support\Access\StaffRole;
use App\Support\AgentApi\ProjectRole;
use App\Support\SharedProjects\SharedViewer;
use App\Support\SharedProjects\ShareSide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rule of "may this workspace's person see or do X on a shared project",
 * pinned at its boundaries. The rules are database-free, so the whole matrix
 * runs here and nothing about it depends on a fixture.
 */
final class SharedViewerTest extends TestCase
{
    private function host(?StaffRole $role, ?ProjectRole $projectRole = null): SharedViewer
    {
        return new SharedViewer(ShareSide::Host, $role, $projectRole);
    }

    private function participant(?StaffRole $role): SharedViewer
    {
        return new SharedViewer(ShareSide::Participant, $role);
    }

    /** @return iterable<string, array{SharedViewer, bool}> */
    public static function viewingTheProject(): iterable
    {
        yield 'host owner' => [new SharedViewer(ShareSide::Host, StaffRole::Owner), true];
        yield 'host admin' => [new SharedViewer(ShareSide::Host, StaffRole::Admin), true];
        yield 'host member holding a project role' => [new SharedViewer(ShareSide::Host, StaffRole::Member, ProjectRole::Viewer), true];
        yield 'host member holding none' => [new SharedViewer(ShareSide::Host, StaffRole::Member), false];
        yield 'host non-member' => [new SharedViewer(ShareSide::Host, null, ProjectRole::Owner), false];
        yield 'participant owner' => [new SharedViewer(ShareSide::Participant, StaffRole::Owner), true];
        yield 'participant admin' => [new SharedViewer(ShareSide::Participant, StaffRole::Admin), true];
        yield 'participant member' => [new SharedViewer(ShareSide::Participant, StaffRole::Member), true];
        yield 'participant non-member' => [new SharedViewer(ShareSide::Participant, null), false];
    }

    #[DataProvider('viewingTheProject')]
    public function test_who_may_open_the_project(SharedViewer $viewer, bool $expected): void
    {
        $this->assertSame($expected, $viewer->canViewProject());
    }

    public function test_the_whole_project_is_open_to_the_host_and_to_a_participant_that_manages_its_workspace(): void
    {
        $this->assertTrue($this->host(StaffRole::Member, ProjectRole::Viewer)->seesWholeProject());
        $this->assertTrue($this->participant(StaffRole::Owner)->seesWholeProject());
        $this->assertTrue($this->participant(StaffRole::Admin)->seesWholeProject());
        $this->assertFalse($this->participant(StaffRole::Member)->seesWholeProject());
        $this->assertFalse($this->participant(null)->seesWholeProject());
        $this->assertFalse($this->host(StaffRole::Member)->seesWholeProject());
        $this->assertFalse($this->host(null)->seesWholeProject());
    }

    public function test_only_the_host_reads_who_takes_part(): void
    {
        $this->assertTrue($this->host(StaffRole::Admin)->canSeeParticipants());
        $this->assertTrue($this->host(StaffRole::Member, ProjectRole::Viewer)->canSeeParticipants());
        $this->assertFalse($this->host(StaffRole::Member)->canSeeParticipants());
        $this->assertFalse($this->participant(StaffRole::Owner)->canSeeParticipants());
    }

    public function test_a_participant_sees_a_task_only_when_it_is_shared_and_theirs_or_they_manage(): void
    {
        $owner = $this->participant(StaffRole::Owner);
        $admin = $this->participant(StaffRole::Admin);
        $member = $this->participant(StaffRole::Member);

        $this->assertTrue($owner->canSeeTask(true, false));
        $this->assertTrue($admin->canSeeTask(true, false));
        $this->assertFalse($owner->canSeeTask(false, true), 'A task the host keeps internal is never shown, even to an owner it is assigned to.');
        $this->assertFalse($owner->canSeeTask(false, false));
        $this->assertTrue($member->canSeeTask(true, true));
        $this->assertFalse($member->canSeeTask(true, false), 'A member sees only the work given to them.');
        $this->assertFalse($member->canSeeTask(false, true));
        $this->assertFalse($this->participant(null)->canSeeTask(true, true));
    }

    public function test_the_host_sees_every_task_it_may_open_the_project_for(): void
    {
        $this->assertTrue($this->host(StaffRole::Owner)->canSeeTask(false, false));
        $this->assertTrue($this->host(StaffRole::Member, ProjectRole::Viewer)->canSeeTask(false, false));
        $this->assertFalse($this->host(StaffRole::Member)->canSeeTask(true, true));
        $this->assertFalse($this->host(null, ProjectRole::Owner)->canSeeTask(true, true));
    }

    public function test_a_participant_reads_only_the_shared_conversation_and_the_host_reads_all_of_it(): void
    {
        $this->assertTrue($this->participant(StaffRole::Member)->canSeeComment(true));
        $this->assertFalse($this->participant(StaffRole::Member)->canSeeComment(false));
        $this->assertFalse($this->participant(null)->canSeeComment(true));
        $this->assertTrue($this->host(StaffRole::Owner)->canSeeComment(false));
        $this->assertTrue($this->host(StaffRole::Owner)->canSeeComment(true));
        $this->assertFalse($this->host(StaffRole::Member)->canSeeComment(true));
    }

    /** @return iterable<string, array{SharedViewer, bool, bool, bool}> */
    public static function commenting(): iterable
    {
        yield 'host owner' => [new SharedViewer(ShareSide::Host, StaffRole::Owner), false, false, true];
        yield 'host contributor' => [new SharedViewer(ShareSide::Host, StaffRole::Member, ProjectRole::Contributor), false, false, true];
        yield 'host manager' => [new SharedViewer(ShareSide::Host, StaffRole::Member, ProjectRole::Manager), false, false, true];
        yield 'host viewer only reads' => [new SharedViewer(ShareSide::Host, StaffRole::Member, ProjectRole::Viewer), false, false, false];
        yield 'host member with no project role' => [new SharedViewer(ShareSide::Host, StaffRole::Member), true, true, false];
        yield 'participant admin on a shared task' => [new SharedViewer(ShareSide::Participant, StaffRole::Admin), true, false, true];
        yield 'participant admin on an internal task' => [new SharedViewer(ShareSide::Participant, StaffRole::Admin), false, true, false];
        yield 'participant member on their task' => [new SharedViewer(ShareSide::Participant, StaffRole::Member), true, true, true];
        yield 'participant member on another task' => [new SharedViewer(ShareSide::Participant, StaffRole::Member), true, false, false];
        yield 'participant non-member' => [new SharedViewer(ShareSide::Participant, null), true, true, false];
    }

    #[DataProvider('commenting')]
    public function test_who_may_comment(SharedViewer $viewer, bool $shared, bool $assigned, bool $expected): void
    {
        $this->assertSame($expected, $viewer->canComment($shared, $assigned));
    }

    public function test_only_the_host_assigns_and_only_those_who_manage_its_tasks(): void
    {
        $this->assertTrue($this->host(StaffRole::Owner)->canAssign());
        $this->assertTrue($this->host(StaffRole::Admin)->canAssign());
        $this->assertTrue($this->host(StaffRole::Member, ProjectRole::Owner)->canAssign());
        $this->assertTrue($this->host(StaffRole::Member, ProjectRole::Manager)->canAssign());
        $this->assertFalse($this->host(StaffRole::Member, ProjectRole::Contributor)->canAssign());
        $this->assertFalse($this->host(StaffRole::Member, ProjectRole::Viewer)->canAssign());
        $this->assertFalse($this->host(StaffRole::Member)->canAssign());
        $this->assertFalse($this->host(null, ProjectRole::Owner)->canAssign());
        $this->assertFalse($this->participant(StaffRole::Owner)->canAssign());
    }

    public function test_inviting_and_revoking_belong_to_the_hosts_owners_and_admins(): void
    {
        $this->assertTrue($this->host(StaffRole::Owner)->canManageParticipants());
        $this->assertTrue($this->host(StaffRole::Admin)->canManageParticipants());
        $this->assertFalse($this->host(StaffRole::Member, ProjectRole::Owner)->canManageParticipants());
        $this->assertFalse($this->host(null)->canManageParticipants());
        $this->assertFalse($this->participant(StaffRole::Owner)->canManageParticipants());
    }

    public function test_leaving_and_linking_belong_to_the_participants_owners_and_admins(): void
    {
        $this->assertTrue($this->participant(StaffRole::Owner)->canActForParticipant());
        $this->assertTrue($this->participant(StaffRole::Admin)->canActForParticipant());
        $this->assertFalse($this->participant(StaffRole::Member)->canActForParticipant());
        $this->assertFalse($this->participant(null)->canActForParticipant());
        $this->assertFalse($this->host(StaffRole::Owner)->canActForParticipant());
    }
}
