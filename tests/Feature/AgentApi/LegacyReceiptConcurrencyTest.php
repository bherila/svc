<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationReceipt;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class LegacyReceiptConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public static function writers(): iterable
    {
        yield 'old reservation wins' => ['old', 'new'];
        yield 'new reservation wins' => ['new', 'old'];
        yield 'independent authenticated clients contend' => ['new', 'new'];
    }

    #[DataProvider('writers')]
    public function test_two_connections_contend_on_the_compatibility_key(string $firstWriter, string $secondWriter): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row-lock contention is exercised in the MariaDB lane.');
        }
        $this->bootProbeDatabase('receipt_probe');
        $original = config('database.default');
        Artisan::call('migrate', ['--database' => 'receipt_probe', '--force' => true]);
        config(['database.default' => 'receipt_probe']);
        $processes = [];
        try {
            $user = User::factory()->create();
            $workspace = Workspace::query()->create(['name' => 'Synthetic receipt race', 'slug' => 'synthetic-receipt-race']);
            $configuration = ['connection' => config('database.connections.receipt_probe'), 'user' => $user->id, 'workspace' => $workspace->id];
            $firstInput = new InputStream;
            $firstInput->write(json_encode($configuration + ['writer' => $firstWriter, 'client' => 'synthetic-first-client', 'hold' => true], JSON_THROW_ON_ERROR)."\n");
            $first = $this->worker($firstInput);
            $processes[] = $first;
            $first->start();
            $this->assertTrue($first->waitUntil(fn (string $type, string $output): bool => str_contains($first->getOutput(), "reserved\n")), $first->getOutput());

            $secondInput = new InputStream;
            $secondInput->write(json_encode($configuration + ['writer' => $secondWriter, 'client' => 'synthetic-second-client', 'hold' => false], JSON_THROW_ON_ERROR)."\n");
            $secondInput->close();
            $second = $this->worker($secondInput);
            $processes[] = $second;
            $second->start();
            // Process startup is too early: wait until the worker reaches receipt SQL.
            $this->assertTrue($second->waitUntil(fn (string $type, string $output): bool => str_contains($second->getOutput(), "attempting-receipt\n")), $second->getOutput());
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
            $this->assertTrue($waiting, 'The second writer must actually wait on the first reservation. Exit: '.var_export($second->getExitCode(), true).' State: '.($workerState?->STATE ?? 'missing').' '.$second->getOutput());
            $firstInput->write("release\n");
            $firstInput->close();
            $first->wait();
            $second->wait();
            $firstResult = $this->workerResult($first);
            $secondResult = $this->workerResult($second);
            $this->assertSame('success', $firstResult['outcome']);
            $this->assertTrue($firstResult['callback']);
            $bothNew = $firstWriter === 'new' && $secondWriter === 'new';
            $this->assertSame($bothNew ? 'success' : 409, $secondResult['outcome']);
            $this->assertSame($bothNew, $secondResult['callback']);
            $this->assertSame($bothNew ? 2 : 1, AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('status', 'completed')->count());
            if (! $bothNew) {
                $this->assertArrayNotHasKey('ids', $secondResult);
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            config(['database.default' => $original]);
        }
    }

    private function worker(InputStream $input): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Fixtures/AgentApi/receipt-race-worker.php')], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], $input, 30);
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
