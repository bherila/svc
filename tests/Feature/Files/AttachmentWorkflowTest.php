<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => true,
            '--drop-types' => true,
            '--path' => [
                'database/migrations/0001_01_01_000000_create_users_table.php',
                'database/migrations/0001_01_01_000001_create_cache_table.php',
                'database/migrations/0001_01_01_000002_create_jobs_table.php',
                'database/migrations/2026_08_15_000000_create_svc_foundation.php',
                'database/migrations/2026_08_15_010000_create_engagement_workflow.php',
                'database/migrations/2026_08_15_015000_create_client_attachments.php',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
        require base_path('routes/files.php');
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_upload_promotes_an_opaque_blob_and_encrypts_the_filename(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $content = 'Synthetic private agreement contents.';
        $this->assertTrue(Gate::forUser($owner)->allows('manage', $workspace));

        $response = $this->actingAs($owner)->post(route('svc.files.store', [
            'workspace' => $workspace,
            'recordType' => 'company',
            'recordPublicId' => $company->public_id,
        ]), [
            'file' => UploadedFile::fake()->createWithContent('secret-contract.txt', $content),
        ]);

        $response->assertRedirect();
        $attachment = ClientAttachment::query()->sole();

        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $attachment->lifecycle_state);
        $this->assertSame('secret-contract.txt', $attachment->original_filename);
        $this->assertSame(hash('sha256', $content), $attachment->sha256);
        $this->assertSame(strlen($content), $attachment->bytes);
        $this->assertStringNotContainsString('secret-contract.txt', $attachment->object_key);
        $this->assertMatchesRegularExpression(
            '#^workspaces/'.preg_quote($workspace->public_id, '#').'/company/'.preg_quote($company->public_id, '#').'/[0-9a-f-]{36}$#',
            $attachment->object_key,
        );
        $this->assertNotSame('secret-contract.txt', DB::table('client_attachments')->value('original_filename'));
        Storage::disk('svc_files')->assertExists($attachment->object_key);
        Storage::disk('svc_files')->assertDirectoryEmpty('_staged');

        $download = $this->actingAs($owner)->get(route('svc.files.download', [
            'workspace' => $workspace,
            'clientAttachment' => $attachment->public_id,
        ]));

        $download->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('X-Content-SHA256', $attachment->sha256)
            ->assertHeader('Content-Disposition', 'attachment; filename=secret-contract.txt')
            ->assertStreamedContent($content);
        $this->assertStringNotContainsString('/storage/', (string) $download->headers->get('Location'));
    }

    public function test_attachment_endpoints_are_tenant_scoped(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $this->assertTrue(Gate::forUser($owner)->allows('manage', $workspace));
        [$outsider, $otherWorkspace] = $this->companyWorkspace('Other Workspace');
        $attachment = app(AttachmentStorageService::class)->store(
            $workspace,
            $company,
            UploadedFile::fake()->createWithContent('private.txt', 'private'),
            $owner,
        );

        $this->actingAs($outsider)->get(route('svc.files.download', [
            'workspace' => $workspace,
            'clientAttachment' => $attachment->public_id,
        ]))->assertForbidden();

        $this->actingAs($outsider)->delete(route('svc.files.destroy', [
            'workspace' => $workspace,
            'clientAttachment' => $attachment->public_id,
        ]))->assertForbidden();

        $this->actingAs($outsider)->post(route('svc.files.store', [
            'workspace' => $workspace,
            'recordType' => 'company',
            'recordPublicId' => $company->public_id,
        ]), [
            'file' => UploadedFile::fake()->createWithContent('other.txt', 'other'),
        ])->assertForbidden();

        $this->assertSame($otherWorkspace->id, $outsider->workspaces()->first()->id);
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $attachment->fresh()->lifecycle_state);
    }

    public function test_json_upload_and_delete_return_redacted_metadata(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();

        $upload = $this->actingAs($owner)->postJson(route('svc.files.store', [
            'workspace' => $workspace,
            'recordType' => 'company',
            'recordPublicId' => $company->public_id,
        ]), [
            'file' => UploadedFile::fake()->createWithContent('private.txt', 'synthetic'),
        ]);

        $upload->assertCreated()
            ->assertJsonPath('record_type', 'company')
            ->assertJsonPath('status', ClientAttachment::STATE_AVAILABLE)
            ->assertJsonMissingPath('object_key')
            ->assertJsonMissingPath('original_filename');

        $attachment = ClientAttachment::query()->sole();
        $this->actingAs($owner)->deleteJson(route('svc.files.destroy', [
            'workspace' => $workspace,
            'clientAttachment' => $attachment->public_id,
        ]))->assertStatus(202)->assertJsonPath('status', 'deleting');
    }

    public function test_portal_user_can_download_only_from_visible_records(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $portalUser = User::factory()->create();
        $company->portalUsers()->attach($portalUser->id, ['role' => 'client']);
        $visibleProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Visible synthetic project',
            'is_visible_to_client' => true,
        ]);
        $internalProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Internal synthetic project',
            'is_visible_to_client' => false,
        ]);

        $visibleAttachment = app(AttachmentStorageService::class)->store(
            $workspace,
            $visibleProject,
            UploadedFile::fake()->createWithContent('visible.txt', 'visible'),
            $owner,
        );
        $internalAttachment = app(AttachmentStorageService::class)->store(
            $workspace,
            $internalProject,
            UploadedFile::fake()->createWithContent('internal.txt', 'internal'),
            $owner,
        );

        $this->actingAs($portalUser)->get(route('svc.files.download', [
            'workspace' => $workspace,
            'clientAttachment' => $visibleAttachment->public_id,
        ]))->assertOk()->assertStreamedContent('visible');

        $this->actingAs($portalUser)->get(route('svc.files.download', [
            'workspace' => $workspace,
            'clientAttachment' => $internalAttachment->public_id,
        ]))->assertForbidden();
    }

    public function test_delete_is_logical_until_repair_applies_retention(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $attachment = app(AttachmentStorageService::class)->store(
            $workspace,
            $company,
            UploadedFile::fake()->createWithContent('delete-me.txt', 'delete me'),
            $owner,
        );

        $this->actingAs($owner)->delete(route('svc.files.destroy', [
            'workspace' => $workspace,
            'clientAttachment' => $attachment->public_id,
        ]))->assertRedirect();

        $attachment = $attachment->fresh();
        $this->assertSame(ClientAttachment::STATE_DELETING, $attachment->lifecycle_state);
        Storage::disk('svc_files')->assertExists($attachment->object_key);

        $this->actingAs($owner)->get(route('svc.files.download', [
            'workspace' => $workspace,
            'clientAttachment' => $attachment->public_id,
        ]))->assertNotFound();

        $this->artisan('svc:attachments:repair', [
            '--apply' => true,
            '--retention-days' => 0,
            '--format' => 'json',
        ])->assertSuccessful();

        $this->assertSame(ClientAttachment::STATE_DELETED, $attachment->fresh()->lifecycle_state);
        Storage::disk('svc_files')->assertMissing($attachment->object_key);
    }

    public function test_repair_dry_run_detects_a_digest_mismatch_without_mutating(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $attachment = app(AttachmentStorageService::class)->store(
            $workspace,
            $company,
            UploadedFile::fake()->createWithContent('integrity.txt', 'original'),
            $owner,
        );
        Storage::disk('svc_files')->put($attachment->object_key, 'tampered');

        $this->artisan('svc:attachments:repair', ['--format' => 'json'])
            ->expectsOutput('{"apply":false,"staged_rows":0,"orphaned_staged_objects":0,"missing_objects":0,"hash_mismatches":1,"purged_rows":0}')
            ->assertSuccessful();
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $attachment->fresh()->lifecycle_state);

        $this->artisan('svc:attachments:repair', ['--apply' => true, '--format' => 'json'])
            ->expectsOutput('{"apply":true,"staged_rows":0,"orphaned_staged_objects":0,"missing_objects":0,"hash_mismatches":1,"purged_rows":0}')
            ->assertSuccessful();
        $this->assertSame(ClientAttachment::STATE_CORRUPT, $attachment->fresh()->lifecycle_state);
    }

    public function test_repair_marks_a_missing_available_object_corrupt(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $attachment = app(AttachmentStorageService::class)->store(
            $workspace,
            $company,
            UploadedFile::fake()->createWithContent('missing.txt', 'missing'),
            $owner,
        );
        Storage::disk('svc_files')->delete($attachment->object_key);

        $this->artisan('svc:attachments:repair', ['--format' => 'json'])
            ->expectsOutput('{"apply":false,"staged_rows":0,"orphaned_staged_objects":0,"missing_objects":1,"hash_mismatches":0,"purged_rows":0}')
            ->assertSuccessful();
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $attachment->fresh()->lifecycle_state);

        $this->artisan('svc:attachments:repair', ['--apply' => true, '--format' => 'json'])
            ->expectsOutput('{"apply":true,"staged_rows":0,"orphaned_staged_objects":0,"missing_objects":1,"hash_mismatches":0,"purged_rows":0}')
            ->assertSuccessful();
        $this->assertSame(ClientAttachment::STATE_CORRUPT, $attachment->fresh()->lifecycle_state);
    }

    public function test_database_failure_removes_the_staged_object(): void
    {
        [$owner, $workspace, $company] = $this->companyWorkspace();
        $invalidUploader = User::make(['name' => 'Synthetic Invalid Uploader']);
        $invalidUploader->id = 999999;

        try {
            app(AttachmentStorageService::class)->store(
                $workspace,
                $company,
                UploadedFile::fake()->createWithContent('failure.txt', 'failure'),
                $invalidUploader,
            );
            $this->fail('The invalid uploader should make the attachment transaction fail.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('client_attachments', 0);
            Storage::disk('svc_files')->assertDirectoryEmpty('_staged');
        }
    }

    /** @return array{User, Workspace, ClientCompany} */
    private function companyWorkspace(string $workspaceName = 'Synthetic Workspace'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => $workspaceName,
            'slug' => str($workspaceName)->slug()->toString().'-'.str()->random(6),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Client',
            'slug' => 'synthetic-client-'.str()->random(6),
        ]);

        return [$owner, $workspace, $company];
    }
}
