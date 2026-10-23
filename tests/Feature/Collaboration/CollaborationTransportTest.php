<?php

namespace Tests\Feature\Collaboration;

use App\Models\AgentMutationReceipt;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAction;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\TopicDraft;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class CollaborationTransportTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.collaboration_writes_enabled' => true]);
    }

    public function test_rest_crud_receipts_versions_and_tombstones_are_shared(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $this->actingAsMcp($actor, ['collaboration:read', 'collaboration:write']);
        $base = $this->base($workspace, $company);
        $body = ['kind' => 'note', 'title' => 'Synthetic REST note', 'body' => '<script>literal</script>', 'project_id' => $project->public_id, 'is_visible_to_client' => true];
        $created = $this->withHeader('Idempotency-Key', 'synthetic-topic-create')->postJson($base.'/topics', $body)->assertCreated()->json('data');
        $this->postJson($base.'/topics', $body)->assertCreated()->assertJsonPath('data.id', $created['id']);
        $this->postJson($base.'/topics', [...$body, 'body' => 'Conflicting input'])->assertConflict();
        $this->getJson($base.'/topics')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'/topics/'.$created['id'])->assertOk()->assertJsonPath('data.body', $body['body'])->assertJsonMissingPath('data.update_href');
        $reply = $this->withHeader('Idempotency-Key', 'synthetic-comment-create')->postJson($base.'/comments/topic/'.$created['id'], ['body' => 'Synthetic first reply', 'expected_version' => $created['version']])->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'synthetic-comment-second')->postJson($base.'/comments/topic/'.$created['id'], ['body' => 'Synthetic second reply', 'expected_version' => $created['version']])->assertCreated();
        $list = $this->getJson($base.'/comments/topic/'.$created['id'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reply['id']);
        $this->getJson($list->json('next_href'))->assertOk()->assertJsonPath('data.0.body', 'Synthetic second reply');
        $this->getJson($base.'/comments/topic/'.$created['id'].'?limit=31')->assertUnprocessable();
        $updated = $this->withHeader('Idempotency-Key', 'synthetic-comment-update')->patchJson($base.'/comments/'.$reply['id'], ['body' => 'Synthetic edited reply', 'expected_version' => $reply['version']])->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'synthetic-comment-stale')->patchJson($base.'/comments/'.$reply['id'], ['body' => 'Stale text', 'expected_version' => $reply['version']])->assertConflict();
        $delete = ['expected_version' => $updated['version'], 'confirm' => true];
        $this->withHeader('Idempotency-Key', 'synthetic-comment-delete')->deleteJson($base.'/comments/'.$reply['id'], $delete)->assertOk()->assertJsonPath('data.deleted', true)->assertJsonPath('data.body', null);
        $this->deleteJson($base.'/comments/'.$reply['id'], $delete)->assertOk()->assertJsonPath('data.deleted', true);
        $changed = $this->withHeader('Idempotency-Key', 'synthetic-topic-update')->patchJson($base.'/topics/'.$created['id'], ['title' => 'Synthetic revised note', 'expected_version' => $created['version']])->assertOk()->json('data');
        $this->assertTrue($changed['is_visible_to_client']);
        $this->withHeader('Idempotency-Key', 'synthetic-topic-delete')->deleteJson($base.'/topics/'.$created['id'], ['expected_version' => $changed['version'], 'confirm' => false])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'synthetic-topic-delete-yes')->deleteJson($base.'/topics/'.$created['id'], ['expected_version' => $changed['version'], 'confirm' => true])->assertOk()->assertJsonPath('data.body', null);
        $this->assertDatabaseCount('client_collaboration_topics', 1);
        $this->assertDatabaseCount('client_comments', 2);
        $this->assertDatabaseCount('historical_entries', 0);
    }

    public function test_scopes_cutovers_and_replay_revocation_are_enforced(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $base = $this->base($workspace, $company);
        $this->actingAsMcp($actor, ['collaboration:write']);
        $this->getJson($base.'/topics')->assertForbidden();
        $body = ['kind' => 'discussion', 'title' => 'Synthetic scope topic', 'body' => 'Synthetic text', 'project_id' => $project->public_id];
        $created = $this->withHeader('Idempotency-Key', 'synthetic-scope-create')->postJson($base.'/topics', $body)->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'synthetic-no-read')->patchJson($base.'/topics/'.$created['id'], ['expected_version' => $created['version'], 'body' => 'No read scope'])->assertForbidden();
        config(['agent_api.collaboration_writes_enabled' => false]);
        $this->postJson($base.'/topics', $body)->assertNotFound();
        config(['agent_api.collaboration_writes_enabled' => true]);
        $workspace->memberships()->where('user_id', $actor->id)->update(['role' => 'member']);
        $this->withHeader('Idempotency-Key', 'synthetic-scope-create')->postJson($base.'/topics', $body)->assertForbidden();
    }

    public function test_portal_and_dual_identities_cannot_select_an_internal_audience_or_write(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $private = $this->topic($actor, $workspace, $company, $project, false);
        $public = $this->topic($actor, $workspace, $company, $project, true);
        $company->portalUsers()->attach($actor->id, ['role' => 'client', 'access_scope' => 'company']);
        $this->actingAsMcp($actor, ['collaboration:read', 'collaboration:write']);
        $base = $this->base($workspace, $company);
        $this->getJson($base.'/topics?audience=internal')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $public->public_id);
        $this->getJson($base.'/topics/'.$private->public_id)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'synthetic-dual-write')->postJson($base.'/comments/topic/'.$public->public_id, ['body' => 'Forbidden dual reply', 'expected_version' => AgentApiVersion::for($public)])->assertForbidden();
    }

    public function test_dual_owner_portal_reads_obey_exact_project_grants_and_company_scope(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $allowed = $this->topic($actor, $workspace, $company, $project, true);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic ungranted project', 'is_visible_to_client' => true]);
        $denied = $this->topic($actor, $workspace, $company, $otherProject, true);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic unrelated company', 'slug' => 'synthetic-unrelated-company']);
        $company->portalUsers()->attach($actor->id, ['role' => 'client', 'access_scope' => 'projects']);
        $membership = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $actor->id)->sole();
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsMcp($actor, ['collaboration:read']);
        $base = $this->base($workspace, $company);
        $this->getJson($base.'/topics')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->public_id);
        $this->getJson($base.'/topics/'.$allowed->public_id)->assertOk();
        $this->getJson($base.'/topics/'.$denied->public_id)->assertNotFound();
        $this->getJson($base.'/comments/topic/'.$denied->public_id)->assertNotFound();
        $this->getJson($this->base($workspace, $otherCompany).'/topics')->assertForbidden();
        $project->update(['is_visible_to_client' => false]);
        $this->getJson($base.'/topics')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/topics/'.$allowed->public_id)->assertNotFound();
    }

    public function test_mcp_and_rest_use_the_same_receipts_and_preserve_full_unicode(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $this->actingAsMcp($actor, [AgentApiScopes::MCP_USE, 'collaboration:read', 'collaboration:write']);
        $session = $this->initialize();
        $this->assertContains('collaboration.topics.create', $this->toolNames($session));
        $body = str_repeat('🧪', 50000);
        $arguments = ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id,
            'kind' => 'discussion', 'title' => 'Synthetic Unicode discussion', 'body' => $body,
            'project_id' => $project->public_id, 'idempotency_key' => 'synthetic-unicode-mcp'];
        $response = $this->callTool($session, 'collaboration.topics.create', $arguments);
        $this->assertFalse($response['result']['isError'] ?? true);
        $created = $response['result']['structuredContent']['data'];
        $this->assertSame($body, $created['body']);
        $this->assertSame(1_048_576, config('agent_api.mcp_max_body_bytes'));
        $this->assertSame(2_097_152, config('agent_api.mcp_max_result_bytes'));
        $this->assertSame(4_194_304, config('agent_api.mcp_max_response_body_bytes'));
        $restBody = array_intersect_key($arguments, array_flip(['kind', 'title', 'body', 'project_id']));
        $this->withHeader('Idempotency-Key', 'synthetic-unicode-mcp')->postJson($this->base($workspace, $company).'/topics', $restBody)->assertCreated()->assertJsonPath('data.id', $created['id']);
        $read = $this->callTool($session, 'collaboration.topics.get', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, 'topic_id' => $created['id']]);
        $this->assertFalse($read['result']['isError'] ?? true);
        $this->assertSame($body, $read['result']['structuredContent']['data']['body']);
        $this->assertSame(1, AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('operation', 'collaboration.topics.create')->count());
    }

    public function test_comment_cursors_bind_context_and_recheck_portal_flags_and_tombstones_before_limits(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $topic = $this->topic($actor, $workspace, $company, $project, true);
        $comments = [];
        for ($index = 0; $index < 4; $index++) {
            $comments[] = ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'client_project_id' => $project->id, 'client_collaboration_topic_id' => $topic->id,
                'body' => 'Synthetic scoped reply '.$index, 'author_user_id' => $actor->id, 'author_name' => $actor->name, 'is_visible_to_client' => $index !== 0]);
        }
        $url = $this->base($workspace, $company).'/comments/topic/'.$topic->public_id;
        $this->actingAsMcp($actor, ['collaboration:read']);
        $cursor = $this->getJson($url)->assertOk()->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $tail = '?cursor='.rawurlencode($cursor);
        $other = $this->topic($actor, $workspace, $company, $project, true);
        $this->getJson($this->base($workspace, $company).'/comments/topic/'.$other->public_id.$tail)->assertUnprocessable();
        $task = new ClientTask;
        $task->forceFill(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
            'public_id' => $topic->public_id, 'title' => 'Synthetic same UUID different parent kind'])->save();
        $this->getJson($this->base($workspace, $company).'/comments/task/'.$task->public_id.$tail)->assertUnprocessable();
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other cursor company', 'slug' => 'synthetic-other-cursor-company']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Synthetic other cursor project']);
        $otherTopic = $this->topic($actor, $workspace, $otherCompany, $otherProject, true);
        $this->getJson($this->base($workspace, $otherCompany).'/comments/topic/'.$otherTopic->public_id.$tail)->assertUnprocessable();
        $otherWorkspace = Workspace::query()->create(['name' => 'Synthetic other cursor workspace', 'slug' => 'synthetic-other-cursor-workspace']);
        $otherWorkspace->memberships()->create(['user_id' => $actor->id, 'role' => 'owner']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Synthetic foreign cursor company', 'slug' => 'synthetic-foreign-cursor-company']);
        $foreignProject = ClientProject::query()->create(['workspace_id' => $otherWorkspace->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Synthetic foreign cursor project']);
        $foreignTopic = $this->topic($actor, $otherWorkspace, $foreignCompany, $foreignProject, true);
        $this->getJson($this->base($otherWorkspace, $foreignCompany).'/comments/topic/'.$foreignTopic->public_id.$tail)->assertUnprocessable();
        $admin = User::factory()->create(['email' => 'synthetic-cursor-admin@example.test']);
        $workspace->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $this->actingAsMcp($admin, ['collaboration:read']);
        $this->getJson($url.$tail)->assertUnprocessable();
        $this->actingAsMcp($actor, ['collaboration:read']);
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'user_id' => $actor->id, 'role' => 'client', 'access_scope' => 'company']);
        $this->getJson($url.$tail)->assertUnprocessable();
        $page = $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $comments[1]->public_id)->json();
        $comments[2]->forceFill(['deleted_at' => now()])->save();
        $this->getJson($page['next_href'])->assertOk()->assertJsonPath('data.0.id', $comments[2]->public_id)
            ->assertJsonPath('data.0.deleted', true)->assertJsonPath('data.0.body', null);
        $project->update(['is_visible_to_client' => false]);
        $this->getJson($page['next_href'])->assertNotFound();
    }

    public function test_agent_topic_get_does_not_fetch_unused_comment_bodies(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $topic = $this->topic($actor, $workspace, $company, $project, true);
        $this->actingAsMcp($actor, ['collaboration:read']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson($this->base($workspace, $company).'/topics/'.$topic->public_id)->assertOk();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame([], array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'client_comments'))));
    }

    /** @return list<array{string}> */
    public static function largeCommentBodies(): array
    {
        return [['unicode'], ['backslashes']];
    }

    #[DataProvider('largeCommentBodies')]
    public function test_maximum_comment_limit_follows_byte_bounded_cursors_without_losing_full_bodies(string $kind): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $topic = app(CollaborationAction::class)->createTopic($actor, $workspace, $company,
            TopicDraft::fromInput(['kind' => 'discussion', 'title' => 'Synthetic bounded comments', 'body' => 'Synthetic parent',
                'project_id' => $project->public_id, 'is_visible_to_client' => true]), CollaborationAudience::Internal);
        $body = str_repeat($kind === 'unicode' ? '🧪' : chr(92), 50000);
        $this->assertSame(50000, mb_strlen($body));
        $ids = [];
        for ($index = 0; $index < 13; $index++) {
            $comment = ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'client_project_id' => $project->id, 'client_collaboration_topic_id' => $topic->id,
                'body' => $body, 'author_user_id' => $actor->id, 'author_name' => 'Synthetic bounded author', 'is_visible_to_client' => true]);
            $ids[] = $comment->public_id;
        }
        $this->actingAsMcp($actor, ['mcp:use', 'collaboration:read']);
        $session = $this->initialize();
        $arguments = ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id,
            'parent_type' => 'topic', 'parent_id' => $topic->public_id, 'limit' => 30];
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $response = $this->mcp(['jsonrpc' => '2.0', 'id' => 100, 'method' => 'tools/call', 'params' => ['name' => 'collaboration.comments.list',
                'arguments' => [...$arguments, ...($cursor === null ? [] : ['cursor' => $cursor])]]], $session)->assertOk();
            $result = $response->json('result');
            $this->assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
            $page = $result['structuredContent'];
            $this->assertNotEmpty($page['data']);
            $this->assertLessThanOrEqual(AgentPageBudget::dataBytes(), strlen(json_encode($page['data'], JSON_THROW_ON_ERROR)));
            $this->assertLessThanOrEqual(config('agent_api.mcp_max_result_bytes'), strlen(json_encode($result, JSON_THROW_ON_ERROR)));
            $wire = $response->getContent();
            $this->assertIsString($wire);
            $this->assertLessThanOrEqual(config('agent_api.mcp_max_response_body_bytes'), strlen($wire));
            foreach ($page['data'] as $row) {
                $this->assertSame($body, $row['body']);
                $seen[] = $row['id'];
            }
            $this->assertNull($page['previous_href']);
            $cursor = $page['meta']['next_cursor'];
            $this->assertSame($cursor === null, $page['next_href'] === null);
            if ($cursor !== null) {
                $this->assertStringContainsString('cursor=', $page['next_href']);
            }
            $this->assertLessThan(20, ++$pages);
        } while ($cursor !== null);
        $this->assertGreaterThan(1, $pages);
        $this->assertSame($ids, $seen);

        $url = $this->base($workspace, $company).'/comments/topic/'.$topic->public_id;
        $this->getJson($url.'?cursor='.rawurlencode(base64_encode('1')))->assertUnprocessable();
        $this->getJson($url.'?comments_page=2')->assertUnprocessable();
        $this->getJson($url.'?comment='.$ids[0])->assertUnprocessable();
    }

    public function test_mcp_all_topic_and_comment_operations_share_the_native_contract(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $this->actingAsMcp($actor, [AgentApiScopes::MCP_USE, 'collaboration:read', 'collaboration:write']);
        $session = $this->initialize();
        $scope = ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id];
        $call = function (string $operation, array $arguments) use ($session, $scope): array {
            $result = $this->callTool($session, 'collaboration.'.$operation, $scope + $arguments)['result'];
            $this->assertFalse($result['isError'] ?? true, $operation);

            return $result['structuredContent'];
        };
        $topic = $call('topics.create', ['kind' => 'note', 'title' => 'Synthetic MCP note', 'body' => 'Synthetic MCP text', 'project_id' => $project->public_id, 'is_visible_to_client' => true, 'idempotency_key' => 'synthetic-mcp-create'])['data'];
        $this->assertCount(1, $call('topics.list', ['kind' => 'note'])['data']);
        $topic = $call('topics.update', ['topic_id' => $topic['id'], 'expected_version' => $topic['version'], 'is_visible_to_client' => false, 'idempotency_key' => 'synthetic-mcp-hide'])['data'];
        $this->assertFalse($topic['is_visible_to_client']);
        $this->assertSame('Synthetic MCP text', $topic['body']);
        $comment = $call('comments.create', ['parent_type' => 'topic', 'parent_id' => $topic['id'], 'body' => 'Synthetic MCP comment', 'expected_version' => $topic['version'], 'idempotency_key' => 'synthetic-mcp-comment'])['data'];
        $list = $call('comments.list', ['parent_type' => 'topic', 'parent_id' => $topic['id']]);
        $this->assertSame($comment['id'], $list['data'][0]['id']);
        $this->assertStringContainsString('?comment='.$comment['id'].'#comment-', $list['data'][0]['web_url']);
        $comment = $call('comments.update', ['comment_id' => $comment['id'], 'expected_version' => $comment['version'], 'body' => 'Synthetic edited MCP comment', 'idempotency_key' => 'synthetic-mcp-comment-edit'])['data'];
        $this->assertSame('Synthetic edited MCP comment', $comment['body']);
        $this->assertTrue($call('comments.delete', ['comment_id' => $comment['id'], 'expected_version' => $comment['version'], 'confirm' => true, 'idempotency_key' => 'synthetic-mcp-comment-delete'])['data']['deleted']);
        $this->assertTrue($call('topics.delete', ['topic_id' => $topic['id'], 'expected_version' => $topic['version'], 'confirm' => true, 'idempotency_key' => 'synthetic-mcp-topic-delete'])['data']['deleted']);
        $events = ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->get();
        $this->assertCount(6, $events);
        $this->assertTrue($events->every(fn ($event): bool => $event->payload === null || $event->payload === []));
    }

    public function test_cli_previews_roll_back_content_and_receipts_and_apply_is_independent_of_agent_flags(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        config(['agent_api.writes_enabled' => false, 'agent_api.collaboration_writes_enabled' => false]);
        $path = tempnam(sys_get_temp_dir(), 'synthetic-collaboration-json-');
        chmod($path, 0600);
        $body = str_repeat('🧪', 50000);
        file_put_contents($path, json_encode(['idempotency_key' => 'synthetic-cli-create', 'payload' => ['kind' => 'note', 'title' => 'Synthetic CLI note', 'body' => $body, 'project_id' => $project->public_id]], JSON_THROW_ON_ERROR));
        try {
            $args = ['operation' => 'topics.create', 'workspace' => $workspace->public_id, 'client' => $company->public_id, '--actor' => $actor->public_id, '--input' => $path];
            $this->assertSame(0, Artisan::call('svc:collaboration', $args));
            $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['dry_run']);
            $this->assertDatabaseCount('client_collaboration_topics', 0);
            $this->assertDatabaseCount('agent_mutation_receipts', 0);
            $this->assertSame(0, Artisan::call('svc:collaboration', [...$args, '--apply' => true]));
            $this->assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['dry_run']);
            $this->assertSame($body, ClientCollaborationTopic::query()->where('workspace_id', $workspace->id)->sole()->body);
            $this->assertSame(0, Artisan::call('svc:collaboration', [...$args, '--apply' => true]));
            $this->assertDatabaseCount('client_collaboration_topics', 1);
            $this->assertDatabaseCount('agent_mutation_receipts', 1);
            chmod($path, 0644);
            $this->assertSame(2, Artisan::call('svc:collaboration', $args));
        } finally {
            unlink($path);
        }
    }

    public function test_cli_supports_full_topic_and_comment_lifecycle_with_public_uuid_envelopes(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'synthetic-collaboration-lifecycle-');
        chmod($path, 0600);
        $call = function (string $operation, array $input) use ($workspace, $company, $actor, $path): array {
            file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
            $this->assertSame(0, Artisan::call('svc:collaboration', ['operation' => $operation, 'workspace' => $workspace->public_id, 'client' => $company->public_id, '--actor' => $actor->public_id, '--input' => $path, '--apply' => true]));

            return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        };
        try {
            $topic = $call('topics.create', ['idempotency_key' => 'synthetic-cli-topic', 'payload' => ['kind' => 'discussion', 'title' => 'Synthetic CLI discussion', 'body' => 'Synthetic CLI text', 'project_id' => $project->public_id]])['data'];
            $this->assertCount(1, $call('topics.list', ['payload' => ['kind' => 'discussion']])['data']);
            $this->assertSame($topic['id'], $call('topics.get', ['id' => $topic['id']])['data']['id']);
            $topic = $call('topics.update', ['id' => $topic['id'], 'idempotency_key' => 'synthetic-cli-topic-update', 'payload' => ['title' => 'Synthetic updated CLI discussion', 'expected_version' => $topic['version']]])['data'];
            $comment = $call('comments.create', ['id' => $topic['id'], 'parent_type' => 'topic', 'idempotency_key' => 'synthetic-cli-comment', 'payload' => ['body' => 'Synthetic CLI reply', 'expected_version' => $topic['version']]])['data'];
            $second = $call('comments.create', ['id' => $topic['id'], 'parent_type' => 'topic', 'idempotency_key' => 'synthetic-cli-second-comment', 'payload' => ['body' => 'Synthetic CLI second reply', 'expected_version' => $topic['version']]])['data'];
            $page = $call('comments.list', ['id' => $topic['id'], 'parent_type' => 'topic']);
            $this->assertSame($comment['id'], $page['data'][0]['id']);
            $this->assertNotNull($page['meta']['next_cursor']);
            $next = $call('comments.list', ['id' => $topic['id'], 'parent_type' => 'topic', 'payload' => ['cursor' => $page['meta']['next_cursor']]]);
            $this->assertSame($second['id'], $next['data'][0]['id']);
            $this->assertNull($next['meta']['next_cursor']);
            $comment = $call('comments.update', ['id' => $comment['id'], 'idempotency_key' => 'synthetic-cli-comment-update', 'payload' => ['body' => 'Synthetic updated CLI reply', 'expected_version' => $comment['version']]])['data'];
            $this->assertTrue($call('comments.delete', ['id' => $comment['id'], 'idempotency_key' => 'synthetic-cli-comment-delete', 'payload' => ['expected_version' => $comment['version'], 'confirm' => true]])['data']['deleted']);
            $this->assertTrue($call('topics.delete', ['id' => $topic['id'], 'idempotency_key' => 'synthetic-cli-topic-delete', 'payload' => ['expected_version' => $topic['version'], 'confirm' => true]])['data']['deleted']);
        } finally {
            unlink($path);
        }
    }

    /** @return array{User,Workspace,ClientCompany,ClientProject} */
    private function fixture(): array
    {
        $actor = User::factory()->create(['email' => 'collaboration-transport@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic transport workspace', 'slug' => 'synthetic-collaboration-transport']);
        $workspace->memberships()->create(['user_id' => $actor->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic transport company', 'slug' => 'synthetic-transport-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic transport project', 'is_visible_to_client' => true]);

        return [$actor, $workspace, $company, $project];
    }

    private function topic(User $actor, Workspace $workspace, ClientCompany $company, ClientProject $project, bool $visible): ClientCollaborationTopic
    {
        return app(CollaborationAction::class)->createTopic($actor, $workspace, $company, TopicDraft::fromInput(['kind' => 'note', 'title' => 'Synthetic audience topic', 'body' => 'Synthetic content', 'project_id' => $project->public_id, 'is_visible_to_client' => $visible]), CollaborationAudience::Internal);
    }

    private function base(Workspace $workspace, ClientCompany $company): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id.'/collaboration';
    }
}
