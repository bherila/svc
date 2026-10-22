<?php

namespace Tests\Feature\Collaboration;

use App\Http\Middleware\ResolveWorkspaceNavigation;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAction;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationReadService;
use App\Services\Collaboration\ContentPatch;
use App\Services\Collaboration\TopicDraft;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class CollaborationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ResolveWorkspaceNavigation::class);
    }

    public function test_native_notes_and_discussions_have_flat_comments_and_server_authorship(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('native');
        $this->actingAs($manager);
        foreach (['note', 'discussion'] as $kind) {
            $this->post(route('collaboration.store', [$workspace, $company]), ['kind' => $kind, 'title' => 'Synthetic '.$kind, 'body' => '<script>literal text</script>', 'project_id' => $project->public_id])->assertRedirect();
            $topic = ClientCollaborationTopic::query()->where('workspace_id', $workspace->id)->where('kind', $kind)->sole();
            $this->assertFalse($topic->is_visible_to_client);
            $this->assertSame($manager->id, $topic->author_user_id);
            $this->post($this->commentUrl($workspace, $company, 'topic', $topic->public_id), ['body' => 'Synthetic reply', 'expected_version' => AgentApiVersion::for($topic)])->assertRedirect();
            $this->get(route('collaboration.show', [$workspace, $company, $topic]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('collaboration/show')->where('topic.body', '<script>literal text</script>')->has('thread.comments', 1)->where('thread.comments.0.author_name', $manager->name));
        }
        $this->assertDatabaseCount('historical_entries', 0);
        $this->assertDatabaseCount('client_invoices', 0);
    }

    public function test_project_managers_can_create_project_topics_but_not_company_wide_topics(): void
    {
        [, $workspace, $company, $project] = $this->tenant('project-manager');
        $manager = $this->member($workspace, $project, 'manager', 'project-manager');
        $this->actingAs($manager);
        $input = ['kind' => 'note', 'title' => 'Synthetic project note', 'body' => 'Synthetic text'];
        $this->post(route('collaboration.store', [$workspace, $company]), $input)->assertForbidden();
        $this->post(route('collaboration.store', [$workspace, $company]), [...$input, 'project_id' => $project->public_id])->assertRedirect();
        $this->get(route('collaboration.index', [$workspace, $company]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('create_scopes', 1)->where('create_scopes.0.id', $project->public_id));
    }

    public function test_contributors_can_comment_and_edit_their_text_but_cannot_publish(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('contributor');
        $topic = $this->topic($manager, $workspace, $company, $project);
        $contributor = $this->member($workspace, $project, 'contributor', 'contributor');
        $this->actingAs($contributor);
        $create = $this->commentUrl($workspace, $company, 'topic', $topic->public_id);
        $this->post($create, ['body' => 'Synthetic contributor text', 'is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($topic)])->assertForbidden();
        $this->post($create, ['body' => 'Synthetic contributor text', 'expected_version' => AgentApiVersion::for($topic)])->assertRedirect();
        $comment = ClientComment::query()->where('workspace_id', $workspace->id)->sole();
        $url = route('collaboration.comments.update', [$workspace, $company, $comment], absolute: false);
        $this->patch($url, ['body' => 'Synthetic changed text', 'expected_version' => AgentApiVersion::for($comment)])->assertRedirect();
        $comment->refresh();
        $this->assertSame('Synthetic changed text', $comment->body);
        $this->assertNotNull($comment->edited_at);
        $this->patch($url, ['is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($comment)])->assertForbidden();
        $this->get(route('collaboration.show', [$workspace, $company, $topic]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('topic.update_href', null)->where('thread.visibility_store_href', null)->where('thread.comments.0.visibility_update_href', null)->where('thread.comments.0.update_href', $url));
    }

    public function test_moderation_does_not_allow_managers_to_rewrite_other_authors(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('moderation');
        $topic = $this->topic($manager, $workspace, $company, $project);
        $contributor = $this->member($workspace, $project, 'contributor', 'moderated');
        $this->actingAs($contributor)->post($this->commentUrl($workspace, $company, 'topic', $topic->public_id), ['body' => 'Original synthetic authored text', 'expected_version' => AgentApiVersion::for($topic)])->assertRedirect();
        $comment = ClientComment::query()->where('workspace_id', $workspace->id)->sole();
        $this->actingAs($manager)->patch(route('collaboration.comments.update', [$workspace, $company, $comment]), ['body' => 'Synthetic replacement', 'expected_version' => AgentApiVersion::for($comment)])->assertForbidden();
        $this->patch(route('collaboration.comments.update', [$workspace, $company, $comment]), ['is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($comment)])->assertRedirect();
        $comment->refresh();
        $this->delete(route('collaboration.comments.destroy', [$workspace, $company, $comment]), ['expected_version' => AgentApiVersion::for($comment)])->assertRedirect();
        $this->get(route('collaboration.show', [$workspace, $company, $topic]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('thread.comments.0.deleted', true)->where('thread.comments.0.body', null)->where('thread.comments.0.update_href', null));
        $this->assertSame($contributor->id, $comment->refresh()->author_user_id);
    }

    public function test_optimistic_versions_reject_stale_edits_and_visibility_omission_preserves_state(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('versions');
        $topic = $this->topic($manager, $workspace, $company, $project, true);
        $url = route('collaboration.update', [$workspace, $company, $topic]);
        $version = AgentApiVersion::for($topic);
        $this->actingAs($manager)->patch($url, ['body' => 'Latest synthetic text', 'expected_version' => $version])->assertRedirect();
        $this->patch($url, ['body' => 'Stale synthetic text', 'expected_version' => $version])->assertStatus(409);
        $topic->refresh();
        $this->assertSame('Latest synthetic text', $topic->body);
        $this->assertTrue($topic->is_visible_to_client);
        $this->patch($url, ['body' => 'Missing version'])->assertSessionHasErrors('expected_version');
    }

    public function test_company_project_and_tenant_boundaries_apply_to_every_parent(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('boundaries');
        [$foreignManager, $foreignWorkspace, $foreignCompany, $foreignProject] = $this->tenant('foreign');
        $foreignTopic = $this->topic($foreignManager, $foreignWorkspace, $foreignCompany, $foreignProject);
        $secondCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic sibling company', 'slug' => 'synthetic-sibling-company']);
        $this->actingAs($manager);
        $this->get(route('collaboration.show', [$workspace, $company, $foreignTopic]))->assertNotFound();
        $this->post(route('collaboration.store', [$workspace, $company]), ['kind' => 'note', 'title' => 'Foreign project', 'body' => 'Synthetic text', 'project_id' => $foreignProject->public_id])->assertNotFound();
        $localTopic = $this->topic($manager, $workspace, $company, $project);
        $this->get(route('collaboration.show', [$workspace, $secondCompany, $localTopic]))->assertNotFound();
        $this->post($this->commentUrl($workspace, $secondCompany, 'topic', $localTopic->public_id), ['body' => 'Wrong company', 'expected_version' => AgentApiVersion::for($localTopic)])->assertNotFound();
        $this->post($this->commentUrl($workspace, $company, 'task', $foreignTopic->public_id), ['body' => 'Wrong parent type', 'expected_version' => AgentApiVersion::for($foreignTopic)])->assertNotFound();
        $viewer = $this->member($workspace, $project, 'viewer', 'viewer');
        $this->actingAs($viewer)->post($this->commentUrl($workspace, $company, 'topic', $localTopic->public_id), ['body' => 'Viewer comment', 'expected_version' => AgentApiVersion::for($localTopic)])->assertForbidden();
    }

    public function test_portal_reads_require_the_entire_visibility_chain_and_have_no_write_capabilities(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('portal');
        $topic = $this->topic($manager, $workspace, $company, $project, true);
        $portal = $this->portalUser($company, 'portal');
        $this->actingAs($manager);
        $this->post($this->commentUrl($workspace, $company, 'topic', $topic->public_id), ['body' => 'Visible synthetic reply', 'is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($topic)])->assertRedirect();
        $this->post($this->commentUrl($workspace, $company, 'topic', $topic->public_id), ['body' => 'Private synthetic reply', 'expected_version' => AgentApiVersion::for($topic)])->assertRedirect();
        $url = route('portal.collaboration.show', [$company, $topic]);
        $this->actingAs($portal)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('topic.update_href', null)->where('topic.visibility_update_href', null)->where('topic.delete_href', null)->where('thread.store_href', null)->has('thread.comments', 1)->where('thread.comments.0.body', 'Visible synthetic reply')->where('topic.is_visible_to_client', null));
        $project->forceFill(['is_visible_to_client' => false])->save();
        $this->get($url)->assertNotFound();
        $this->get(route('portal.collaboration.index', $company))->assertOk()->assertInertia(fn (Assert $page) => $page->has('topics', 0)->where('create_href', null));
        $project->forceFill(['is_visible_to_client' => true])->save();
        $topic->forceFill(['is_visible_to_client' => false])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_project_scoped_portal_grants_do_not_unlock_company_wide_or_sibling_topics(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('scope');
        $granted = $this->topic($manager, $workspace, $company, $project, true);
        $companyTopic = $this->topic($manager, $workspace, $company, null, true);
        $sibling = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic ungranted project', 'is_visible_to_client' => true]);
        $siblingTopic = $this->topic($manager, $workspace, $company, $sibling, true);
        $portal = $this->portalUser($company, 'scope', $project);
        $this->actingAs($portal)->get(route('portal.collaboration.index', $company))->assertOk()->assertInertia(fn (Assert $page) => $page->has('topics', 1)->where('topics.0.id', $granted->public_id));
        $this->get(route('portal.collaboration.show', [$company, $companyTopic]))->assertNotFound();
        $this->get(route('portal.collaboration.show', [$company, $siblingTopic]))->assertNotFound();
        $company->portalUsers()->detach($portal->id);
        $this->get(route('portal.collaboration.show', [$company, $granted]))->assertNotFound();
    }

    public function test_explicit_portal_audience_never_inherits_dual_identity_private_reads(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('dual');
        $topic = $this->topic($manager, $workspace, $company, $project, false);
        $this->actingAs($manager)->get(route('collaboration.show', [$workspace, $company, $topic]))->assertOk();
        $this->get(route('portal.collaboration.show', [$company, $topic]).'?audience=internal')->assertNotFound();
    }

    public function test_revocation_and_deleted_parents_prevent_held_write_urls(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('revoke');
        $topic = $this->topic($manager, $workspace, $company, $project, true);
        $author = $this->member($workspace, $project, 'contributor', 'revoked-author');
        $this->actingAs($author)->post($this->commentUrl($workspace, $company, 'topic', $topic->public_id), ['body' => 'Synthetic authored reply', 'expected_version' => AgentApiVersion::for($topic)])->assertRedirect();
        $comment = ClientComment::query()->where('workspace_id', $workspace->id)->sole();
        $project->members()->detach($author->id);
        $this->patch(route('collaboration.comments.update', [$workspace, $company, $comment]), ['body' => 'Revoked edit', 'expected_version' => AgentApiVersion::for($comment)])->assertForbidden();
        $this->actingAs($manager)->delete(route('collaboration.destroy', [$workspace, $company, $topic]), ['expected_version' => AgentApiVersion::for($topic)])->assertRedirect();
        $this->get(route('collaboration.show', [$workspace, $company, $topic]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('topic.body', null)->where('topic.deleted', true)->where('thread', null));
        $this->post($this->commentUrl($workspace, $company, 'topic', $topic->public_id), ['body' => 'After removal', 'expected_version' => AgentApiVersion::for($topic->refresh())])->assertForbidden();
    }

    public function test_task_comments_use_task_and_project_visibility_and_reject_wrong_company_context(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('task');
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic task', 'is_visible_to_client' => true]);
        $this->actingAs($manager)->post($this->commentUrl($workspace, $company, 'task', $task->public_id), ['body' => 'Synthetic task comment', 'is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($task)])->assertRedirect();
        $portal = $this->portalUser($company, 'task');
        $url = route('portal.collaboration.comments.index', [$company, 'task', $task->public_id]);
        $this->actingAs($portal)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('thread.comments', 1));
        $task->forceFill(['is_visible_to_client' => false])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_spoofed_authorship_and_parent_reassignment_are_rejected(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('immutable');
        $this->actingAs($manager)->post(route('collaboration.store', [$workspace, $company]), ['kind' => 'note', 'title' => 'Synthetic spoof', 'body' => 'Synthetic body', 'author_user_id' => $manager->id])->assertSessionHasErrors('author_user_id');
        $topic = $this->topic($manager, $workspace, $company, $project);
        $this->patch(route('collaboration.update', [$workspace, $company, $topic]), ['project_id' => $project->public_id, 'expected_version' => AgentApiVersion::for($topic)])->assertSessionHasErrors('project_id');
        $this->expectException(LogicException::class);
        $topic->forceFill(['author_name' => 'Synthetic rewritten author'])->save();
    }

    public function test_comments_are_paged_without_losing_tombstones_or_private_filtering(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('pages');
        $topic = $this->topic($manager, $workspace, $company, $project, true);
        for ($index = 1; $index <= 35; $index++) {
            ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'client_collaboration_topic_id' => $topic->id, 'body' => 'Synthetic reply '.$index, 'author_user_id' => $manager->id, 'author_name' => $manager->name, 'is_visible_to_client' => true]);
        }
        $this->actingAs($manager)->get(route('collaboration.show', [$workspace, $company, $topic]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('thread.comments', 30)->where('thread.comments.0.body', 'Synthetic reply 1')->where('thread.next_href', fn ($href) => str_contains($href, 'comments_page=2')));
        $this->get(route('collaboration.comments.index', [$workspace, $company, 'topic', $topic->public_id]).'?comments_page=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('thread.comments', 5)->where('thread.comments.0.body', 'Synthetic reply 31')->where('thread.next_href', null));
        $response = $this->post($this->commentUrl($workspace, $company, 'topic', $topic->public_id), ['body' => 'Synthetic newly appended reply', 'expected_version' => AgentApiVersion::for($topic)]);
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('?comment=', $location);
        $this->assertStringContainsString('#comment-', $location);
        $this->get(explode('#', $location)[0])->assertOk()->assertInertia(fn (Assert $page) => $page->has('thread.comments', 6)->where('thread.comments.5.body', 'Synthetic newly appended reply'));
    }

    public function test_content_and_reply_writes_roll_back_without_touching_history_or_billing(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('rollback');
        DB::beginTransaction();
        $topic = $this->topic($manager, $workspace, $company, $project);
        app(CollaborationAction::class)->updateTopic($manager, $workspace, $company, $topic->public_id, ContentPatch::fromInput(['body' => 'Synthetic changed body', 'expected_version' => AgentApiVersion::for($topic)], true), CollaborationAudience::Internal);
        DB::rollBack();
        $this->assertDatabaseCount('client_collaboration_topics', 0);
        $this->assertDatabaseCount('client_comments', 0);
        $this->assertDatabaseCount('historical_entries', 0);
        $this->assertDatabaseCount('client_invoices', 0);
    }

    public function test_malformed_same_tenant_lineage_is_refused_and_comment_parent_is_exclusive(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('malformed');
        $other = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other company', 'slug' => 'synthetic-malformed-other']);
        $topic = ClientCollaborationTopic::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $other->id,
            'client_project_id' => $project->id, 'kind' => 'note', 'title' => 'Synthetic malformed lineage', 'body' => 'Never disclosed', 'author_name' => 'Synthetic author']);
        $this->actingAs($manager)->get(route('collaboration.show', [$workspace, $other, $topic]))->assertNotFound();
        $this->get(route('collaboration.index', [$workspace, $other]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('topics', 0));
        $this->expectException(LogicException::class);
        ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'body' => 'No parent', 'author_name' => 'Synthetic author']);
    }

    public function test_topic_listing_does_not_query_permissions_per_row(): void
    {
        [$manager, $workspace, $company, $project] = $this->tenant('bounded');
        $read = app(CollaborationReadService::class);
        $this->topic($manager, $workspace, $company, $project);
        DB::enableQueryLog();
        $read->listing($manager, $workspace, $company, CollaborationAudience::Internal, null, null, 1);
        $firstCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($index = 0; $index < 31; $index++) {
            $this->topic($manager, $workspace, $company, $project);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $read->listing($manager, $workspace, $company, CollaborationAudience::Internal, null, null, 1);
        $secondCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($firstCount, $secondCount);
        $this->assertCount(30, $result['topics']);
        $this->assertNotNull($result['next_href']);
    }

    /** @return array{User,Workspace,ClientCompany,ClientProject} */
    private function tenant(string $suffix): array
    {
        $manager = User::factory()->create(['email' => 'collab-'.$suffix.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic collaboration '.$suffix, 'slug' => 'collab-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company '.$suffix, 'slug' => 'collab-company-'.$suffix]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project '.$suffix, 'is_visible_to_client' => true]);

        return [$manager, $workspace, $company, $project];
    }

    private function topic(User $manager, Workspace $workspace, ClientCompany $company, ?ClientProject $project, bool $visible = false): ClientCollaborationTopic
    {
        return app(CollaborationAction::class)->createTopic($manager, $workspace, $company, TopicDraft::fromInput(['kind' => 'discussion', 'title' => 'Synthetic topic', 'body' => 'Synthetic body', 'project_id' => $project?->public_id, 'is_visible_to_client' => $visible]), CollaborationAudience::Internal);
    }

    private function member(Workspace $workspace, ClientProject $project, string $role, string $suffix): User
    {
        $user = User::factory()->create(['email' => 'collab-member-'.$suffix.'@synthetic.test']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        $project->members()->attach($user->id, ['workspace_id' => $workspace->id, 'role' => $role]);

        return $user;
    }

    private function portalUser(ClientCompany $company, string $suffix, ?ClientProject $project = null): User
    {
        $user = User::factory()->create(['email' => 'collab-portal-'.$suffix.'@synthetic.test']);
        $company->portalUsers()->attach($user->id, ['role' => 'viewer', 'access_scope' => $project === null ? 'company' : 'projects']);
        if ($project !== null) {
            $membership = ClientCompanyMembership::query()->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id)->where('user_id', $user->id)->firstOrFail();
            $membership->scopedProjects()->attach($project->id, ['workspace_id' => $company->workspace_id]);
        }

        return $user;
    }

    private function commentUrl(Workspace $workspace, ClientCompany $company, string $type, string $id): string
    {
        return route('collaboration.comments.store', [$workspace, $company, $type, $id]);
    }
}
