<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceProjectMutationAction;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\Concerns\CapturesTenantOwnedWrites;
use Tests\TestCase;

/**
 * One project's assignment, changed through the shared project workflow, is
 * coordinated like every other access mutation.
 */
final class ProjectAssignmentCoordinationTest extends TestCase
{
    use BuildsAccessFixtures;
    use CapturesTenantOwnedWrites;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private User $member;

    private ClientCompany $company;

    private ClientProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create(['name' => 'Assignments', 'slug' => 'assignments']);
        $this->admin = $this->staff($this->workspace, 'admin');
        $this->member = $this->staff($this->workspace, 'member');
        $this->company = $this->company($this->workspace, 'assignment-client');
        $this->project = $this->project($this->company, 'Assigned');
    }

    protected function tearDown(): void
    {
        LockOrderRecorder::stop();

        parent::tearDown();
    }

    public function test_the_workspace_access_lock_comes_first(): void
    {
        LockOrderRecorder::start();
        $this->update('viewer');
        $sequences = LockOrderRecorder::sequences();
        LockOrderRecorder::stop();

        $this->assertSame(
            [LockResource::Workspace, LockResource::ClientProject, LockResource::WorkspaceMembership, LockResource::WorkspaceMembership, LockResource::ClientProjectMembership],
            $sequences[0],
            'Workspace, then the project, then the actor re-checked under the lock, then the target, then the assignment whose current role the audit records.',
        );
    }

    public function test_assignment_changes_are_audited_with_before_and_after_roles(): void
    {
        $this->update('viewer');
        $this->update('manager');
        $this->update('manager');
        $this->update('none');

        $events = AccessAuditEvent::query()->orderBy('id')->get();
        $project = $this->project->public_id;
        $this->assertSame(['project.assignment_changed'], $events->pluck('action')->unique()->values()->all());
        $this->assertSame([
            [['project' => $project, 'role' => 'none'], ['project' => $project, 'role' => 'viewer']],
            [['project' => $project, 'role' => 'viewer'], ['project' => $project, 'role' => 'manager']],
            [['project' => $project, 'role' => 'manager'], ['project' => $project, 'role' => 'none']],
        ], $events->map(fn (AccessAuditEvent $event): array => [$event->before_state, $event->after_state])->all(), 'Re-setting the same role changes nothing worth recording.');
        $this->assertSame([$this->admin->id], $events->pluck('actor_user_id')->unique()->values()->all());
        $this->assertSame([$this->member->id], $events->pluck('subject_user_id')->unique()->values()->all());
        // The revision still advances on every call, as before.
        $this->assertSame(5, $this->project->refresh()->lock_version);
    }

    public function test_an_actor_demoted_after_the_fast_check_is_refused_with_nothing_written(): void
    {
        $revision = $this->project->lock_version;
        $this->onceTheWorkspaceIsLocked(fn () => DB::table('workspace_memberships')
            ->where('workspace_id', $this->workspace->id)->where('user_id', $this->admin->id)->update(['role' => 'member']));

        try {
            $this->update('viewer');
            $this->fail('A demoted actor completed an assignment change.');
        } catch (HttpException $refused) {
            $this->assertSame(403, $refused->getStatusCode());
        }

        $this->assertSame(0, ClientProjectMembership::query()->count());
        $this->assertSame($revision, $this->project->refresh()->lock_version);
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_an_actor_removed_after_the_fast_check_is_refused(): void
    {
        $this->onceTheWorkspaceIsLocked(fn () => DB::table('workspace_memberships')
            ->where('workspace_id', $this->workspace->id)->where('user_id', $this->admin->id)->delete());

        $this->expectException(HttpException::class);

        $this->update('viewer');
    }

    public function test_assignment_writes_stay_inside_their_workspace(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $foreignAdmin = $this->staff($elsewhere, 'admin');
        $actions = app(WorkspaceProjectMutationAction::class);

        // An admin of another workspace has no authority here...
        try {
            $actions->updateAccess($foreignAdmin, $this->workspace, $this->project, $this->member->public_id, 'viewer');
            $this->fail('An admin of another workspace changed an assignment here.');
        } catch (HttpException $refused) {
            $this->assertSame(403, $refused->getStatusCode());
        }

        // ...and cannot reach this workspace's project through their own.
        try {
            $actions->updateAccess($foreignAdmin, $elsewhere, $this->project, $this->member->public_id, 'viewer');
            $this->fail('A project was reached through another workspace.');
        } catch (ModelNotFoundException) {
            // Refused under the lock, before any write.
        }
        $this->assertSame(0, ClientProjectMembership::query()->count());
        $this->assertSame(0, AccessAuditEvent::query()->count());

        $writes = $this->writesIssuedBy(function (): void {
            $this->update('viewer');
            $this->update('none');
        });
        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['client_project_memberships', 'client_projects']);
    }

    private function update(string $role): ClientProject
    {
        return app(WorkspaceProjectMutationAction::class)->updateAccess($this->admin, $this->workspace, $this->project, $this->member->public_id, $role);
    }
}
