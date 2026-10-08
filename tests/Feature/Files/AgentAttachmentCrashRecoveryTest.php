<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class AgentAttachmentCrashRecoveryTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_killed_generic_uploader_keeps_a_committed_recovery_row_after_promotion(): void
    {
        $this->bootProbeDatabase('generic_upload_crash_probe');
        $original = config('database.default');
        Artisan::call('migrate', ['--database' => 'generic_upload_crash_probe', '--force' => true]);
        config(['database.default' => 'generic_upload_crash_probe']);
        $storage = sys_get_temp_dir().'/svc-generic-upload-probe-'.bin2hex(random_bytes(8));
        File::makeDirectory($storage);
        config(['svc.filesystem_disk' => 'generic_crash', 'filesystems.disks.generic_crash' => ['driver' => 'local', 'root' => $storage]]);
        $process = null;
        try {
            $user = User::factory()->create();
            $workspace = Workspace::query()->create(['name' => 'Synthetic generic crash', 'slug' => 'synthetic-generic-crash']);
            $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-company']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
            $input = new InputStream;
            $input->write(json_encode(['connection' => config('database.connections.generic_upload_crash_probe'), 'workspace' => $workspace->id,
                'user' => $user->id, 'project' => $project->id, 'storage' => $storage], JSON_THROW_ON_ERROR)."\n");
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/Files/generic-upload-worker.php')], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], $input, 30);
            $process->start();
            $this->assertTrue($process->waitUntil(fn (): bool => str_contains($process->getOutput(), "promoted\n")), $process->getOutput().$process->getErrorOutput());
            $process->signal(9);
            $process->wait();
            $this->assertFalse($process->isSuccessful());
            $row = ClientAttachment::query()->where('workspace_id', $workspace->id)->sole();
            $this->assertSame(ClientAttachment::STATE_STAGED, $row->lifecycle_state);
            $this->assertNotNull($row->staged_object_key);
            $this->assertNull($row->available_at);
            $this->assertFileExists($storage.'/'.$row->object_key);
            $this->assertFileDoesNotExist($storage.'/'.$row->staged_object_key);
            $this->assertDatabaseCount('agent_mutation_receipts', 0);
            $counts = app(AttachmentStorageService::class)->repair(true, stagedAgeMinutes: 0);
            $this->assertSame(1, $counts['staged_rows']);
            $this->assertSame(ClientAttachment::STATE_DELETED, $row->fresh()->lifecycle_state);
            $this->assertSame([], File::allFiles($storage));
        } finally {
            $process?->stop(0);
            config(['database.default' => $original]);
            File::deleteDirectory($storage);
        }
    }
}
