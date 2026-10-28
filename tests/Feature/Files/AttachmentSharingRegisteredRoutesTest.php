<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientProjectMembership;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/** Exercises registered production routes and request-bound MCP dispatch, not synthetic routes. */
final class AttachmentSharingRegisteredRoutesTest extends TestCase
{
    use BuildsSyntheticExpenses, CallsMcp, RefreshDatabase;

    private string $policyDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyDirectory = sys_get_temp_dir().'/svc-registered-files-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->policyDirectory, 0700);
        File::put($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        $this->app->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($this->policyDirectory));
        config(['svc.attachments.sharing_writes_enabled' => true, 'svc.filesystem_disk' => 'svc_files',
            'agent_api.mcp_enabled' => true, 'agent_api.writes_enabled' => true,
            'agent_api.file_writes_enabled' => true, 'agent_api.collaboration_writes_enabled' => true]);
        Storage::fake('svc_files');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->policyDirectory);
        parent::tearDown();
    }

    public function test_project_publisher_prepares_private_upload_and_shares_with_replay_and_current_authority(): void
    {
        [$workspace, $company, $project, $publisher, $topic] = $this->fixture();
        $this->actingAsMcp($publisher, ['mcp:use', 'files:read', 'files:write', 'collaboration:read', 'collaboration:write']);
        $session = $this->initialize();
        $arguments = ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id,
            'record_type' => 'topic', 'record_id' => $topic->public_id];
        $prepared = $this->callTool($session, 'collaboration.attachments.upload_url', $arguments);
        $this->assertArrayNotHasKey('error', $prepared);
        $this->assertFalse($prepared['result']['isError'] ?? false, json_encode($prepared));
        $upload = $prepared['result']['structuredContent']['data'];
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('client_attachments', 0);
        $url = parse_url($upload['url'], PHP_URL_PATH).'?'.parse_url($upload['url'], PHP_URL_QUERY);
        $this->withHeader('Idempotency-Key', 'synthetic-registered-file')->post($url, [
            'file' => UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic registered file'),
            'expected_version' => $upload['expected_version'],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.is_visible_to_client', false);
        $file = ClientAttachment::query()->sole();
        $payload = [...$arguments, 'attachment_id' => $file->public_id, 'is_visible_to_client' => true,
            'expected_version' => AgentApiVersion::for($file), 'expected_parent_version' => AgentApiVersion::for($topic),
            'idempotency_key' => 'synthetic-registered-sharing'];
        $shared = $this->callTool($session, 'collaboration.attachments.visibility.update', $payload);
        $this->assertFalse($shared['result']['isError'] ?? false, json_encode($shared));
        $state = $shared['result']['structuredContent']['data'];
        $this->assertTrue($state['is_visible_to_client']);
        $this->assertTrue($state['effective_visible_to_client']);
        $replay = $this->callTool($session, 'collaboration.attachments.visibility.update', $payload);
        $this->assertSame($state, $replay['result']['structuredContent']['data']);
        $this->assertDatabaseCount('agent_mutation_receipts', 2);
        ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $publisher->id)->delete();
        $revoked = $this->callTool($session, 'collaboration.attachments.visibility.update', $payload);
        $this->assertTrue($revoked['result']['isError']);
        $this->assertDatabaseCount('agent_mutation_receipts', 2);
    }

    public function test_readers_survive_disabled_writers_and_both_scope_families_are_required(): void
    {
        [$workspace, $company, $project, $publisher, $topic] = $this->fixture();
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id.'/collaboration/attachments/topic/'.$topic->public_id;
        $this->actingAsMcp($publisher, ['files:read']);
        $this->getJson($base)->assertForbidden();
        $this->actingAsMcp($publisher, ['mcp:use', 'files:read', 'files:write', 'collaboration:read', 'collaboration:write']);
        config(['svc.attachments.sharing_writes_enabled' => false]);
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($base.'/upload-url')->assertNotFound();
        $names = $this->toolNames($this->initialize());
        $this->assertContains('collaboration.attachments.list', $names);
        $this->assertContains('collaboration.attachments.download_url', $names);
        $this->assertNotContains('attachments.visibility.get', $names); // Generic reads still require a workspace manager.
        foreach (['collaboration.attachments.upload_url', 'collaboration.attachments.visibility.update',
            'collaboration.attachments.delete', 'attachments.visibility.update'] as $writer) {
            $this->assertNotContains($writer, $names);
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
    }

    public function test_retained_policy_and_portal_identity_are_rechecked_after_url_preparation(): void
    {
        [$workspace, $company, $project, $publisher, $topic] = $this->fixture();
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id.'/collaboration/attachments/topic/'.$topic->public_id;
        $this->actingAsMcp($publisher, ['files:read', 'files:write', 'collaboration:read', 'collaboration:write']);
        $url = $this->postJson($base.'/upload-url')->assertOk()->json('data.url');
        File::delete($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER);
        $this->withHeader('Idempotency-Key', 'synthetic-held-upload')->post($url, ['expected_version' => AgentApiVersion::for($topic),
            'file' => UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic held URL')], ['Accept' => 'application/json'])->assertStatus(503);
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
        File::put($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        $company->portalUsers()->attach($publisher->id, ['role' => 'client', 'access_scope' => 'company']);
        $this->postJson($base.'/upload-url')->assertForbidden();
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
    }

    public function test_topic_and_comment_reads_omit_all_file_metadata_without_both_scopes(): void
    {
        [$workspace, $company, $project, $publisher, $topic] = $this->fixture();
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id.'/collaboration';
        $this->actingAsMcp($publisher, ['files:read', 'files:write', 'collaboration:read', 'collaboration:write']);
        $prepared = $this->postJson($base.'/attachments/topic/'.$topic->public_id.'/upload-url')->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'synthetic-preview-upload')->post($prepared['url'], [
            'file' => UploadedFile::fake()->createWithContent('synthetic-private-filename.txt', 'Synthetic preview content'),
            'expected_version' => $prepared['expected_version'],
        ], ['Accept' => 'application/json'])->assertOk();
        $this->getJson($base.'/topics/'.$topic->public_id)->assertOk()->assertJsonPath('data.attachments.data.0.filename', 'synthetic-private-filename.txt');
        $this->actingAsMcp($publisher, ['collaboration:read']);
        $response = $this->getJson($base.'/topics/'.$topic->public_id)->assertOk()->assertJsonMissingPath('data.attachments');
        $this->assertStringNotContainsString('synthetic-private-filename', $response->getContent());
        $this->getJson($base.'/comments/topic/'.$topic->public_id)->assertOk()->assertJsonCount(0, 'data');
    }

    private function fixture(): array
    {
        $workspace = $this->syntheticWorkspace('registered-files');
        $company = $this->syntheticCompany($workspace, 'registered-files');
        $project = $this->syntheticProject($company, 'registered-files');
        $project->update(['is_visible_to_client' => true]);
        $publisher = $this->syntheticMember($workspace, 'registered-publisher', 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
            'user_id' => $publisher->id, 'role' => 'manager']);
        $topic = ClientCollaborationTopic::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_project_id' => $project->id, 'kind' => 'note', 'title' => 'Synthetic registered topic', 'body' => 'Synthetic body',
            'author_user_id' => $publisher->id, 'author_name' => $publisher->name, 'is_visible_to_client' => true]);

        return [$workspace, $company, $project, $publisher, $topic];
    }
}
