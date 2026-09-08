<?php

namespace Tests\Feature\Expenses;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Support\Billing\BillingCadence;
use App\Support\Expenses\NewExpense;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class ExpenseRecurrenceConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public static function writers(): iterable
    {
        yield 'generation then generation' => ['generate', 'generate'];
        yield 'generation then pause' => ['generate', 'pause'];
        yield 'pause then generation' => ['pause', 'generate'];
    }

    #[DataProvider('writers')]
    public function test_generation_and_pause_wait_for_the_same_schedule_lock(string $firstWriter, string $secondWriter): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row-lock contention is exercised in the MariaDB lane.');
        }
        $this->bootProbeDatabase('recurrence_probe');
        $original = config('database.default');
        Artisan::call('migrate', ['--database' => 'recurrence_probe', '--force' => true]);
        config(['database.default' => 'recurrence_probe']);
        $processes = [];
        try {
            $user = User::factory()->create();
            $workspace = Workspace::query()->create(['name' => 'Synthetic recurrence race', 'slug' => 'synthetic-recurrence-race']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic recurrence race', 'slug' => 'synthetic-recurrence-race']);
            $schedule = (new WorkspaceExpenseSchedules($workspace))->create($company, null, new NewExpense(app(WorkspaceClock::class)->today($workspace), 1200, 'USD', 'Synthetic recurrence race'), BillingCadence::Monthly);
            $configuration = ['connection' => config('database.connections.recurrence_probe'), 'user' => $user->id, 'workspace' => $workspace->id, 'schedule' => $schedule->public_id];
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
            // Process startup is too early: wait until the worker reaches schedule lock SQL.
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
            $this->assertTrue($waiting, 'The second writer must actually wait on the first schedule lock. Exit: '.var_export($second->getExitCode(), true).' State: '.($workerState?->STATE ?? 'missing').' '.$second->getOutput());
            $firstInput->write("release\n");
            $firstInput->close();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getOutput());
            $this->assertSame($firstWriter === 'pause' ? 0 : 1, ClientExpense::query()->where('workspace_id', $workspace->id)->count());
            $this->assertSame($firstWriter === 'pause' ? 0 : 1, $schedule->refresh()->next_occurrence);
            $this->assertSame($firstWriter === 'generate' && $secondWriter === 'generate', $schedule->is_active);

        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            config(['database.default' => $original]);
        }
    }

    private function worker(InputStream $input): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Fixtures/Expenses/recurrence-worker.php')], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], $input, 30);
    }
}
