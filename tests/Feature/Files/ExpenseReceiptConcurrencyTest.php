<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Files\AttachmentStorageService;
use App\Support\Expenses\NewExpense;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class ExpenseReceiptConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public static function writers(): iterable
    {
        yield 'upload then discard' => ['upload', 'discard'];
        yield 'discard then upload' => ['discard', 'upload'];
    }

    #[DataProvider('writers')]
    public function test_upload_and_discard_contend_on_the_live_expense_lock(string $firstWriter, string $secondWriter): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row-lock contention is exercised in the MariaDB lane.');
        }
        $this->bootProbeDatabase('expense_receipt_probe');
        $original = config('database.default');
        Artisan::call('migrate', ['--database' => 'expense_receipt_probe', '--force' => true]);
        config(['database.default' => 'expense_receipt_probe']);
        $storage = sys_get_temp_dir().'/svc-receipt-probe-'.bin2hex(random_bytes(8));
        File::makeDirectory($storage);
        $processes = [];
        try {
            $user = User::factory()->create();
            $workspace = Workspace::query()->create(['name' => 'Synthetic receipt race', 'slug' => 'synthetic-receipt-race']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic receipt race', 'slug' => 'synthetic-receipt-race']);
            $expense = (new WorkspaceExpenses($workspace))->record($company, null, new NewExpense(app(WorkspaceClock::class)->today($workspace), 1200, 'USD', 'Synthetic receipt race'));
            $configuration = ['connection' => config('database.connections.expense_receipt_probe'), 'user' => $user->id, 'workspace' => $workspace->id, 'expense' => $expense->id, 'storage' => $storage];
            $firstInput = new InputStream;
            $firstInput->write(json_encode($configuration + ['operation' => $firstWriter, 'hold' => true], JSON_THROW_ON_ERROR)."\n");
            $first = $this->worker($firstInput);
            $processes[] = $first;
            $first->start();
            $this->assertTrue($first->waitUntil(fn (string $type, string $output): bool => str_contains($first->getOutput(), "locked\n")), $first->getOutput());

            $secondInput = new InputStream;
            $secondInput->write(json_encode($configuration + ['operation' => $secondWriter, 'hold' => false], JSON_THROW_ON_ERROR)."\n");
            $secondInput->close();
            $second = $this->worker($secondInput);
            $processes[] = $second;
            $second->start();
            // Process startup is too early: wait until the worker reaches expense lock SQL.
            $this->assertTrue($second->waitUntil(fn (string $type, string $output): bool => str_contains($second->getOutput(), "attempting-lock\n")), $second->getOutput());
            $this->assertSame(1, preg_match('/connection:(\d+)/', $second->getOutput(), $match));
            $waiting = false;
            $deadline = microtime(true) + 10;
            do {
                // Correlate the active server session with its InnoDB transaction.
                $workerState = DB::selectOne('select COMMAND, STATE from information_schema.PROCESSLIST where ID = ?', [(int) $match[1]]);
                $transaction = DB::selectOne('select trx_state from information_schema.innodb_trx where trx_mysql_thread_id = ?', [(int) $match[1]]);
                $waiting = $workerState !== null && $workerState->COMMAND !== 'Sleep' && $transaction?->trx_state === 'LOCK WAIT';
                if (! $waiting) {
                    // MariaDB's trx0i_s.cc CACHE_MIN_IDLE_TIME_NS requires 100ms
                    // without a read; faster polling pins the old snapshot forever.
                    // One second also leaves idle time with four ParaTest workers.
                    usleep(1_000_000);
                }
            } while (! $waiting && $second->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second writer must actually wait on the first expense lock. Exit: '.var_export($second->getExitCode(), true).' State: '.($workerState?->STATE ?? 'missing').' '.$second->getOutput());
            $firstInput->write("release\n");
            $firstInput->close();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getOutput());
            $this->assertStringContainsString('outcome:success', $first->getOutput());
            $this->assertStringContainsString($firstWriter === 'discard' ? 'outcome:404' : 'outcome:success', $second->getOutput());
            $this->assertSame(0, ClientExpense::query()->where('workspace_id', $workspace->id)->count());
            $this->assertSame(1, ClientExpense::withTrashed()->where('workspace_id', $workspace->id)->count());
            $this->assertSame(1, ClientAttachment::query()->where('workspace_id', $workspace->id)->count());
            if ($firstWriter === 'discard') {
                $this->assertSame('staged', ClientAttachment::query()->where('workspace_id', $workspace->id)->sole()->lifecycle_state);
            }
            $this->assertCount($firstWriter === 'upload' ? 1 : 0, File::allFiles($storage));
            if ($firstWriter === 'upload') {
                $attachment = ClientAttachment::query()->where('workspace_id', $workspace->id)->sole();
                $this->assertSame('available', $attachment->lifecycle_state);
                $this->assertFileExists($storage.'/'.$attachment->object_key);
            }

        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            config(['database.default' => $original]);
            File::deleteDirectory($storage);
        }
    }

    public function test_killed_uploader_leaves_a_durable_row_so_repair_cleans_the_promoted_blob(): void
    {
        $this->bootProbeDatabase('expense_receipt_crash_probe');
        $original = config('database.default');
        Artisan::call('migrate', ['--database' => 'expense_receipt_crash_probe', '--force' => true]);
        config(['database.default' => 'expense_receipt_crash_probe']);
        $storage = sys_get_temp_dir().'/svc-receipt-probe-'.bin2hex(random_bytes(8));
        File::makeDirectory($storage);
        config(['svc.filesystem_disk' => 'receipt_crash', 'filesystems.disks.receipt_crash' => ['driver' => 'local', 'root' => $storage]]);
        $process = null;
        try {
            $user = User::factory()->create();
            $workspace = Workspace::query()->create(['name' => 'Synthetic receipt crash', 'slug' => 'synthetic-receipt-crash']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic receipt crash', 'slug' => 'synthetic-receipt-crash']);
            $expense = (new WorkspaceExpenses($workspace))->record($company, null, new NewExpense(app(WorkspaceClock::class)->today($workspace), 1200, 'USD', 'Synthetic receipt crash'));
            $input = new InputStream;
            $input->write(json_encode(['connection' => config('database.connections.expense_receipt_crash_probe'), 'workspace' => $workspace->id, 'user' => $user->id, 'expense' => $expense->id,
                'storage' => $storage, 'operation' => 'upload', 'hold' => false, 'crash' => true], JSON_THROW_ON_ERROR)."\n");
            $process = $this->worker($input);
            $process->start();
            $this->assertTrue($process->waitUntil(fn (string $type, string $output): bool => str_contains($process->getOutput(), "promoted\n")), $process->getOutput());
            // Kill rather than throw: PHP catch/finally compensation must not run.
            $process->signal(9);
            $process->wait();
            $this->assertFalse($process->isSuccessful());
            $attachment = ClientAttachment::query()->where('workspace_id', $workspace->id)->sole();
            $this->assertSame(ClientAttachment::STATE_STAGED, $attachment->lifecycle_state);
            $this->assertNotNull($attachment->staged_object_key);
            $this->assertFileExists($storage.'/'.$attachment->object_key);
            $this->assertFileDoesNotExist($storage.'/'.$attachment->staged_object_key);
            $counts = app(AttachmentStorageService::class)->repair(true, stagedAgeMinutes: 0);
            $this->assertSame(1, $counts['staged_rows']);
            $this->assertSame(ClientAttachment::STATE_DELETED, ClientAttachment::query()->where('workspace_id', $workspace->id)->whereKey($attachment->id)->sole()->lifecycle_state);
            $this->assertSame([], File::allFiles($storage));
        } finally {
            $process?->stop(0);
            config(['database.default' => $original]);
            File::deleteDirectory($storage);
        }
    }

    private function worker(InputStream $input): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Fixtures/Files/expense-receipt-worker.php')], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], $input, 30);
    }
}
