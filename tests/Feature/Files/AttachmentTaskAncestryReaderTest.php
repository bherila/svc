<?php

namespace Tests\Feature\Files;

use App\Models\ClientComment;
use App\Models\ClientCompanyMembership;
use App\Models\ClientTask;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationReadService;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class AttachmentTaskAncestryReaderTest extends TestCase
{
    use BuildsSyntheticExpenses, CallsMcp, RefreshDatabase, WritesLegacyCrossTenantRows;

    /** @return list<array{string}> */
    public static function hiddenOrganizations(): array
    {
        return [['private_parent'], ['private_list'], ['deleted_list'], ['cycle'], ['foreign_parent'], ['missing_parent']];
    }

    #[DataProvider('hiddenOrganizations')]
    public function test_files_comments_and_search_share_the_task_ancestor_policy(string $organization): void
    {
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
        $workspace = $this->syntheticWorkspace('ancestor-reader');
        $owner = $this->syntheticMember($workspace, 'ancestor-owner', 'owner');
        $company = $this->syntheticCompany($workspace, 'ancestor-company');
        $project = $this->syntheticProject($company, 'ancestor-project');
        $project->update(['is_visible_to_client' => true]);
        // A dual manager exercises real REST/MCP, with exact grants to this project.
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'client', 'access_scope' => 'projects']);
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id]);
        $parent = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic containing task', 'is_visible_to_client' => true]);
        $leaf = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'AncestryNeedle synthetic leaf', 'is_visible_to_client' => true]);
        DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $leaf->id)->update(['parent_task_id' => $parent->id]);
        $comment = ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_project_id' => $project->id, 'client_task_id' => $leaf->id, 'body' => 'AncestryNeedle synthetic comment',
            'author_user_id' => $owner->id, 'author_name' => 'Synthetic author', 'is_visible_to_client' => true]);
        $storage = app(AttachmentStorageService::class);
        $file = $storage->store($workspace, $leaf, UploadedFile::fake()->createWithContent('AncestryNeedle-synthetic-task.txt', 'Synthetic task bytes'), $owner);
        $commentFile = $storage->store($workspace, $project, UploadedFile::fake()->createWithContent('AncestryNeedle-synthetic-comment.txt', 'Synthetic comment bytes'), $owner);
        DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $commentFile->id)->update(['record_type' => 'comment', 'record_public_id' => $comment->public_id]);
        $ungrantedProject = $this->syntheticProject($company, 'ungranted-search');
        $ungrantedProject->update(['is_visible_to_client' => true]);
        for ($index = 0; $index < 7; $index++) {
            ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $ungrantedProject->id,
                'title' => 'AncestryNeedle ungranted synthetic task '.$index, 'is_visible_to_client' => true]);
        }
        $webThread = route('portal.collaboration.comments.index', [$company, 'task', $leaf->public_id]);
        $this->actingAs($owner)->get($webThread)->assertOk();
        $this->actingAsMcp($owner, ['mcp:use', 'files:read', 'identity:read', 'tasks:read', 'collaboration:read']);
        $base = '/api/v1/workspaces/'.$workspace->public_id;
        $heldUrl = $this->getJson($base.'/attachments/'.$file->public_id.'/download-url')->assertOk()->json('data.url');
        $this->get($heldUrl)->assertOk()->assertStreamedContent('Synthetic task bytes');
        $this->getJson($base.'/attachments/'.$commentFile->public_id)->assertOk()->assertJsonPath('data.filename', 'AncestryNeedle-synthetic-comment.txt');
        $this->getJson($base.'/search?q=AncestryNeedle')->assertOk()->assertJsonCount(2, 'data');

        if ($organization === 'private_parent') {
            $parent->update(['is_visible_to_client' => false]);
        } elseif (in_array($organization, ['private_list', 'deleted_list'], true)) {
            $list = DB::table('client_task_lists')->insertGetId(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
                'public_id' => (string) Str::uuid(), 'name' => 'Synthetic containing list', 'is_visible_to_client' => $organization === 'deleted_list',
                'deleted_at' => $organization === 'deleted_list' ? now() : null]);
            DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $parent->id)->update(['client_task_list_id' => $list]);
        } elseif ($organization === 'cycle') {
            DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $parent->id)->update(['parent_task_id' => $leaf->id]);
        } else {
            $foreignProject = $this->syntheticProject($company, 'foreign-ancestor-project');
            $foreignTask = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $foreignProject->id,
                'title' => 'Synthetic foreign task never disclosed', 'is_visible_to_client' => true]);
            $this->writingLegacyCrossTenantRows(fn () => DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $leaf->id)
                ->update(['parent_task_id' => $organization === 'missing_parent' ? 99999999 : $foreignTask->id]));
        }

        $this->get($heldUrl)->assertNotFound();
        foreach ([$file, $commentFile] as $attachment) {
            $this->getJson($base.'/attachments/'.$attachment->public_id)->assertNotFound();
            $this->getJson($base.'/attachments/'.$attachment->public_id.'/download-url')->assertNotFound();
        }
        $this->getJson($base.'/records/task/'.$leaf->public_id.'/attachments')->assertNotFound();
        $this->getJson($base.'/clients/'.$company->public_id.'/collaboration/comments/task/'.$leaf->public_id)->assertNotFound();
        $this->getJson($base.'/search?q=AncestryNeedle')->assertOk()->assertJsonCount(0, 'data');
        $session = $this->initialize();
        $tool = $this->callTool($session, 'attachments.get', ['workspace_id' => $workspace->public_id, 'attachment_id' => $file->public_id]);
        $this->assertTrue($tool['result']['isError']);
        $this->actingAs($owner)->get($webThread)->assertNotFound();
        // Current native staff access remains independent of external disclosure.
        $thread = app(CollaborationReadService::class)->thread($owner, $workspace, $company, 'task', $leaf->public_id, CollaborationAudience::Internal);
        $this->assertFalse($thread['comments'][0]['effective_visible_to_client']);
        $this->actingAs($owner)->get(route('svc.files.download', [$workspace, $file->public_id]))->assertOk();
    }
}
