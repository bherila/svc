<?php

namespace Tests\Feature\Files;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class NativeFileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_native_dual_manager_can_upload_and_remove_with_receipts_and_current_versions(): void
    {
        Storage::fake('svc_files');
        config(['agent_api.writes_enabled' => false, 'agent_api.file_writes_enabled' => false]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->actingAs($owner, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str_repeat('f', 40));
        $prepared = $this->postJson(route('agent-api.v1.attachments.upload-url', [$workspace, 'project', $project->public_id]))->assertOk()->json('data');
        $this->assertSame(AgentApiVersion::for($project), $prepared['expected_version']);
        $bytes = ['file' => UploadedFile::fake()->createWithContent('synthetic-native-file.txt', 'Synthetic native file bytes.'), 'expected_version' => $prepared['expected_version']];
        $this->withHeader('Accept', 'application/json')->withHeader('Idempotency-Key', 'native-file-upload');
        $created = $this->post($prepared['url'], $bytes, ['Content-Type' => 'multipart/form-data'])->assertCreated()->assertJsonPath('data.record_id', $project->public_id)->json('data');
        $this->post($prepared['url'], $bytes, ['Content-Type' => 'multipart/form-data'])->assertCreated()->assertJsonPath('data.id', $created['id']);
        $this->assertDatabaseCount('client_attachments', 1);
        $delete = route('agent-api.v1.attachments.destroy', [$workspace, $created['id']]);
        $body = ['expected_version' => $created['version'], 'confirm' => true];
        $this->withHeader('Idempotency-Key', 'native-file-delete')->deleteJson($delete, $body)->assertStatus(202);
        $this->deleteJson($delete, $body)->assertStatus(202);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'attachments.delete', 'outcome' => 'replay']);
    }

    public function test_portal_agent_identity_cannot_promote_itself_to_native_file_authority(): void
    {
        [$owner, $workspace, $company, $project] = $this->tenant();
        config(['agent_api.writes_enabled' => true, 'agent_api.file_writes_enabled' => true]);
        $this->actingAsMcp($owner, [AgentApiScopes::FILES_READ, AgentApiScopes::FILES_WRITE]);
        $this->postJson(route('agent-api.v1.attachments.upload-url', [$workspace, 'project', $project->public_id]), ['origin' => 'Native'])->assertForbidden();
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    /** @return array{User,Workspace,ClientCompany,ClientProject} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic native file workspace', 'slug' => 'synthetic-native-file-workspace']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic native file company', 'slug' => 'synthetic-native-file-company']);
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'client', 'access_scope' => 'company']);
        $project = $company->projects()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic private file project', 'is_visible_to_client' => false]);

        return [$owner, $workspace, $company, $project];
    }
}
