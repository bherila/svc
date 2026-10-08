<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentWorkspaceCreationAction;
use App\Services\Files\AttachmentStorageService;
use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpToolCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\FirstPartySession;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AgentWorkspaceMiscTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.workspace_writes_enabled' => true,
            'agent_api.file_writes_enabled' => true, 'svc.filesystem_disk' => 'local']);
        Storage::fake('local');
    }

    public function test_workspace_creation_replays_scopes_actor_client_keys_and_refuses_revoked_membership(): void
    {
        $user = User::factory()->create();
        $this->actingAsMcp($user, ['workspaces:create']);
        $body = ['name' => 'Synthetic New Tenant'];
        $created = $this->withHeader('Idempotency-Key', 'new-tenant')->postJson('/api/v1/workspaces', $body)->assertCreated();
        $id = $created->json('data.id');
        $this->postJson('/api/v1/workspaces', $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/workspaces', ['name' => 'Changed request'])->assertConflict();
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('agent_workspace_creations', 1);
        $workspace = Workspace::query()->where('public_id', $id)->firstOrFail();
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'workspaces.create', 'outcome' => 'replay']);
        $action = app(AgentWorkspaceCreationAction::class);
        $otherClient = $action->run($user, 'synthetic-other-client', 'new-tenant', $body);
        $this->assertNotSame($id, $otherClient['data']['id']);
        $otherUser = User::factory()->create();
        $otherActor = $action->run($otherUser, 'synthetic-other-client', 'new-tenant', $body);
        $this->assertNotSame($otherClient['data']['id'], $otherActor['data']['id']);
        $workspace->memberships()->where('user_id', $user->id)->delete();
        $this->postJson('/api/v1/workspaces', $body)->assertForbidden();
        $this->assertDatabaseCount('workspaces', 3);
        $this->assertSchema('workspaces.create', $created->json());
    }

    public function test_invalid_workspace_creation_leaves_no_reservation_or_tenant(): void
    {
        $this->actingAsMcp(User::factory()->create(), ['workspaces:create']);
        $this->postJson('/api/v1/workspaces', ['name' => 'Synthetic'])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'bad-workspace')->postJson('/api/v1/workspaces', ['name' => ''])->assertUnprocessable();
        $this->postJson('/api/v1/workspaces', ['name' => 'Synthetic', 'owner_id' => 'foreign'])->assertUnprocessable();
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('agent_workspace_creations', 0);
    }

    public function test_distinct_workspace_creation_keys_recover_a_slug_claimed_after_the_availability_check(): void
    {
        $owner = User::factory()->create();
        $slug = 'synthetic-racing-workspace';
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $claimed = false;
        try {
            $connection->listen(function (QueryExecuted $query) use (&$claimed, $slug): void {
                if (! $claimed && str_contains($query->sql, 'exists') && str_contains($query->sql, 'workspaces') && $query->bindings === [$slug]) {
                    $claimed = true;
                    // The first SELECT already returned false. Claim its slug
                    // before the action's insert savepoint, like another key.
                    Workspace::query()->create(['name' => 'Synthetic Concurrent Tenant', 'slug' => $slug]);
                }
            });
            $action = app(AgentWorkspaceCreationAction::class);
            $first = $action->run($owner, 'synthetic-slug-client', 'synthetic-first-key', ['name' => 'Synthetic Racing Workspace']);
            $second = $action->run($owner, 'synthetic-slug-client', 'synthetic-second-key', ['name' => 'Synthetic Racing Workspace']);
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
        $this->assertTrue($claimed, 'The fixture must claim the selected slug before the insert.');
        $this->assertNotSame($first['data']['id'], $second['data']['id']);
        $this->assertSame($slug.'-2', Workspace::query()->where('public_id', $first['data']['id'])->value('slug'));
        $this->assertSame($slug.'-3', Workspace::query()->where('public_id', $second['data']['id'])->value('slug'));
        $this->assertDatabaseCount('workspaces', 3);
        $this->assertDatabaseCount('workspace_memberships', 2);
        $this->assertDatabaseCount('agent_workspace_creations', 2);
        $this->assertDatabaseCount('agent_mutation_receipts', 2);
        $this->assertDatabaseCount('agent_mutation_audits', 2);
        $this->assertSame($first, $action->run($owner, 'synthetic-slug-client', 'synthetic-first-key', ['name' => 'Synthetic Racing Workspace']));
    }

    public function test_generic_file_upload_is_idempotent_downloads_privately_and_deletes_with_confirmation_and_version(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['files:read', 'files:write']);
        $base = $this->base($workspace);
        $prepare = $this->postJson($this->recordUrl($workspace, $project).'/upload-url')->assertOk();
        $this->assertSchema('attachments.upload_url', $prepare->json());
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $upload = $prepare->json('data.url');
        $version = $prepare->json('data.expected_version');
        $created = $this->withHeader('Idempotency-Key', 'synthetic-upload')->post($upload,
            ['file' => $this->file(), 'expected_version' => $version], ['Accept' => 'application/json'])->assertCreated();
        $id = $created->json('data.id');
        $this->assertSchema('attachments.upload', $created->json());
        $this->post($upload, ['file' => $this->file(), 'expected_version' => $version], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.id', $id);
        $this->post($upload, ['file' => $this->file('Different synthetic bytes'), 'expected_version' => $version], ['Accept' => 'application/json'])->assertConflict();
        $this->assertDatabaseCount('client_attachments', 1);
        $listed = $this->getJson($this->recordUrl($workspace, $project))->assertOk()->assertJsonCount(1, 'data');
        $this->assertSchema('attachments.list', $listed->json());
        $metadata = $this->getJson($base.'/attachments/'.$id)->assertOk();
        $this->assertSchema('attachments.get', $metadata->json());
        $this->assertArrayNotHasKey('object_key', $metadata->json('data'));
        $download = $this->getJson($base.'/attachments/'.$id.'/download-url')->assertOk();
        $this->assertSchema('attachments.download_url', $download->json());
        $downloadUrl = $download->json('data.url');
        $this->get($downloadUrl)->assertOk()->assertStreamedContent('Synthetic attachment bytes');
        $this->get(preg_replace('/signature=[^&]+/', 'signature=invalid', $downloadUrl))->assertForbidden();
        $deletion = ['expected_version' => $metadata->json('data.version'), 'confirm' => true];
        $this->withHeader('Idempotency-Key', 'file-refusal')->deleteJson($base.'/attachments/'.$id, ['expected_version' => $deletion['expected_version'], 'confirm' => false])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'file-stale')->deleteJson($base.'/attachments/'.$id, ['expected_version' => str_repeat('a', 64), 'confirm' => true])->assertConflict();
        $deleted = $this->withHeader('Idempotency-Key', 'file-delete')->deleteJson($base.'/attachments/'.$id, $deletion)->assertAccepted()->assertJsonPath('data.status', 'deleting');
        $this->assertSchema('attachments.delete', $deleted->json());
        $this->deleteJson($base.'/attachments/'.$id, $deletion)->assertAccepted();
        $this->get($downloadUrl)->assertNotFound();
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'attachments.upload', 'outcome' => 'replay']);
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'attachments.delete', 'outcome' => 'failed', 'error_category' => 'conflict']);
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->deleteJson($base.'/attachments/'.$id, $deletion)->assertForbidden();
        $this->post($upload, ['file' => $this->file(), 'expected_version' => $version], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_file_routes_are_tenant_scoped_exclude_receipts_and_check_parent_edits_and_expiry(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        [$foreign, $foreignCompany, $foreignProject] = $this->fixture();
        $attachment = app(AttachmentStorageService::class)->store($foreign, $foreignProject, $this->file(), $owner);
        $this->actingAsMcp($owner, ['files:read', 'files:write']);
        $this->getJson($this->base($workspace).'/attachments/'.$attachment->public_id)->assertNotFound();
        $this->getJson($this->base($workspace).'/records/project/'.$foreignProject->public_id.'/attachments')->assertNotFound();
        $this->postJson($this->base($workspace).'/records/expense/'.$project->public_id.'/attachments/upload-url')->assertUnprocessable();
        $prep = $this->postJson($this->recordUrl($workspace, $project).'/upload-url')->assertOk()->json('data');
        $project->update(['name' => 'Synthetic Edited Parent']);
        $this->withHeader('Idempotency-Key', 'stale-parent')->post($prep['url'], ['file' => $this->file(), 'expected_version' => $prep['expected_version']], ['Accept' => 'application/json'])->assertConflict();
        $this->assertDatabaseCount('client_attachments', 1);
        $this->travel(11)->minutes();
        $this->withHeader('Idempotency-Key', 'expired-url')->post($prep['url'], ['file' => $this->file(), 'expected_version' => AgentApiVersion::for($project)], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_signed_urls_require_current_scope_and_manager_permission(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $attachment = app(AttachmentStorageService::class)->store($workspace, $project, $this->file(), $owner);
        $this->actingAsMcp($owner, ['files:read', 'files:write']);
        $download = $this->getJson($this->base($workspace).'/attachments/'.$attachment->public_id.'/download-url')->assertOk()->json('data.url');
        $upload = $this->postJson($this->recordUrl($workspace, $project).'/upload-url')->assertOk()->json('data');
        $this->actingAsMcp($owner, ['identity:read']);
        $this->get($download)->assertForbidden();
        $this->withHeader('Idempotency-Key', 'missing-scope')->post($upload['url'], ['file' => $this->file(), 'expected_version' => $upload['expected_version']], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAsMcp($owner, ['files:read', 'files:write']);
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->get($download)->assertForbidden();
        $this->getJson($this->base($workspace).'/attachments/'.$attachment->public_id)->assertForbidden();
        $this->postJson($this->recordUrl($workspace, $project).'/upload-url')->assertForbidden();
    }

    public function test_search_filters_each_kind_by_scope_and_workspace_and_uses_shared_web_results(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        [$foreign] = $this->fixture();
        $this->actingAsMcp($owner, ['identity:read', 'projects:read']);
        $response = $this->getJson($this->base($workspace).'/search?q=Synthetic')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'project')->assertJsonPath('data.0.id', $project->public_id);
        $this->assertSchema('search', $response->json());
        $this->getJson($this->base($foreign).'/search?q=Synthetic')->assertNotFound();
        $this->actingAsMcp($owner, ['identity:read']);
        $this->getJson($this->base($workspace).'/search?q=Synthetic')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsMcp($owner, ['identity:read', 'clients:read']);
        $this->getJson($this->base($workspace).'/search?q=Synthetic')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'client');
        $this->getJson($this->base($workspace).'/search?q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_mcp_prepares_urls_runs_workspace_create_and_search_and_reports_withheld_tools(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'projects:read', 'workspaces:create', 'files:read', 'files:write']);
        $session = $this->initialize();
        $names = $this->toolNames($session);
        foreach (['workspaces.create', 'search', 'attachments.list', 'attachments.get', 'attachments.download_url', 'attachments.upload_url', 'attachments.delete'] as $name) {
            $this->assertContains($name, $names);
        }
        $created = $this->callTool($session, 'workspaces.create', ['name' => 'Synthetic MCP Tenant', 'idempotency_key' => 'mcp-tenant']);
        $this->assertFalse($created['result']['isError'] ?? false);
        $this->assertSame('Synthetic MCP Tenant', $created['result']['structuredContent']['data']['name']);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/workspaces/', $created['result']['structuredContent']['data']['web_url']);
        $search = $this->callTool($session, 'search', ['workspace_id' => $workspace->public_id, 'q' => 'Synthetic']);
        $this->assertSame($project->public_id, $search['result']['structuredContent']['data'][0]['id']);
        $upload = $this->callTool($session, 'attachments.upload_url', ['workspace_id' => $workspace->public_id, 'record_type' => 'project', 'record_id' => $project->public_id]);
        $this->assertSame('POST', $upload['result']['structuredContent']['data']['method']);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/api/v1/', $upload['result']['structuredContent']['data']['url']);
        $this->assertDatabaseCount('client_attachments', 0);
        $prepared = $upload['result']['structuredContent']['data'];
        $uploaded = $this->withHeader('Idempotency-Key', 'mcp-prepared-upload')->post($prepared['url'],
            ['file' => $this->file(), 'expected_version' => $prepared['expected_version']], ['Accept' => 'application/json'])->assertCreated();
        $fileId = $uploaded->json('data.id');
        $listed = $this->callTool($session, 'attachments.list', ['workspace_id' => $workspace->public_id, 'record_type' => 'project', 'record_id' => $project->public_id]);
        $this->assertSame($fileId, $listed['result']['structuredContent']['data'][0]['id']);
        $metadata = $this->callTool($session, 'attachments.get', ['workspace_id' => $workspace->public_id, 'attachment_id' => $fileId]);
        $this->assertSame($uploaded->json('data.version'), $metadata['result']['structuredContent']['data']['version']);
        $url = $this->callTool($session, 'attachments.download_url', ['workspace_id' => $workspace->public_id, 'attachment_id' => $fileId]);
        $this->get($url['result']['structuredContent']['data']['url'])->assertOk()->assertStreamedContent('Synthetic attachment bytes');
        $deletionArgs = ['workspace_id' => $workspace->public_id, 'attachment_id' => $fileId,
            'expected_version' => $uploaded->json('data.version'), 'confirm' => true, 'idempotency_key' => 'mcp-delete'];
        $refused = $this->callTool($session, 'attachments.delete', [...$deletionArgs, 'confirm' => false, 'idempotency_key' => 'mcp-delete-unconfirmed']);
        $this->assertSame(-32602, $refused['error']['code']);
        $refused = $this->callTool($session, 'attachments.delete', [...$deletionArgs, 'expected_version' => str_repeat('0', 64), 'idempotency_key' => 'mcp-delete-stale']);
        $this->assertTrue($refused['result']['isError']);
        [$foreign, $foreignCompany, $foreignProject] = $this->fixture();
        $refused = $this->callTool($session, 'attachments.upload_url', ['workspace_id' => $workspace->public_id,
            'record_type' => 'project', 'record_id' => $foreignProject->public_id]);
        $this->assertTrue($refused['result']['isError']);
        $deleted = $this->callTool($session, 'attachments.delete', $deletionArgs);
        $this->assertSame('deleting', $deleted['result']['structuredContent']['data']['status']);
        $retry = $this->callTool($session, 'attachments.delete', $deletionArgs);
        $this->assertSame($deleted['result']['structuredContent'], $retry['result']['structuredContent']);
        config(['agent_api.invoice_writes_enabled' => false]);
        $context = $this->getJson('/api/v1/context')->assertOk();
        $this->assertSchema('context.get', $context->json());
        $withheld = collect($context->json('data.withheld_tools'))->keyBy('name');
        foreach (['create_draft', 'update_draft', 'update_details', 'correct', 'discard_draft', 'issue', 'send', 'void'] as $operation) {
            $this->assertSame('deployment_disabled', $withheld['invoices.'.$operation]['reason']);
        }
        $this->assertSame(['name' => 'tasks.list', 'reason' => 'scope_not_granted', 'scope' => 'tasks:read'], $withheld['tasks.list']);
        $this->assertArrayNotHasKey('attachments.upload_url', $withheld->all());
        $mcpContext = $this->callTool($session, 'context.get', []);
        $this->assertSame($context->json('data.withheld_tools'), $mcpContext['result']['structuredContent']['data']['withheld_tools']);
        $workspace->memberships()->where('user_id', $owner->id)->delete();
        Workspace::query()->where('public_id', $created['result']['structuredContent']['data']['id'])->firstOrFail()->memberships()->where('user_id', $owner->id)->delete();
        $withoutRole = collect($this->getJson('/api/v1/context')->assertOk()->json('data.withheld_tools'))->keyBy('name');
        $this->assertSame('role', $withoutRole['attachments.list']['reason']);
        $this->assertSame('role', $withoutRole['attachments.upload_url']['reason']);
    }

    public function test_workspace_creation_rolls_back_reservation_membership_and_receipt_on_audit_failure(): void
    {
        $user = User::factory()->create();
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'agent_mutation_audits')) {
                $fail = false;
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(AgentWorkspaceCreationAction::class)->run($user, 'synthetic-rollback-client', 'rollback-workspace', ['name' => 'Synthetic Rollback Tenant']);
            $this->fail('Creation must fail when its audit cannot commit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertFalse($fail, 'The audit insert fault must execute on the active database driver.');
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_memberships', 0);
        $this->assertDatabaseCount('agent_workspace_creations', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_upload_compensates_disk_object_and_receipt_when_audit_fails(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['files:write']);
        $prepared = $this->postJson($this->recordUrl($workspace, $project).'/upload-url')->assertOk()->json('data');
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'agent_mutation_audits')) {
                $fail = false;
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->withHeader('Idempotency-Key', 'rollback-file')->post($prepared['url'],
            ['file' => $this->file(), 'expected_version' => $prepared['expected_version']], ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertFalse($fail, 'The audit insert fault must execute on the active database driver.');
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'attachments.upload', 'outcome' => 'failed']);
    }

    public function test_generic_parent_types_have_current_versions_and_accepted_proposals_accept_files_without_mutation(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id,
            'client_project_id' => $project->id, 'title' => 'Synthetic File Task', 'status' => 'open']);
        $proposal = ClientProposal::query()->create(['workspace_id' => $workspace->id,
            'client_company_id' => $company->id, 'title' => 'Synthetic Accepted File Proposal', 'currency' => 'USD', 'status' => 'accepted']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id,
            'client_company_id' => $company->id, 'title' => 'Synthetic File Agreement', 'currency' => 'USD', 'starts_on' => '2026-10-01']);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id,
            'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-FILE-001', 'currency' => 'USD']);
        $this->actingAsMcp($owner, ['files:read', 'files:write']);
        foreach (['company' => $company, 'project' => $project, 'task' => $task, 'proposal' => $proposal, 'agreement' => $agreement, 'invoice' => $invoice] as $type => $record) {
            $url = $this->base($workspace).'/records/'.$type.'/'.$record->public_id.'/attachments';
            $before = AgentApiVersion::for($record);
            $prep = $this->postJson($url.'/upload-url')->assertOk()->assertJsonPath('data.expected_version', $before)->json('data');
            $this->withHeader('Idempotency-Key', 'generic-'.$type)->post($prep['url'],
                ['file' => $this->file(), 'expected_version' => $prep['expected_version']], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.record_type', $type);
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('parent_version', $before);
            $this->assertSame($before, AgentApiVersion::for($record->fresh()));
        }
        $before = AgentApiVersion::for($company);
        $company->update(['name' => 'Synthetic Changed Client']);
        $this->assertNotSame($before, AgentApiVersion::for($company));
    }

    public function test_creation_upload_and_delete_take_registered_locks_in_order(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['workspaces:create', 'files:write', 'files:read']);
        LockOrderRecorder::start();
        try {
            $this->withHeader('Idempotency-Key', 'lock-workspace')->postJson('/api/v1/workspaces', ['name' => 'Synthetic Lock Tenant'])->assertCreated();
            $prep = $this->postJson($this->recordUrl($workspace, $project).'/upload-url')->assertOk()->json('data');
            $file = $this->withHeader('Idempotency-Key', 'lock-upload')->post($prep['url'],
                ['file' => $this->file(), 'expected_version' => $prep['expected_version']], ['Accept' => 'application/json'])->assertCreated()->json('data');
            $this->withHeader('Idempotency-Key', 'lock-delete')->deleteJson($this->base($workspace).'/attachments/'.$file['id'],
                ['expected_version' => $file['version'], 'confirm' => true])->assertAccepted();
            $seen = [];
            foreach (LockOrderRecorder::sequences() as $sequence) {
                $ranks = array_map(fn ($resource): int => $resource->rank(), $sequence);
                $sorted = $ranks;
                sort($sorted);
                $this->assertSame($sorted, $ranks);
                foreach ($sequence as $resource) {
                    $seen[] = $resource;
                }
            }
            $this->assertContains(LockResource::AgentWorkspaceCreation, $seen);
            $this->assertContains(LockResource::ClientAttachment, $seen);
        } finally {
            LockOrderRecorder::stop();
        }
    }

    public function test_context_reports_per_tool_and_global_mcp_kill_switches_without_claiming_payment_support(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['identity:read', 'files:read']);
        config(['agent_api.mcp_feature_flags' => ['search' => false]]);
        $withheld = collect($this->getJson('/api/v1/context')->assertOk()->json('data.withheld_tools'))->keyBy('name');
        $this->assertSame(['name' => 'search', 'reason' => 'deployment_disabled'], $withheld['search']);
        $this->assertArrayNotHasKey('attachments.list', $withheld->all());
        config(['agent_api.mcp_enabled' => false]);
        $withheld = collect($this->getJson('/api/v1/context')->assertOk()->json('data.withheld_tools'))->keyBy('name');
        $this->assertSame('deployment_disabled', $withheld['attachments.list']['reason']);
        $this->assertSame('deployment_disabled', $withheld['context.get']['reason']);
        $definition = collect(app(AgentMcpToolCatalog::class)->definitions(
            app(AgentMcpReadTools::class), app(AgentMcpWriteTools::class)))->firstWhere('name', 'invoices.get');
        $this->assertStringNotContainsString('MCP can record', $definition->description);
        $this->assertStringContainsString('withheld_tools', $definition->description);
    }

    public static function flags(): iterable
    {
        yield [false, false, false];
        yield [true, false, false];
        yield [false, true, false];
        yield [true, true, true];
    }

    #[DataProvider('flags')]
    public function test_browser_and_oauth_context_report_the_same_client_deployment_cutovers(bool $outer, bool $inner, bool $enabled): void
    {
        [, , , $owner] = $this->fixture();
        config(['agent_api.writes_enabled' => $outer, 'agent_api.client_writes_enabled' => $inner]);
        $this->actingAs($owner)->withCredentials()
            ->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
        $browser = $this->getJson('/api/v1/context')->assertOk()->json('data.withheld_tools');
        $byName = collect($browser)->keyBy('name');
        foreach (['clients.create', 'clients.update', 'clients.archive', 'clients.restore', 'agreements.create', 'agreements.update', 'agreements.activate', 'agreements.terminate'] as $name) {
            if ($enabled) {
                $this->assertArrayNotHasKey($name, $byName->all());
            } else {
                $this->assertSame(['name' => $name, 'reason' => 'deployment_disabled'], $byName[$name]);
            }
        }
        $this->actingAsMcp($owner, ['mcp:use', ...FirstPartySession::scopes()]);
        $oauth = $this->getJson('/api/v1/context')->assertOk()->json('data.withheld_tools');
        $this->assertSame($browser, $oauth);
    }

    #[DataProvider('flags')]
    public function test_workspace_and_file_cutovers_gate_rest_mcp_and_capabilities(bool $outer, bool $inner, bool $enabled): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        config(['agent_api.writes_enabled' => $outer, 'agent_api.workspace_writes_enabled' => $inner, 'agent_api.file_writes_enabled' => $inner]);
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'workspaces:create', 'files:read', 'files:write']);
        $session = $this->initialize();
        $names = $this->toolNames($session);
        $this->assertSame($enabled, in_array('workspaces.create', $names, true));
        $this->assertSame($enabled, in_array('attachments.upload_url', $names, true));
        $this->assertSame($enabled, in_array('attachments.delete', $names, true));
        $context = $this->getJson('/api/v1/context')->assertOk();
        $this->assertSame($enabled, in_array('workspaces:create', $context->json('data.capabilities'), true));
        $this->assertSame($enabled, in_array('files:write', $context->json('data.workspaces.0.capabilities'), true));
        $this->postJson($this->recordUrl($workspace, $project).'/upload-url')->assertStatus($enabled ? 200 : 404);
        $this->withHeader('Idempotency-Key', 'flag-create')->postJson('/api/v1/workspaces', ['name' => 'Synthetic Flag Tenant'])->assertStatus($enabled ? 201 : 404);
        $this->getJson($this->recordUrl($workspace, $project))->assertOk();
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User} */
    public function test_workspace_routes_conceal_missing_and_foreign_workspaces_without_scopes_or_enabled_writes(): void
    {
        config(['app.debug' => false]);
        [$workspace, , $project, $owner] = $this->fixture();
        $attachment = app(AttachmentStorageService::class)->store($workspace, $project, $this->file(), $owner);
        $stranger = User::factory()->create();
        $missing = Str::uuid()->toString();
        $record = '/records/project/'.$project->public_id.'/attachments';
        $file = '/attachments/'.$attachment->public_id;
        $requests = [['GET', '/search?q=synthetic'], ['GET', $record], ['GET', $file], ['GET', $file.'/download-url'], ['GET', $file.'/content'], ['POST', $record.'/upload-url'], ['POST', $record.'/upload'], ['DELETE', $file]];
        foreach ([[['identity:read', 'files:read', 'files:write'], true], [[], true], [['identity:read', 'files:read', 'files:write'], false]] as [$scopes, $writes]) {
            $this->actingAsMcp($stranger, $scopes);
            config(['agent_api.writes_enabled' => $writes, 'agent_api.file_writes_enabled' => $writes]);
            foreach ($requests as [$method, $suffix]) {
                $known = $this->json($method, $this->base($workspace).$suffix)->assertNotFound();
                $unknown = $this->json($method, '/api/v1/workspaces/'.$missing.$suffix)->assertNotFound();
                $this->assertSame($known->json(), $unknown->json());
                $this->assertSame($known->headers->get('Cache-Control'), $unknown->headers->get('Cache-Control'));
                $this->assertStringContainsString('no-store', $unknown->headers->get('Cache-Control'));
            }
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_file_deletion_requires_the_read_scope_for_its_revision(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $attachment = app(AttachmentStorageService::class)->store($workspace, $project, $this->file(), $owner);
        $this->actingAsMcp($owner, ['files:write']);
        $this->deleteJson($this->base($workspace).'/attachments/'.$attachment->public_id, ['expected_version' => AgentApiVersion::for($attachment), 'confirm' => true], ['Idempotency-Key' => 'synthetic-unreadable-delete'])->assertForbidden();
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_signed_in_browser_reads_search_and_files_with_agent_writes_disabled(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        config(['agent_api.writes_enabled' => false, 'agent_api.workspace_writes_enabled' => false, 'agent_api.file_writes_enabled' => false]);
        $this->actingAs($owner)->withCredentials()
            ->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
        $this->getJson($this->base($workspace).'/search?q=Synthetic')->assertOk();
        $this->getJson($this->recordUrl($workspace, $project))->assertOk()->assertJsonCount(0, 'data');
    }

    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Misc Workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Misc Client', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Misc Project']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $company, $project, $owner];
    }

    private function base(Workspace $workspace): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id;
    }

    private function recordUrl(Workspace $workspace, ClientProject $project): string
    {
        return $this->base($workspace).'/records/project/'.$project->public_id.'/attachments';
    }

    private function file(string $bytes = 'Synthetic attachment bytes'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic.txt', $bytes);
    }

    /** @param array<string,mixed> $data */
    private function assertSchema(string $operation, array $data): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($data, AgentApiResponseSchemaCatalog::forOperation($operation)));
    }
}
