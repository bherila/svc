<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCompanyMembership;
use App\Services\AgentApi\AgentAttachmentService;
use App\Services\AgentApi\AgentExpenseReceiptService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class AttachmentWriteSnapshotTest extends TestCase
{
    use BuildsSyntheticExpenses, UsesAProbeDatabase;

    /** @return list<array{string}> */
    public static function operations(): array
    {
        return [['upload'], ['upload_replay'], ['delete'], ['delete_replay'], ['expense'], ['expense_replay']];
    }

    #[DataProvider('operations')]
    public function test_new_portal_identity_after_real_rr_snapshot_denies_agent_mutation_and_replay(string $operation): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Current-read authority needs a separate MariaDB connection.');
        }
        $this->bootProbeDatabase('file_race');
        Artisan::call('migrate', ['--database' => 'file_race', '--force' => true]);
        $original = config('database.default');
        config(['database.default' => 'file_race', 'svc.filesystem_disk' => 'svc_files', 'agent_api.writes_enabled' => true,
            'agent_api.file_writes_enabled' => true, 'agent_api.expense_writes_enabled' => true]);
        Storage::fake('svc_files');
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_snapshot_isolation=OFF');
            $workspace = $this->syntheticWorkspace('file-snapshot');
            $owner = $this->syntheticMember($workspace, 'file-snapshot-owner', 'owner');
            $actor = $this->syntheticMember($workspace, 'file-snapshot-actor', 'admin');
            $company = $this->syntheticCompany($workspace, 'file-snapshot');
            $project = $this->syntheticProject($company, 'file-snapshot');
            $expense = $this->recordSyntheticExpense($workspace, $company);
            $file = UploadedFile::fake()->createWithContent('synthetic-snapshot.txt', 'Synthetic snapshot bytes');
            $files = app(AgentAttachmentService::class);
            $receipts = app(AgentExpenseReceiptService::class);
            $projectVersion = AgentApiVersion::for($project);
            $expenseVersion = AgentApiVersion::for($expense);
            $first = null;
            if (str_starts_with($operation, 'delete') || $operation === 'upload_replay') {
                $first = $files->upload($actor, $workspace, 'synthetic-snapshot', 'synthetic-upload', 'project', $project->public_id, $file,
                    ['expected_version' => $projectVersion, 'file' => $file]);
            } elseif ($operation === 'expense_replay') {
                $first = $receipts->upload($actor, $workspace, 'synthetic-snapshot', 'synthetic-expense', $expense->public_id,
                    ['expected_version' => $expenseVersion], $file);
            }
            $deleteVersion = $first['data']['version'] ?? '';
            if ($operation === 'delete_replay') {
                $files->delete($actor, $workspace, 'synthetic-snapshot', 'synthetic-delete', $first['data']['id'], ['expected_version' => $deleteVersion, 'confirm' => true]);
            }
            $beforeRows = ClientAttachment::query()->where('workspace_id', $workspace->id)->get()->map->getAttributes()->all();
            $beforeObjects = Storage::disk('svc_files')->allFiles();
            $beforeReceipts = DB::table('agent_mutation_receipts')->where('workspace_id', $workspace->id)->count();
            $refusal = null;
            DB::transaction(function () use ($operation, $workspace, $owner, $actor, $project, $expense, $file, $files, $receipts, $first, $projectVersion, $expenseVersion, $deleteVersion, &$refusal): void {
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->count());
                $this->assertSame('admin', $workspace->memberships()->where('user_id', $actor->id)->value('role'));
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Collaboration/collaboration-race-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode(['connection' => config('database.connections.file_race'),
                        'workspace' => $workspace->id, 'owner' => $owner->id, 'actor' => $actor->id, 'project' => $project->id,
                        'operation' => 'portal_granted_new_company'], JSON_THROW_ON_ERROR)."\n", 30);
                $worker->mustRun();
                $this->assertSame("committed\n", $worker->getOutput());
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->count(), 'Ordinary reads retain the old snapshot.');
                try {
                    if (str_starts_with($operation, 'delete')) {
                        $files->delete($actor, $workspace, 'synthetic-snapshot', 'synthetic-delete', $first['data']['id'], ['expected_version' => $deleteVersion, 'confirm' => true]);
                    } elseif (str_starts_with($operation, 'expense')) {
                        $receipts->upload($actor, $workspace, 'synthetic-snapshot', 'synthetic-expense', $expense->public_id,
                            ['expected_version' => $expenseVersion], $file);
                    } else {
                        $files->upload($actor, $workspace, 'synthetic-snapshot', 'synthetic-upload', 'project', $project->public_id, $file,
                            ['expected_version' => $projectVersion, 'file' => $file]);
                    }
                } catch (HttpExceptionInterface $e) {
                    $refusal = $e->getStatusCode();
                }
            });
            $this->assertSame(403, $refusal);
            $this->assertSame($beforeRows, ClientAttachment::query()->where('workspace_id', $workspace->id)->get()->map->getAttributes()->all());
            $this->assertSame($beforeObjects, Storage::disk('svc_files')->allFiles());
            $this->assertSame($beforeReceipts, DB::table('agent_mutation_receipts')->where('workspace_id', $workspace->id)->count());
        } finally {
            config(['database.default' => $original]);
        }
    }
}
