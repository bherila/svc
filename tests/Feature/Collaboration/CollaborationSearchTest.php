<?php

namespace Tests\Feature\Collaboration;

use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Search\CollaborationSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class CollaborationSearchTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    public function test_native_text_search_finds_full_bodies_and_links_to_authorized_content(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $note = $this->topic($actor, $workspace, $company, $project);
        $discussion = $this->topic($actor, $workspace, $company, null, 'discussion');
        $comment = $this->comment($actor, $workspace, $company, $project, $note);
        $results = $this->actingAs($actor)->getJson('/search?q=Needle50%25_')->assertOk()->json('results');
        $this->assertSame(['note', 'discussion', 'comment'], array_column($results, 'kind'));
        $byId = array_column($results, null, 'id');
        $this->assertSame(route('collaboration.show', [$workspace, $company, $note->public_id], absolute: false), $byId[$note->public_id]['href']);
        $this->assertStringContainsString('comment='.$comment->public_id, $byId[$comment->public_id]['href']);
        $this->assertStringContainsString('#comment-'.$comment->public_id, $byId[$comment->public_id]['href']);
        $this->get($byId[$discussion->public_id]['href'])->assertOk();
        $this->get($byId[$comment->public_id]['href'])->assertOk();
        $this->getJson('/search?q=Needle50x')->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_dual_owner_api_search_obeys_read_scopes_exact_grants_and_parent_visibility_before_limits(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $allowed = $this->topic($actor, $workspace, $company, $project, visible: true);
        $comment = $this->comment($actor, $workspace, $company, $project, $allowed, true);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Other synthetic project', 'is_visible_to_client' => true]);
        for ($index = 0; $index < 7; $index++) {
            $this->topic($actor, $workspace, $company, $otherProject, visible: true);
        }
        $this->topic($actor, $workspace, $company, null, visible: true);
        $this->topic($actor, $workspace, $company, $project, visible: false);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other synthetic company', 'slug' => 'other-synthetic-company']);
        $this->topic($actor, $workspace, $otherCompany, null, visible: true);
        $company->portalUsers()->attach($actor->id, ['role' => 'client', 'access_scope' => 'projects']);
        $membership = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $actor->id)->sole();
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()]);
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/search?q=Needle50%25_';
        $this->actingAsMcp($actor, ['identity:read']);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsMcp($actor, ['identity:read', 'collaboration:read']);
        $results = $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing([$allowed->public_id, $comment->public_id], array_column($results, 'id'));
        foreach ($results as $result) {
            $this->assertStringStartsWith('/portal/'.$company->public_id.'/collaboration/', $result['href']);
        }
        $allowed->update(['is_visible_to_client' => false]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $allowed->update(['is_visible_to_client' => true]);
        $project->update(['is_visible_to_client' => false]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_dual_owner_native_project_and_task_results_open_the_operator_family(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $project->update(['name' => 'Needle50%_ synthetic private project', 'is_visible_to_client' => false]);
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Needle50%_ synthetic private task', 'status' => 'pending', 'is_visible_to_client' => false]);
        $company->portalUsers()->attach($actor->id, ['role' => 'client', 'access_scope' => 'projects']);
        $results = $this->actingAs($actor)->getJson('/search?q=Needle50%25_')->assertOk()->json('results');
        $byId = array_column($results, null, 'id');
        foreach ([$project, $task] as $record) {
            $this->assertArrayHasKey($record->public_id, $byId);
            $this->assertStringStartsWith('/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id, $byId[$record->public_id]['href']);
            $this->get($byId[$record->public_id]['href'])->assertOk();
        }
        $this->actingAsMcp($actor, ['identity:read', 'projects:read', 'tasks:read']);
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/search?q=Needle50%25_')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_comment_search_rejects_malformed_company_and_project_lineage_and_tombstones(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $topic = $this->topic($actor, $workspace, $company, $project, visible: true);
        $valid = $this->comment($actor, $workspace, $company, $project, $topic, true);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other company', 'slug' => 'other-company']);
        $bad = $this->comment($actor, $workspace, $company, $project, $topic, true);
        DB::table('client_comments')->where('id', $bad->id)->update(['client_company_id' => $foreignCompany->id]);
        $badProject = $this->comment($actor, $workspace, $company, $project, $topic, true);
        DB::table('client_comments')->where('id', $badProject->id)->update(['client_project_id' => null]);
        $search = app(CollaborationSearch::class);
        $results = $search->forWorkspace($actor, $workspace, 'Needle50%_', [], CollaborationAudience::Internal);
        $this->assertEqualsCanonicalizing([$topic->public_id, $valid->public_id], array_map(fn ($row): string => $row->id, $results));
        $topic->forceFill(['deleted_at' => now()])->save();
        $this->assertSame([], $search->forWorkspace($actor, $workspace, 'Needle50%_', [], CollaborationAudience::Internal));
    }

    public function test_task_comments_require_the_same_project_and_visible_task(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $company->portalUsers()->attach($actor->id, ['role' => 'client', 'access_scope' => 'company']);
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic task', 'status' => 'pending', 'is_visible_to_client' => true]);
        $comment = ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'client_task_id' => $task->id,
            'body' => 'Needle50%_ task comment', 'author_user_id' => $actor->id, 'author_name' => 'Synthetic author', 'is_visible_to_client' => true]);
        $search = app(CollaborationSearch::class);
        $rows = $search->forWorkspace($actor, $workspace, 'Needle50%_', [], CollaborationAudience::Portal);
        $this->assertSame([$comment->public_id], array_map(fn ($row): string => $row->id, $rows));
        $this->assertStringContainsString('/comments/task/'.$task->public_id, $rows[0]->href);
        $task->update(['is_visible_to_client' => false]);
        $this->assertSame([], $search->forWorkspace($actor, $workspace, 'Needle50%_', [], CollaborationAudience::Portal));
    }

    public function test_search_queries_are_bounded_by_kind_instead_of_matching_rows(): void
    {
        [$actor, $workspace, $company, $project] = $this->fixture();
        $search = app(CollaborationSearch::class);
        $count = function () use ($search, $actor, $workspace): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $search->forWorkspace($actor, $workspace, 'Needle50%_', [], CollaborationAudience::Internal);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $topic = $this->topic($actor, $workspace, $company, $project);
        $this->comment($actor, $workspace, $company, $project, $topic);
        $one = $count();
        for ($index = 0; $index < 8; $index++) {
            $more = $this->topic($actor, $workspace, $company, $project);
            $this->comment($actor, $workspace, $company, $project, $more);
        }
        $this->assertSame($one, $count());

    }

    /** @return array{User, Workspace, ClientCompany, ClientProject} */
    private function fixture(): array
    {
        $actor = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic search workspace', 'slug' => 'synthetic-search-collaboration']);
        $workspace->memberships()->create(['user_id' => $actor->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project', 'is_visible_to_client' => true]);

        return [$actor, $workspace, $company, $project];
    }

    private function topic(User $actor, Workspace $workspace, ClientCompany $company, ?ClientProject $project, string $kind = 'note', bool $visible = false): ClientCollaborationTopic
    {
        return ClientCollaborationTopic::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project?->id,
            'kind' => $kind, 'title' => 'Synthetic '.$kind, 'body' => 'Full body with Needle50%_ literal match', 'author_user_id' => $actor->id, 'author_name' => 'Synthetic author', 'is_visible_to_client' => $visible]);
    }

    private function comment(User $actor, Workspace $workspace, ClientCompany $company, ClientProject $project, ClientCollaborationTopic $topic, bool $visible = false): ClientComment
    {
        return ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'client_collaboration_topic_id' => $topic->id, 'body' => 'Needle50%_ comment text', 'author_user_id' => $actor->id, 'author_name' => 'Synthetic author', 'is_visible_to_client' => $visible]);
    }
}
