<?php

namespace Tests\Feature\Api;

use App\Models\ClientAttachment;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentSharingNativeView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Resource refetches use the API; page navigation and byte downloads keep their browser routes. */
final class BrowserJsonReadParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_legacy_json_lookup_routes_are_retired_without_removing_browser_destinations(): void
    {
        foreach (['tasks.assignees', 'tasks.planning.labels.options', 'tasks.planning.dependencies.get',
            'tasks.planning.dependencies.options', 'tasks.planning.contacts.options', 'svc.files.parent.index',
            'svc.files.collaboration.index', 'portal.files.parent.index', 'portal.files.collaboration.index'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
        foreach (['tasks.organization.show', 'tasks.planning.labels.page', 'svc.files.parent.show',
            'svc.files.collaboration.show', 'portal.files.parent.show', 'portal.files.collaboration.show',
            'svc.files.download', 'portal.files.parent.download', 'portal.files.collaboration.download', 'search'] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_actual_native_refetch_capabilities_are_canonical_including_portal_file_continuations(): void
    {
        config(['task_planning_details.enabled' => true, 'task_organization.enabled' => true,
            'svc.attachments.sharing_writes_enabled' => true]);
        $directory = sys_get_temp_dir().'/svc-json-read-parity-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        File::put($directory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        app()->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($directory));
        try {
            $owner = User::factory()->create();
            $workspace = Workspace::query()->create(['name' => 'Synthetic JSON Read Ratchet', 'slug' => 'synthetic-json-read-ratchet']);
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Read Client', 'slug' => 'synthetic-read-client']);
            $company->portalUsers()->attach($owner->id, ['role' => 'viewer', 'access_scope' => 'company']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'name' => 'Synthetic Read Project', 'is_visible_to_client' => true]);
            $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic Lookup Task']);
            $this->actingAs($owner, 'web');
            $props = $this->get(route('tasks.show', [$workspace, $task]))->assertOk()->viewData('page')['props'];
            foreach (['task.assignees_action', 'planning_details.labels.options_href', 'planning_details.contacts.options_href',
                'planning_details.dependencies.options_href', 'planning_details.dependencies.read_href'] as $path) {
                $this->assertCanonical(data_get($props, $path), $path);
            }
            $this->assertStringNotContainsString('/api/v1/', $props['planning_details']['label_catalog_href']);
            $organization = $this->get(route('tasks.organization.show', [$workspace, $company, $project->public_id]))->assertOk()->viewData('page')['props'];
            $this->assertCanonical($organization['organization']['assignees_href'], 'organization.assignees_href');
            $topic = ClientCollaborationTopic::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'client_project_id' => $project->id, 'kind' => 'note', 'title' => 'Synthetic File Topic', 'body' => 'Synthetic body',
                'author_user_id' => $owner->id, 'author_name' => $owner->name, 'is_visible_to_client' => true]);
            foreach ([$project, $topic] as $parent) {
                foreach (range(1, 2) as $number) {
                    $file = ClientAttachment::query()->create(['workspace_id' => $workspace->id, 'record_type' => $parent === $project ? 'project' : 'topic',
                        'record_public_id' => $parent->public_id, 'object_key' => 'synthetic/read-ratchet/'.$parent->public_id.'/'.$number.'.txt',
                        'original_filename' => 'Synthetic-read-'.$number.'.txt', 'media_type' => 'text/plain', 'bytes' => 16,
                        'sha256' => hash('sha256', 'synthetic-read-'.$number), 'uploader_id' => $owner->id,
                        'lifecycle_state' => ClientAttachment::STATE_AVAILABLE, 'available_at' => now(), 'is_visible_to_client' => true]);
                    // Synthetic published fixture: real uploads deliberately start private.
                    DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $file->id)
                        ->update(['is_visible_to_client' => true]);
                }
            }
            $view = app(AttachmentSharingNativeView::class);
            $page = AttachmentPageQuery::fromInput(['limit' => 1]);
            $projectRef = new AttachmentParentReference('project', $project->public_id);
            $topicRef = new AttachmentParentReference('topic', $topic->public_id, $company->public_id);
            foreach (['native' => [$view->legacy($owner, $workspace, $projectRef, $page),
                $view->collaboration($owner, $workspace, $topicRef, AttachmentAudience::Internal, $page)],
                'portal' => [$view->portalLegacy($owner, $company, 'project', $project->public_id, $page),
                    $view->collaboration($owner, $workspace, $topicRef, AttachmentAudience::PortalPreview, $page)]] as $presentation => $panels) {
                foreach ($panels as $panel) {
                    $this->assertCount(1, $panel['files']);
                    $this->assertCanonical($panel['next_href'], 'file continuation');
                    parse_str((string) parse_url($panel['next_href'], PHP_URL_QUERY), $query);
                    $this->assertSame($presentation, $query['presentation']);
                    $this->assertNotEmpty($query['cursor']);
                    $this->assertStringNotContainsString('/api/v1/', $panel['files'][0]['download_href']);
                    if ($presentation === 'portal') {
                        $this->assertNull($panel['upload']);
                        $this->assertNull($panel['files'][0]['sharing']);
                        $this->assertNull($panel['files'][0]['delete_href']);
                    }
                }
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function assertCanonical(mixed $href, string $context): void
    {
        $this->assertIsString($href, $context);
        $this->assertStringStartsWith('/api/v1/', (string) parse_url($href, PHP_URL_PATH), $context);
    }
}
