<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentAttachmentService;
use App\Services\Files\AttachmentStorageService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AgentAttachmentPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.file_writes_enabled' => true, 'svc.filesystem_disk' => 'local']);
        Storage::fake('local');
    }

    public function test_verified_bytes_and_recovery_row_precede_receipt_reservation(): void
    {
        [$workspace, $project, $user] = $this->fixture();
        $inspected = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($workspace, &$inspected): void {
            if (! $inspected && str_starts_with(strtolower($sql), 'insert') && str_contains($sql, 'agent_mutation_receipts')) {
                $inspected = true;
                $row = ClientAttachment::query()->where('workspace_id', $workspace->id)->sole();
                $this->assertSame(ClientAttachment::STATE_STAGED, $row->lifecycle_state);
                $this->assertNull($row->available_at);
                $this->assertNotNull($row->staged_object_key);
                Storage::disk('local')->assertExists($row->object_key);
                Storage::disk('local')->assertMissing($row->staged_object_key);
            }
        });
        $result = $this->upload($workspace, $project, $user, 'synthetic-preparation');
        $this->assertTrue($inspected);
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $result['data']['status']);
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
    }

    public function test_identical_replay_discards_the_unused_preparation(): void
    {
        [$workspace, $project, $user] = $this->fixture();
        $first = $this->upload($workspace, $project, $user, 'synthetic-replay');
        $replay = $this->upload($workspace, $project, $user, 'synthetic-replay');
        $this->assertSame($first, $replay);
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'attachments.upload', 'outcome' => 'replay']);
    }

    public function test_stale_parent_discards_prepared_recovery_row_and_bytes(): void
    {
        [$workspace, $project, $user] = $this->fixture();
        try {
            $this->upload($workspace, $project, $user, 'synthetic-stale', str_repeat('0', 64));
            $this->fail('A stale parent revision must be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function changedAuthorization(): iterable
    {
        yield 'membership revoked' => [false, 403];
        yield 'cutover disabled' => [true, 404];
    }

    #[DataProvider('changedAuthorization')]
    public function test_publication_rechecks_authorization_after_preparation(bool $disableCutover, int $status): void
    {
        [$workspace, $project, $user] = $this->fixture();
        DB::listen(function ($query) use ($workspace, $user, $disableCutover): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'client_attachments')) {
                if ($disableCutover) {
                    config(['agent_api.file_writes_enabled' => false]);
                } else {
                    $workspace->memberships()->where('user_id', $user->id)->update(['role' => 'member']);
                }
            }
        });
        try {
            $this->upload($workspace, $project, $user, 'synthetic-changed-authorization');
            $this->fail('Publication must recheck the current authorization.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_compensation_retains_the_staged_row_for_repair(): void
    {
        [$workspace, $project, $user] = $this->fixture();
        $manager = app('filesystem');
        $disk = Storage::disk('local');
        $broken = Mockery::mock($disk)->makePartial();
        $broken->shouldReceive('delete')->andThrow(new \RuntimeException('Synthetic cleanup outage'));
        Storage::shouldReceive('disk')->andReturn($broken);
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'agent_mutation_audits')) {
                $fail = false;
                throw new \RuntimeException('Synthetic audit outage');
            }
        });
        try {
            $this->upload($workspace, $project, $user, 'synthetic-compensation-outage');
            $this->fail('The audit outage must fail publication.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit outage', $exception->getMessage());
        } finally {
            Storage::swap($manager);
        }
        $this->assertFalse($fail, 'The audit insert fault must execute on the active database driver.');
        $row = ClientAttachment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(ClientAttachment::STATE_STAGED, $row->lifecycle_state);
        $this->assertNotNull($row->staged_object_key);
        $disk->assertExists($row->object_key);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $counts = app(AttachmentStorageService::class)->repair(true, stagedAgeMinutes: 0);
        $this->assertSame(1, $counts['staged_rows']);
        $this->assertSame(ClientAttachment::STATE_DELETED, $row->fresh()->lifecycle_state);
        $this->assertSame([], $disk->allFiles());
    }

    /** @return array{Workspace,ClientProject,User} */
    private function fixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic upload preparation', 'slug' => 'synthetic-preparation-'.bin2hex(random_bytes(4))]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);

        return [$workspace, $project, $user];
    }

    /** @return array<string,mixed> */
    private function upload(Workspace $workspace, ClientProject $project, User $user, string $key, ?string $version = null): array
    {
        $file = UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic upload bytes');

        return app(AgentAttachmentService::class)->upload($user, $workspace, 'synthetic-upload-client', $key, 'project', $project->public_id, $file,
            ['expected_version' => $version ?? AgentApiVersion::for($project), 'file' => $file]);
    }
}
