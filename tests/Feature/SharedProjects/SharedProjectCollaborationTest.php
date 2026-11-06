<?php

namespace Tests\Feature\SharedProjects;

use App\Models\ClientComment;
use App\Models\ClientTaskAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SharedProjects\SharedProjectParticipations;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSharedProjects;
use Tests\TestCase;

/**
 * What a participant's people can see and say on a host's project, and what
 * the host can hand them: reads, assignment and comments over REST.
 */
final class SharedProjectCollaborationTest extends TestCase
{
    use BuildsSharedProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-10 12:00:00');
        config(['agent_api.writes_enabled' => true, 'agent_api.collaboration_writes_enabled' => true]);
        $this->sharedWorld();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function as(User $user): void
    {
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::TASKS_READ, AgentApiScopes::TASKS_WRITE,
            AgentApiScopes::COLLABORATION_READ, AgentApiScopes::COLLABORATION_WRITE]);
    }

    private function base(Workspace $workspace): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/shared-projects/{$this->world['project']->public_id}";
    }

    /** @return list<string> */
    private function taskTitles(User $user, Workspace $workspace): array
    {
        $this->as($user);

        return array_column($this->getJson($this->base($workspace).'/tasks')->assertOk()->json('data'), 'title');
    }

    public function test_a_participant_that_manages_its_workspace_sees_the_shared_tasks_and_not_the_internal_ones(): void
    {
        $w = $this->world;

        foreach (['aOwner' => 'a', 'aAdmin' => 'a'] as $person => $workspace) {
            $this->assertSame(['VISIBLE-SHARED-TASK'], $this->taskTitles($w[$person], $w[$workspace]), $person);
        }
    }

    public function test_a_task_under_a_hidden_parent_or_list_is_internal_too(): void
    {
        $w = $this->world;
        $child = $this->task($w['project'], 'SECRET-HOST-CHILD-OF-HIDDEN');
        $child->forceFill(['parent_task_id' => $w['internal']->id])->save();

        $this->assertSame(['VISIBLE-SHARED-TASK'], $this->taskTitles($w['aOwner'], $w['a']));
        $this->assertContains('SECRET-HOST-CHILD-OF-HIDDEN', $this->taskTitles($w['hostOwner'], $w['host']), 'The host still sees it.');
    }

    public function test_a_plain_member_sees_only_the_work_assigned_to_them_from_their_workspace(): void
    {
        $w = $this->world;
        $this->assertSame([], $this->taskTitles($w['aMember'], $w['a']), 'Nothing is assigned to them yet.');

        $this->assignment($w['shared'], $w['aMember'], $w['a']);
        $second = $this->task($w['project'], 'VISIBLE-UNASSIGNED-TASK');
        $internalMine = $this->task($w['project'], 'SECRET-INTERNAL-BUT-ASSIGNED', visible: false);
        $this->assignment($internalMine, $w['aMember'], $w['a']);

        $this->assertSame(['VISIBLE-SHARED-TASK'], $this->taskTitles($w['aMember'], $w['a']));
        $this->assertSame(['VISIBLE-SHARED-TASK', 'VISIBLE-UNASSIGNED-TASK'], $this->taskTitles($w['aOwner'], $w['a']));
        $this->as($w['aMember']);
        $this->getJson($this->base($w['a']).'/tasks/'.$second->public_id.'/comments')->assertNotFound();
        $this->getJson($this->base($w['a']).'/tasks/'.$internalMine->public_id.'/comments')->assertNotFound();
    }

    public function test_an_assignment_made_for_another_workspace_does_not_count_for_this_one(): void
    {
        $w = $this->world;
        // The same person assigned in the capacity of the host is not the participant's work.
        $this->assignment($w['shared'], $w['aMember'], null);

        $this->assertSame([], $this->taskTitles($w['aMember'], $w['a']));
    }

    public function test_a_participant_reads_the_task_fields_it_is_meant_to_and_no_others(): void
    {
        $w = $this->world;
        $this->as($w['aOwner']);

        $task = $this->getJson($this->base($w['a']).'/tasks')->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(['id', 'title', 'description', 'status', 'shared_with_participants', 'starts_on', 'due_on', 'completed_at', 'web_url', 'assignees', 'can_comment'], array_keys($task));
        $this->assertSame($w['shared']->public_id, $task['id']);
        $this->assertTrue($task['shared_with_participants']);
    }

    public function test_the_project_a_participant_reads_is_the_name_and_the_standing_and_nothing_of_the_hosts(): void
    {
        $w = $this->world;
        $this->as($w['aOwner']);

        $project = $this->getJson($this->base($w['a']))->assertOk()->json('data');

        $this->assertSame('participant', $project['side']);
        $this->assertSame('Synthetic Host Studio', $project['host_workspace_name']);
        $this->assertSame(['id' => $w['aLocal']->public_id, 'name' => 'SECRET-A-LOCAL-PROJECT'], $project['local_project'], 'Its own linked project is its own to see.');
        $this->assertArrayNotHasKey('client_name', $project);
        $this->assertArrayNotHasKey('description', $project);
        $this->assertArrayNotHasKey('participants', $project, 'It does not learn who else takes part.');
        $this->assertArrayNotHasKey('invitations', $project);
        $this->assertArrayNotHasKey('assignee_options', $project);
        $this->assertFalse($project['viewer']['can_assign']);
    }

    public function test_the_host_sees_participants_invitations_and_who_it_may_assign(): void
    {
        $w = $this->world;
        $invitee = $this->person($w['stranger'], 'owner', 'Ines Invitee');
        app(SharedProjectParticipations::class)->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);
        $this->as($w['hostOwner']);

        $project = $this->getJson($this->base($w['host']))->assertOk()->json('data');

        $this->assertSame('host', $project['side']);
        $this->assertSame('SECRET-HOST-CLIENT-NAME', $project['client_name'], 'The host reads its own client.');
        $this->assertEqualsCanonicalizing(['Synthetic Partner Alpha', 'Synthetic Partner Beta'], array_column($project['participants'], 'workspace_name'));
        $this->assertSame([(string) $invitee->email], array_column($project['invitations'], 'email'));
        $names = array_column($project['assignee_options'], 'name');
        $this->assertContains('Hollis Hostowner', $names);
        $this->assertContains('Hana Hostmanager', $names);
        $this->assertContains('Alma Alphaowner', $names);
        $this->assertContains('Adel Alphaadmin', $names);
        $this->assertContains('Anya Alphalocal', $names, 'A member on the participant\'s local project is offered.');
        $this->assertNotContains('Aiden Alphamember', $names, 'A member it did not put on the share is not.');
        $this->assertNotContains('Hector Hostplain', $names);
        $this->assertNotContains('Sasha Strangerowner', $names);
    }

    public function test_the_host_hands_a_task_across_workspaces_and_both_sides_see_where_each_person_stands(): void
    {
        $w = $this->world;
        $this->as($w['hostOwner']);

        $this->putJson($this->base($w['host']).'/tasks/'.$w['shared']->public_id.'/assignees', ['assignees' => [
            ['user_id' => $w['hostManager']->public_id, 'workspace_id' => $w['host']->public_id],
            ['user_id' => $w['aMember']->public_id, 'workspace_id' => $w['a']->public_id],
        ]])->assertUnprocessable();

        $this->withHeader('Idempotency-Key', 'assign-1')->putJson($this->base($w['host']).'/tasks/'.$w['shared']->public_id.'/assignees', ['assignees' => [
            ['user_id' => $w['hostManager']->public_id, 'workspace_id' => $w['host']->public_id],
            ['user_id' => $w['aOnLocal']->public_id, 'workspace_id' => $w['a']->public_id],
        ]])->assertOk()->assertJsonPath('data.assignees.1.workspace_name', 'Synthetic Partner Alpha');

        $this->assertSame(['workspace_id' => $w['host']->id, 'ok' => true], ['workspace_id' => ClientTaskAssignment::query()->where('user_id', $w['hostManager']->id)->value('workspace_id'), 'ok' => ClientTaskAssignment::query()->where('user_id', $w['hostManager']->id)->value('assignee_workspace_id') === null]);
        $this->assertSame($w['a']->id, ClientTaskAssignment::query()->where('user_id', $w['aOnLocal']->id)->value('assignee_workspace_id'));

        // The assignee sees the task in their own workspace; the owner sees it with both names.
        $this->assertSame(['VISIBLE-SHARED-TASK'], $this->taskTitles($w['aOnLocal'], $w['a']));
        $this->as($w['aOwner']);
        $assignees = $this->getJson($this->base($w['a']).'/tasks')->assertOk()->json('data.0.assignees');
        $this->assertSame([['name' => 'Hana Hostmanager', 'workspace_name' => 'Synthetic Host Studio'], ['name' => 'Anya Alphalocal', 'workspace_name' => 'Synthetic Partner Alpha']], $assignees, 'No ids are told to a viewer who cannot assign.');
    }

    public function test_assignment_replaces_the_set_and_keeps_people_already_on_it(): void
    {
        $w = $this->world;
        $this->assignment($w['shared'], $w['aMember'], $w['a']);
        $this->as($w['hostOwner']);
        $stay = ['user_id' => $w['aMember']->public_id, 'workspace_id' => $w['a']->public_id];

        $this->withHeader('Idempotency-Key', 'assign-keep')->putJson($this->base($w['host']).'/tasks/'.$w['shared']->public_id.'/assignees', ['assignees' => [
            $stay, ['user_id' => $w['bOwner']->public_id, 'workspace_id' => $w['b']->public_id],
        ]])->assertOk();
        $this->assertSame(2, ClientTaskAssignment::query()->where('client_task_id', $w['shared']->id)->count(), 'An existing assignee who is no longer offered stays.');

        $this->withHeader('Idempotency-Key', 'assign-clear')->putJson($this->base($w['host']).'/tasks/'.$w['shared']->public_id.'/assignees', ['assignees' => []])->assertOk();
        $this->assertSame(0, ClientTaskAssignment::query()->where('client_task_id', $w['shared']->id)->count());
    }

    public function test_assignment_refuses_people_not_offered_duplicates_and_other_workspaces_names_for_them(): void
    {
        $w = $this->world;
        $this->as($w['hostOwner']);
        $put = fn (array $people) => $this->withHeader('Idempotency-Key', 'assign-'.uniqid())->putJson($this->base($w['host']).'/tasks/'.$w['shared']->public_id.'/assignees', ['assignees' => $people]);

        // Not a member of any workspace here at all.
        $put([['user_id' => $w['strangerOwner']->public_id, 'workspace_id' => $w['stranger']->public_id]])->assertUnprocessable();
        // A real participant person named in a workspace they are not in.
        $put([['user_id' => $w['aOwner']->public_id, 'workspace_id' => $w['b']->public_id]])->assertUnprocessable();
        // A real person of a workspace that does not take part.
        $put([['user_id' => $w['strangerOwner']->public_id, 'workspace_id' => $w['a']->public_id]])->assertUnprocessable();
        // The same person twice, and unknown ids.
        $put([['user_id' => $w['aOwner']->public_id, 'workspace_id' => $w['a']->public_id], ['user_id' => $w['aOwner']->public_id, 'workspace_id' => $w['a']->public_id]])->assertUnprocessable();
        $put([['user_id' => '00000000-0000-4000-8000-000000000000', 'workspace_id' => $w['a']->public_id]])->assertUnprocessable();
        $put([['user_id' => $w['aOwner']->public_id]])->assertUnprocessable();
        $this->assertSame(0, ClientTaskAssignment::query()->where('client_task_id', $w['shared']->id)->count());
    }

    public function test_a_task_cannot_be_assigned_to_a_participant_who_has_left(): void
    {
        $w = $this->world;
        app(SharedProjectParticipations::class)->leave($w['aOwner'], $w['a'], $w['project']->public_id);
        $this->as($w['hostOwner']);

        $this->withHeader('Idempotency-Key', 'assign-left')->putJson($this->base($w['host']).'/tasks/'.$w['shared']->public_id.'/assignees', ['assignees' => [
            ['user_id' => $w['aOwner']->public_id, 'workspace_id' => $w['a']->public_id],
        ]])->assertUnprocessable();
    }

    public function test_who_may_assign(): void
    {
        $w = $this->world;
        $body = ['assignees' => [['user_id' => $w['hostManager']->public_id, 'workspace_id' => $w['host']->public_id]]];
        $put = fn (User $user, Workspace $workspace) => $this->as($user) ?? $this->withHeader('Idempotency-Key', 'assign-'.uniqid())->putJson($this->base($workspace).'/tasks/'.$w['shared']->public_id.'/assignees', $body);

        $put($w['hostManager'], $w['host'])->assertOk();
        $put($w['hostViewer'], $w['host'])->assertForbidden();
        $put($w['hostPlain'], $w['host'])->assertNotFound();
        $put($w['aOwner'], $w['a'])->assertForbidden();
        $put($w['aMember'], $w['a'])->assertForbidden();
        $put($w['strangerOwner'], $w['stranger'])->assertNotFound();
    }

    public function test_assignment_is_off_for_agents_until_collaboration_writes_are_enabled(): void
    {
        $w = $this->world;
        config(['agent_api.collaboration_writes_enabled' => false]);
        $this->as($w['hostOwner']);

        $this->withHeader('Idempotency-Key', 'assign-off')->putJson($this->base($w['host']).'/tasks/'.$w['shared']->public_id.'/assignees', ['assignees' => []])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'comment-off')->postJson($this->base($w['host']).'/tasks/'.$w['shared']->public_id.'/comments', ['body' => 'Hello'])->assertNotFound();
    }

    public function test_the_shared_conversation_is_visible_to_the_host_and_to_participants_and_the_internal_notes_are_not(): void
    {
        $w = $this->world;
        $path = $this->base($w['a']).'/tasks/'.$w['shared']->public_id.'/comments';
        $this->as($w['aOwner']);

        $this->withHeader('Idempotency-Key', 'comment-a-1')->postJson($path, ['body' => 'VISIBLE-A-COMMENT'])->assertCreated()
            ->assertJsonPath('data.author_name', 'Alma Alphaowner')->assertJsonPath('data.author_workspace_name', 'Synthetic Partner Alpha');

        $stored = ClientComment::query()->where('body', 'VISIBLE-A-COMMENT')->firstOrFail();
        $this->assertSame($w['host']->id, $stored->workspace_id, 'The comment lives in the host\'s thread.');
        $this->assertSame($w['a']->id, $stored->author_workspace_id);
        $this->assertTrue($stored->shared_with_participants);
        $this->assertFalse($stored->is_visible_to_client, 'It is never shown to the host\'s client.');

        $bodies = fn (User $user, Workspace $workspace): array => $this->as($user) ?? array_column($this->getJson($this->base($workspace).'/tasks/'.$w['shared']->public_id.'/comments')->assertOk()->json('data'), 'body');

        $this->assertSame(['VISIBLE-HOST-SHARED-COMMENT', 'VISIBLE-A-COMMENT'], $bodies($w['aOwner'], $w['a']));
        $this->assertSame(['VISIBLE-HOST-SHARED-COMMENT', 'VISIBLE-A-COMMENT'], $bodies($w['bOwner'], $w['b']), 'The shared conversation is shared with every participant.');
        $this->assertSame(['SECRET-HOST-INTERNAL-COMMENT', 'VISIBLE-HOST-SHARED-COMMENT', 'VISIBLE-A-COMMENT'], $bodies($w['hostOwner'], $w['host']));
    }

    public function test_who_may_comment(): void
    {
        $w = $this->world;
        $this->assignment($w['shared'], $w['aMember'], $w['a']);
        $post = fn (User $user, Workspace $workspace) => $this->as($user) ?? $this->withHeader('Idempotency-Key', 'comment-'.uniqid())->postJson($this->base($workspace).'/tasks/'.$w['shared']->public_id.'/comments', ['body' => 'A synthetic comment']);

        $post($w['hostOwner'], $w['host'])->assertCreated();
        $post($w['hostManager'], $w['host'])->assertCreated();
        $post($w['hostViewer'], $w['host'])->assertForbidden();
        $post($w['hostPlain'], $w['host'])->assertNotFound();
        $post($w['aOwner'], $w['a'])->assertCreated();
        $post($w['aMember'], $w['a'])->assertCreated();
        $post($w['aOnLocal'], $w['a'])->assertNotFound();
        $post($w['strangerOwner'], $w['stranger'])->assertNotFound();
    }

    public function test_a_participant_cannot_comment_on_an_internal_task_or_another_projects_task(): void
    {
        $w = $this->world;
        $this->as($w['aOwner']);

        foreach (['internal', 'otherTask'] as $task) {
            $this->withHeader('Idempotency-Key', 'comment-'.$task)->postJson($this->base($w['a']).'/tasks/'.$w[$task]->public_id.'/comments', ['body' => 'Should not land'])->assertNotFound();
        }
        $this->assertSame(0, ClientComment::query()->where('body', 'Should not land')->count());
    }

    public function test_a_comment_is_validated_and_never_takes_an_author_or_workspace_from_the_request(): void
    {
        $w = $this->world;
        $this->as($w['aOwner']);
        $path = $this->base($w['a']).'/tasks/'.$w['shared']->public_id.'/comments';

        $this->withHeader('Idempotency-Key', 'comment-empty')->postJson($path, ['body' => ''])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'comment-long')->postJson($path, ['body' => str_repeat('x', 50001)])->assertUnprocessable();
        // The published contract refuses an extra field outright, so no author or workspace can be sent.
        $this->assertFalse(AgentApiResponseSchemaCatalog::requestForOperation('shared_projects.comments.create')['additionalProperties']);
        $this->assertSame(['body'], array_keys(AgentApiResponseSchemaCatalog::requestForOperation('shared_projects.comments.create')['properties']));
        $this->flushHeaders();
        $this->postJson($path, ['body' => 'No key'])->assertUnprocessable();
    }

    public function test_a_replayed_comment_is_the_same_comment_and_a_revoke_ends_the_replay(): void
    {
        $w = $this->world;
        $path = $this->base($w['a']).'/tasks/'.$w['shared']->public_id.'/comments';
        $this->as($w['aOwner']);
        $first = $this->withHeader('Idempotency-Key', 'comment-replay')->postJson($path, ['body' => 'VISIBLE-ONCE'])->assertCreated()->json('data');

        $again = $this->withHeader('Idempotency-Key', 'comment-replay')->postJson($path, ['body' => 'VISIBLE-ONCE'])->assertCreated()->json('data');
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, ClientComment::query()->where('body', 'VISIBLE-ONCE')->count());
        $this->withHeader('Idempotency-Key', 'comment-replay')->postJson($path, ['body' => 'Different'])->assertConflict();

        app(SharedProjectParticipations::class)->leave($w['aOwner'], $w['a'], $w['project']->public_id);
        $this->as($w['aOwner']);
        $this->withHeader('Idempotency-Key', 'comment-replay')->postJson($path, ['body' => 'VISIBLE-ONCE'])->assertNotFound();
    }

    public function test_the_task_list_is_paged_and_a_cursor_from_another_listing_is_refused(): void
    {
        $w = $this->world;
        foreach (range(1, 3) as $number) {
            $this->task($w['project'], "VISIBLE-PAGED-{$number}");
        }
        $this->as($w['aOwner']);

        $page = $this->getJson($this->base($w['a']).'/tasks?limit=2')->assertOk()->json();
        $this->assertCount(2, $page['data']);
        $this->assertNotNull($page['meta']['next_cursor']);
        $next = $this->getJson($this->base($w['a']).'/tasks?limit=2&cursor='.urlencode($page['meta']['next_cursor']))->assertOk()->json();
        $this->assertCount(2, $next['data']);
        $this->assertNull($next['meta']['next_cursor']);
        $this->assertSame([], array_values(array_intersect(array_column($page['data'], 'id'), array_column($next['data'], 'id'))));

        // A cursor minted for another workspace's listing is not accepted here.
        $this->as($w['bOwner']);
        $this->getJson($this->base($w['b']).'/tasks?cursor='.urlencode($page['meta']['next_cursor']))->assertUnprocessable();
        $this->getJson($this->base($w['b']).'/tasks?limit=0')->assertUnprocessable();
        $this->getJson($this->base($w['b']).'/tasks?limit=51')->assertUnprocessable();
    }

    public function test_the_list_names_the_projects_a_workspace_hosts_and_the_ones_it_takes_part_in(): void
    {
        $w = $this->world;
        $this->as($w['hostOwner']);
        $hosted = $this->getJson("/api/v1/workspaces/{$w['host']->public_id}/shared-projects")->assertOk()->json('data');
        $this->assertSame([], $hosted['participating']);
        $this->assertSame(['Synthetic Shared Project'], array_column($hosted['hosted'], 'name'), 'Only the project with a participant, not the host\'s other project.');

        $this->as($w['aOwner']);
        $participating = $this->getJson("/api/v1/workspaces/{$w['a']->public_id}/shared-projects")->assertOk()->json('data');
        $this->assertSame([], $participating['hosted']);
        $this->assertSame([['id' => $w['project']->public_id, 'name' => 'Synthetic Shared Project', 'host_workspace_name' => 'Synthetic Host Studio', 'web_url' => "/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}"]], $participating['participating']);

        $this->as($w['strangerOwner']);
        $this->getJson("/api/v1/workspaces/{$w['stranger']->public_id}/shared-projects")->assertOk()->assertExactJson(['data' => ['hosted' => [], 'participating' => []]]);
    }
}
