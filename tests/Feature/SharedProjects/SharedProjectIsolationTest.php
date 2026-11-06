<?php

namespace Tests\Feature\SharedProjects;

use App\Models\ClientAttachment;
use App\Models\ClientComment;
use App\Models\ClientProjectParticipation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SharedProjects\SharedProjectParticipations;
use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\BuildsSharedProjects;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * A participant can never read or write the host's (or another participant's)
 * time, rates, invoices, billing, internal notes, files or projects outside the
 * share. Checked from every standing that must get nothing - a stranger, a
 * pending, declined or expired invitation, a revoked and a departed
 * participant - and from the standings that get something, to prove what they
 * get carries nothing else. Each surface is exercised: the web page, REST, MCP
 * and search.
 */
final class SharedProjectIsolationTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use BuildsSharedProjects;
    use CallsMcp;
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

    /** @return list<string> */
    private function allScopes(): array
    {
        return [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::TASKS_READ, AgentApiScopes::TASKS_WRITE, AgentApiScopes::COLLABORATION_READ,
            AgentApiScopes::COLLABORATION_WRITE, AgentApiScopes::BILLING_READ, AgentApiScopes::TIME_READ, AgentApiScopes::FILES_READ, AgentApiScopes::CLIENTS_READ,
            AgentApiScopes::EXPENSES_READ, AgentApiScopes::FINANCE_READ];
    }

    /** @return array{User, Workspace} the person and the workspace they stand in, in the named standing */
    private function standing(string $case): array
    {
        $w = $this->world;
        $participations = app(SharedProjectParticipations::class);
        $invitee = $this->person($w['stranger'], 'owner', 'Ines Invitee');
        $invite = fn () => $participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);

        switch ($case) {
            case 'stranger':
                return [$w['strangerOwner'], $w['stranger']];
            case 'pending':
                $invite();

                return [$invitee, $w['stranger']];
            case 'declined':
                $participations->decline($invitee, $invite()->public_id);

                return [$invitee, $w['stranger']];
            case 'expired':
                $invitation = $invite();
                CarbonImmutable::setTestNow($invitation->expires_at->addSecond());

                return [$invitee, $w['stranger']];
            case 'revoked':
                $participations->revokeParticipant($w['hostOwner'], $w['host'], $w['project']->public_id, $this->participationOf($w['a']));

                return [$w['aOwner'], $w['a']];
            case 'left':
                $participations->leave($w['bOwner'], $w['b'], $w['project']->public_id);

                return [$w['bOwner'], $w['b']];
            case 'invitation revoked':
                $participations->revokeInvitation($w['hostOwner'], $w['host'], $w['project']->public_id, $invite()->public_id);

                return [$invitee, $w['stranger']];
            case 'host member without the project':
                return [$w['hostPlain'], $w['host']];
        }
        $this->fail("Unknown standing {$case}.");
    }

    private function participationOf(Workspace $participant): string
    {
        return (string) ClientProjectParticipation::query()->where('participant_workspace_id', $participant->id)->value('public_id');
    }

    /** @return iterable<string, array{string}> */
    public static function standingsThatGetNothing(): iterable
    {
        foreach (['stranger', 'pending', 'declined', 'expired', 'revoked', 'left', 'invitation revoked', 'host member without the project'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('standingsThatGetNothing')]
    public function test_a_standing_without_a_live_participation_is_told_nothing_on_every_surface(string $case): void
    {
        $w = $this->world;
        [$user, $workspace] = $this->standing($case);
        $project = $w['project']->public_id;
        $task = $w['shared']->public_id;
        $base = "/api/v1/workspaces/{$workspace->public_id}/shared-projects/{$project}";
        $this->actingAsMcp($user, $this->allScopes());

        // REST: every read and write is 404, exactly as for a project that does not exist.
        $this->getJson($base)->assertNotFound();
        $this->getJson("$base/tasks")->assertNotFound();
        $this->getJson("$base/tasks/$task/comments")->assertNotFound();
        $this->withHeader('Idempotency-Key', 'iso-comment-'.$case)->postJson("$base/tasks/$task/comments", ['body' => 'Should never land'])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'iso-assign-'.$case)->putJson("$base/tasks/$task/assignees", ['assignees' => [['user_id' => $user->public_id, 'workspace_id' => $workspace->public_id]]])->assertNotFound();
        $this->assertDatabaseMissing('client_comments', ['body' => 'Should never land']);
        $this->assertDatabaseMissing('client_task_assignments', ['user_id' => $user->id, 'client_task_id' => $w['shared']->id]);
        $this->flushHeaders();

        // The list answers as if there were nothing to list.
        $list = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/shared-projects")->assertOk();
        $this->assertJsonPayloadOmits($list, [...$this->hostSecrets(), 'Synthetic Shared Project', $project], 'participating');

        // MCP: the same refusal as a tool error, carrying no host content.
        $session = $this->initialize();
        foreach ([
            ['shared_projects.get', ['workspace_id' => $workspace->public_id, 'project_id' => $project]],
            ['shared_projects.tasks.list', ['workspace_id' => $workspace->public_id, 'project_id' => $project]],
            ['shared_projects.comments.list', ['workspace_id' => $workspace->public_id, 'project_id' => $project, 'task_id' => $task]],
            ['shared_projects.comments.create', ['workspace_id' => $workspace->public_id, 'project_id' => $project, 'task_id' => $task, 'body' => 'Never', 'idempotency_key' => 'iso-mcp-'.$case]],
        ] as [$tool, $arguments]) {
            $result = $this->callTool($session, $tool, $arguments);
            $this->assertTrue($result['result']['isError'] ?? false, "$tool: ".json_encode($result));
            $this->assertStringNotContainsString('VISIBLE-SHARED-TASK', (string) json_encode($result));
        }

        // Web: the page is a 404.
        $this->actingAs($user, 'web')->get("/workspaces/{$workspace->public_id}/shared-projects/{$project}")->assertNotFound();
    }

    /** @return iterable<string, array{string}> */
    public static function outsidersToTheHost(): iterable
    {
        foreach (['stranger', 'pending', 'declined', 'expired', 'revoked', 'left', 'invitation revoked'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('outsidersToTheHost')]
    public function test_the_hosts_own_surfaces_are_closed_to_a_standing_without_a_live_participation(string $case): void
    {
        $w = $this->world;
        [$user] = $this->standing($case);
        $host = $w['host']->public_id;
        $this->actingAsMcp($user, $this->allScopes());

        foreach ([
            "/api/v1/workspaces/$host/tasks/{$w['shared']->public_id}",
            "/api/v1/workspaces/$host/tasks",
            "/api/v1/workspaces/$host/projects",
            "/api/v1/workspaces/$host/time-entries",
            "/api/v1/workspaces/$host/invoices",
            "/api/v1/workspaces/$host/clients",
            "/api/v1/workspaces/$host/clients/{$w['company']->public_id}/collaboration/topics",
            "/api/v1/workspaces/$host/shared-projects/{$w['project']->public_id}",
        ] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->actingAs($user, 'web');
        foreach ([
            "/workspaces/$host/clients/{$w['company']->public_id}/projects/{$w['project']->public_id}",
            "/workspaces/$host/tasks/{$w['shared']->public_id}",
            "/workspaces/$host/shared-projects/{$w['project']->public_id}",
            "/workspaces/$host/shared-projects",
            "/workspaces/$host/dashboard",
        ] as $path) {
            $this->assertContains($this->get($path)->getStatusCode(), [403, 404], $path);
        }
    }

    public function test_a_participants_owner_gets_the_share_and_nothing_else_of_the_host_or_the_other_participant(): void
    {
        $w = $this->world;
        $secrets = [...$this->hostSecrets(), 'SECRET-B-TIME', 'SECRET-B-LOCAL-PROJECT', 'SECRET-B-CLIENT-NAME', '333777', '555111'];
        $this->actingAsMcp($w['aOwner'], $this->allScopes());
        $base = "/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}";

        $this->assertJsonPayloadOmits($this->getJson($base)->assertOk(), $secrets, 'Synthetic Shared Project');
        $this->assertJsonPayloadOmits($this->getJson("$base/tasks")->assertOk(), $secrets, 'VISIBLE-SHARED-TASK');
        $this->assertJsonPayloadOmits($this->getJson("$base/tasks/{$w['shared']->public_id}/comments")->assertOk(), $secrets, 'VISIBLE-HOST-SHARED-COMMENT');
        $this->assertJsonPayloadOmits($this->getJson("/api/v1/workspaces/{$w['a']->public_id}/shared-projects")->assertOk(), $secrets, 'Synthetic Shared Project');

        $session = $this->initialize();
        foreach ([
            ['shared_projects.get', ['workspace_id' => $w['a']->public_id, 'project_id' => $w['project']->public_id], 'Synthetic Shared Project'],
            ['shared_projects.tasks.list', ['workspace_id' => $w['a']->public_id, 'project_id' => $w['project']->public_id], 'VISIBLE-SHARED-TASK'],
            ['shared_projects.comments.list', ['workspace_id' => $w['a']->public_id, 'project_id' => $w['project']->public_id, 'task_id' => $w['shared']->public_id], 'VISIBLE-HOST-SHARED-COMMENT'],
        ] as [$tool, $arguments, $control]) {
            $result = $this->callTool($session, $tool, $arguments);
            $this->assertFalse($result['result']['isError'] ?? true, json_encode($result));
            $text = (string) json_encode($result);
            $this->assertStringContainsString($control, $text, $tool);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text, "$tool leaked $secret");
            }
        }

        $page = $this->actingAs($w['aOwner'], 'web')->get("/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}")->assertOk();
        $this->assertInertiaPayloadOmits($page, $secrets, 'VISIBLE-SHARED-TASK');
        $index = $this->actingAs($w['aOwner'], 'web')->get("/workspaces/{$w['a']->public_id}/shared-projects")->assertOk();
        $this->assertInertiaPayloadOmits($index, $secrets, 'Synthetic Shared Project');
    }

    public function test_the_other_participant_is_equally_closed_off_from_the_first_ones_books(): void
    {
        $w = $this->world;
        $secrets = [...$this->hostSecrets(), 'SECRET-A-TIME', 'SECRET-A-LOCAL-PROJECT', 'SECRET-A-CLIENT-NAME', '555111'];
        $this->actingAsMcp($w['bOwner'], $this->allScopes());
        $base = "/api/v1/workspaces/{$w['b']->public_id}/shared-projects/{$w['project']->public_id}";

        $this->assertJsonPayloadOmits($this->getJson($base)->assertOk(), $secrets, 'SECRET-B-LOCAL-PROJECT');
        $this->assertJsonPayloadOmits($this->getJson("$base/tasks")->assertOk(), $secrets, 'VISIBLE-SHARED-TASK');
        $page = $this->actingAs($w['bOwner'], 'web')->get("/workspaces/{$w['b']->public_id}/shared-projects/{$w['project']->public_id}")->assertOk();
        $this->assertInertiaPayloadOmits($page, $secrets, 'VISIBLE-SHARED-TASK');
    }

    public function test_a_subcontractor_in_a_participant_workspace_sees_only_the_work_given_to_them(): void
    {
        $w = $this->world;
        $secrets = [...$this->hostSecrets(), 'SECRET-B-TIME', 'SECRET-A-TIME', 'SECRET-A-LOCAL-PROJECT', 'SECRET-A-CLIENT-NAME', 'SECRET-B-LOCAL-PROJECT', '555111', '333777'];
        $mine = $this->task($w['project'], 'VISIBLE-MINE-TASK');
        $this->assignment($mine, $w['aMember'], $w['a']);
        $base = "/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}";
        $this->actingAsMcp($w['aMember'], $this->allScopes());

        $tasks = $this->getJson("$base/tasks")->assertOk();
        $this->assertJsonPayloadOmits($tasks, [...$secrets, 'VISIBLE-SHARED-TASK'], 'VISIBLE-MINE-TASK');
        $project = $this->getJson($base)->assertOk()->json('data');
        $this->assertFalse($project['viewer']['sees_whole_project']);
        $this->assertFalse($project['viewer']['can_act_as_participant']);
        $this->assertArrayNotHasKey('local_project', $project, 'Their workspace\'s link is for its owners and admins to manage.');
        $this->assertJsonPayloadOmits($this->getJson($base)->assertOk(), $secrets, 'Synthetic Shared Project');
        // Not leaving, not linking, not inviting: the browser actions are refused.
        $this->actingAs($w['aMember'], 'web')->post("/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}/leave")->assertForbidden();
        $this->actingAs($w['aMember'], 'web')->putJson("/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}/local-project", ['local_project_id' => null])->assertForbidden();
        $this->actingAs($w['aMember'], 'web')->postJson("/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}/invitations", ['email' => 'x@example.test'])->assertNotFound();

        $page = $this->actingAs($w['aMember'], 'web')->get("/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}")->assertOk();
        $this->assertInertiaPayloadOmits($page, [...$secrets, 'VISIBLE-SHARED-TASK'], 'VISIBLE-MINE-TASK');
    }

    public function test_access_ends_the_moment_the_host_revokes_or_the_participant_leaves(): void
    {
        $w = $this->world;
        $base = fn (Workspace $workspace) => "/api/v1/workspaces/{$workspace->public_id}/shared-projects/{$w['project']->public_id}";
        $this->actingAsMcp($w['aOwner'], $this->allScopes());
        $this->getJson($base($w['a']).'/tasks')->assertOk();
        $this->actingAs($w['aOwner'], 'web')->get("/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}")->assertOk();
        $session = $this->initialize();

        app(SharedProjectParticipations::class)->revokeParticipant($w['hostOwner'], $w['host'], $w['project']->public_id, $this->participationOf($w['a']));

        $result = $this->callTool($session, 'shared_projects.tasks.list', ['workspace_id' => $w['a']->public_id, 'project_id' => $w['project']->public_id]);
        $this->assertTrue($result['result']['isError'] ?? false, 'An MCP session opened before the revoke gets nothing after it.');
        $this->getJson($base($w['a']).'/tasks')->assertNotFound();
        $this->actingAs($w['aOwner'], 'web')->get("/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}")->assertNotFound();
        // The other participant is untouched by it.
        $this->actingAsMcp($w['bOwner'], $this->allScopes());
        $this->getJson($base($w['b']).'/tasks')->assertOk();
    }

    public function test_a_project_the_host_did_not_share_is_not_found_through_the_shared_path_or_by_task(): void
    {
        $w = $this->world;
        $this->actingAsMcp($w['aOwner'], $this->allScopes());
        $other = "/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['other']->public_id}";

        $this->getJson($other)->assertNotFound();
        $this->getJson("$other/tasks")->assertNotFound();
        // A task of the host's other project, named under the shared project's path.
        $this->getJson("/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}/tasks/{$w['otherTask']->public_id}/comments")->assertNotFound();
        // The participant's own project is its own to host, with nobody taking part; the host's is not the participant's to name.
        $this->getJson("/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['aLocal']->public_id}")->assertOk()->assertJsonPath('data.participants', []);
        $this->getJson("/api/v1/workspaces/{$w['host']->public_id}/shared-projects/{$w['project']->public_id}")->assertNotFound();
    }

    public function test_the_hosts_files_and_other_workspace_routes_are_not_reachable_through_the_share(): void
    {
        $w = $this->world;
        $attachment = ClientAttachment::query()->where('workspace_id', $w['host']->id)->firstOrFail();
        $this->actingAsMcp($w['aOwner'], $this->allScopes());

        // By the participant's own workspace, with the host's record ids.
        $this->getJson("/api/v1/workspaces/{$w['a']->public_id}/attachments/{$attachment->public_id}")->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$w['a']->public_id}/tasks/{$w['shared']->public_id}")->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$w['a']->public_id}/projects")->assertOk()->assertJsonMissing(['name' => 'Synthetic Shared Project']);
        // By the host's, which the participant is not in.
        $this->getJson("/api/v1/workspaces/{$w['host']->public_id}/attachments/{$attachment->public_id}")->assertNotFound();
        $this->actingAs($w['aOwner'], 'web')->get("/workspaces/{$w['host']->public_id}/attachments/{$attachment->public_id}")->assertStatus(403);
        $this->actingAs($w['aOwner'], 'web')->get("/workspaces/{$w['a']->public_id}/attachments/{$attachment->public_id}")->assertNotFound();
    }

    public function test_search_never_returns_the_hosts_or_another_participants_records(): void
    {
        $w = $this->world;
        $secretTerms = ['SECRET-HOST', 'SECRET-OTHER-HOST-PROJECT', 'SECRET-B-', 'Synthetic Shared Project', 'VISIBLE-SHARED-TASK', 'SECRET-OTHER-PROJECT-TASK'];

        foreach ([[$w['aOwner'], 'SECRET-A-LOCAL'], [$w['aAdmin'], 'SECRET-A-LOCAL'], [$w['bOwner'], 'SECRET-B-LOCAL']] as [$user, $own]) {
            $found = $this->actingAs($user, 'web')->getJson('/search?q='.urlencode($own))->assertOk();
            $this->assertStringContainsString($own, (string) $found->getContent(), 'The control: a participant finds its own books.');
            foreach ($secretTerms as $term) {
                if (str_contains($own, 'SECRET-B-') && str_contains($term, 'SECRET-B-')) {
                    continue;
                }
                $response = $this->actingAs($user, 'web')->getJson('/search?q='.urlencode($term))->assertOk();
                $this->assertSame([], $response->json('results'), "A participant searched for {$term} and found something.");
            }
        }

        // The host finds its own, which proves the terms are searchable at all.
        $this->assertNotSame([], $this->actingAs($w['hostOwner'], 'web')->getJson('/search?q='.urlencode('SECRET-OTHER-HOST'))->assertOk()->json('results'));
    }

    public function test_a_participants_comments_do_not_reach_the_hosts_client(): void
    {
        $w = $this->world;
        $this->actingAsMcp($w['aOwner'], $this->allScopes());
        $this->withHeader('Idempotency-Key', 'iso-client-comment')->postJson("/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}/tasks/{$w['shared']->public_id}/comments", ['body' => 'SECRET-PARTICIPANT-REMARK'])->assertCreated();

        $comment = ClientComment::query()->where('body', 'SECRET-PARTICIPANT-REMARK')->firstOrFail();

        $this->assertFalse($comment->is_visible_to_client, 'The portal reads comments flagged for the client, and this one is not.');
        $this->assertSame(0, ClientComment::query()->where('workspace_id', $w['host']->id)->where('is_visible_to_client', true)->where('body', 'SECRET-PARTICIPANT-REMARK')->count());
    }

    public function test_the_page_and_the_task_list_cost_the_same_queries_however_many_tasks_and_assignees_there_are(): void
    {
        $w = $this->world;
        $this->assignment($w['shared'], $w['aMember'], $w['a']);
        $render = function () use ($w): void {
            $this->actingAs($w['aOwner'], 'web')->get("/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}")->assertOk();
        };
        $listing = function () use ($w): void {
            $this->actingAsMcp($w['aOwner'], $this->allScopes());
            $this->getJson("/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}/tasks")->assertOk();
        };
        $grow = function () use ($w): void {
            foreach (range(1, 8) as $number) {
                $task = $this->task($w['project'], "VISIBLE-GROW-{$number}");
                $this->assignment($task, $number % 2 === 0 ? $w['aMember'] : $w['aOnLocal'], $w['a']);
                $this->comment($task, $w['hostOwner'], "VISIBLE-GROW-COMMENT-{$number}", shared: true);
            }
        };

        $this->assertQueryCountIndependentOfRows($render, $grow);
        $this->assertQueryCountIndependentOfRows($listing, fn () => $grow());
    }

    public function test_a_response_for_a_participant_is_a_closed_set_of_keys(): void
    {
        $w = $this->world;
        $this->actingAsMcp($w['aOwner'], $this->allScopes());
        $base = "/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}";

        $keys = array_keys($this->getJson($base)->assertOk()->json('data'));
        sort($keys);

        $this->assertSame(['host_workspace_name', 'id', 'local_project', 'name', 'participation', 'side', 'status', 'viewer', 'web_url'], $keys);
    }

    public function test_a_comment_row_for_a_participant_names_no_ids(): void
    {
        $w = $this->world;
        $this->actingAsMcp($w['aOwner'], $this->allScopes());

        $row = $this->getJson("/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}/tasks/{$w['shared']->public_id}/comments")->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(['id', 'body', 'author_name', 'author_workspace_name', 'shared_with_participants', 'created_at'], array_keys($row));
    }

    public function test_every_participant_response_is_valid_against_the_published_contract(): void
    {
        $w = $this->world;
        $this->actingAsMcp($w['aOwner'], $this->allScopes());
        $base = "/api/v1/workspaces/{$w['a']->public_id}/shared-projects/{$w['project']->public_id}";

        // The test case checks each exchange against the contract; reaching the end means each matched it.
        foreach ([$base, "$base/tasks", "$base/tasks/{$w['shared']->public_id}/comments", "/api/v1/workspaces/{$w['a']->public_id}/shared-projects"] as $path) {
            $this->assertInstanceOf(TestResponse::class, $this->getJson($path)->assertOk());
        }
    }
}
