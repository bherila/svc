<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/** Actual maintenance commands against concurrent generation, with observed row-lock waits. */
final class BillingMaintenanceConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public static function orders(): iterable
    {
        foreach (['replay', 'rehearse'] as $command) {
            yield $command.' first' => [$command, 'maintenance'];
            yield 'writer before '.$command => [$command, 'writer'];
            yield 'writer timeout during '.$command => [$command, 'writer_timeout'];
            yield $command.' excludes only its workspace' => [$command, 'other_workspace'];
        }
    }

    #[DataProvider('orders')]
    public function test_maintenance_excludes_writers_with_bounded_waits_unchanged_data_and_no_deadlock(string $command, string $order): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Maintenance contention is proved on the database CI lane.');
        }
        $this->bootProbeDatabase('maintenance_race');
        Artisan::call('migrate', ['--database' => 'maintenance_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('maintenance_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $paths = [];
        $barrier = sys_get_temp_dir().'/svc-maintenance-race-'.Str::lower(Str::random(16));
        foreach (['first', 'second'] as $worker) {
            foreach (['ready', 'acquired', 'release'] as $checkpoint) {
                $paths[$worker][$checkpoint] = $barrier.'-'.$worker.'-'.$checkpoint;
            }
        }
        try {
            $maintenance = $this->fixture('maintenance');
            $writer = $order === 'other_workspace' ? $this->fixture('other-workspace') : $maintenance;
            $before = $this->snapshot($maintenance['workspace']);
            $invoiceCount = ClientInvoice::query()->where('workspace_id', $writer['workspace'])->count();
            $first = $this->worker($order === 'writer' ? 'generate' : $command, $order === 'writer' ? $writer : $maintenance, $paths['first']);
            $second = $this->worker($order === 'writer' ? $command : 'generate', $order === 'writer' ? $maintenance : $writer, $paths['second']);
            $processes = [$first, $second];
            $first->start();
            $this->awaitFile($first, $paths['first']['acquired']);
            $second->start();
            $this->awaitFile($second, $paths['second']['ready']);
            if ($order !== 'other_workspace') {
                $this->assertObservedWait($first, $second, $paths);
            }
            if ($order === 'writer_timeout') {
                $second->wait();
                $result = $this->workerResult($second);
                $this->assertSame(1, $result['exit']);
                $this->assertSame(409, $result['status']);
                $this->assertSame('Billing maintenance in progress, try again.', $result['output']);
                $this->assertLessThan(4, $result['seconds']);
                $this->assertSame(0, $result['transaction_level']);
                $this->assertFileDoesNotExist($paths['second']['acquired']);
                $this->assertTrue(touch($paths['first']['release']));
                $first->wait();
                $this->assertSame(0, $this->workerResult($first)['exit']);
                $this->assertSame($before, $this->snapshot($maintenance['workspace']));
            } elseif ($order === 'writer') {
                $second->wait();
                $result = $this->workerResult($second);
                $this->assertSame(1, $result['exit'], $result['output']);
                $this->assertStringContainsString('maintenance could not start within 2 seconds. Nothing changed.', $result['output']);
                $this->assertLessThan(4, $result['seconds'], 'The maintenance wait must be bounded.');
                $this->assertSame(0, $result['transaction_level']);
                $this->assertFileDoesNotExist($paths['second']['acquired']);
                $this->assertSame($before, $this->snapshot($maintenance['workspace']), 'A refused command wrote billing data.');
                $this->assertTrue(touch($paths['first']['release']));
                $first->wait();
                $this->assertSame(0, $this->workerResult($first)['exit']);
            } else {
                if ($order === 'other_workspace') {
                    // The other tenant acquires its share lock while maintenance
                    // still holds the first tenant exclusively.
                    $this->awaitFile($second, $paths['second']['acquired']);
                }
                $this->assertTrue(touch($paths['first']['release']));
                $first->wait();
                $result = $this->workerResult($first);
                $this->assertSame(0, $result['exit'], $result['output']);
                $this->assertSame(0, $result['transaction_level']);
                $this->awaitFile($second, $paths['second']['acquired']);
                $this->assertSame($before, $this->snapshot($maintenance['workspace']), 'Maintenance must roll back every row before a waiting writer runs.');
                $this->assertTrue(touch($paths['second']['release']));
                $second->wait();
                $this->assertSame(0, $this->workerResult($second)['exit']);
            }
            $this->assertSame($invoiceCount + ($order === 'writer_timeout' ? 0 : 1), ClientInvoice::query()->where('workspace_id', $writer['workspace'])->count());
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            foreach ($paths as $checkpoints) {
                foreach ($checkpoints as $path) {
                    @unlink($path);
                }
            }
            Carbon::setTestNow();
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /** @return array{workspace:int, workspace_public_id:string, company:int, company_public_id:string, agreement:int} */
    private function fixture(string $name): array
    {
        $user = User::factory()->create(['email' => $name.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$name, 'slug' => 'synthetic-'.$name]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Client', 'slug' => 'synthetic-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Project']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'title' => 'Synthetic Agreement', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-07-01',
            'retainer_minutes' => 120, 'retainer_amount' => 30000, 'hourly_rate_amount' => 12000,
            'catch_up_threshold_minutes' => 0, 'billing_cadence' => 'monthly', 'bill_overage_interim' => false, 'rollover_months' => 0]);
        foreach (['2026-07-14', '2026-08-14'] as $date) {
            ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'client_project_id' => $project->id, 'user_id' => $user->id, 'worked_on' => $date, 'minutes' => 600,
                'description' => 'Synthetic work', 'is_billable' => true, 'is_deferred' => false, 'status' => 'approved',
                'billing_rate_amount' => 12000, 'billing_rate_source' => 'agreement', 'currency' => 'USD']);
        }
        ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
            'title' => 'Synthetic Milestone', 'status' => 'completed', 'completed_at' => '2026-07-20', 'milestone_price_amount' => 25000]);
        Carbon::setTestNow('2026-09-01');
        $result = app(ClientInvoicingService::class)->generateAllInvoices($company);
        $this->assertGreaterThanOrEqual(2, count($result['generated']));

        return ['workspace' => $workspace->id, 'workspace_public_id' => $workspace->public_id,
            'company' => $company->id, 'company_public_id' => $company->public_id, 'agreement' => $agreement->id];
    }

    /** @return array<string, list<array<string,mixed>>> */
    private function snapshot(int $workspaceId): array
    {
        $rows = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (! Schema::hasColumn($name, 'workspace_id')) {
                continue;
            }
            $data = DB::table($name)->where('workspace_id', $workspaceId)->get()->map(static fn ($row): array => (array) $row)->all();
            usort($data, static fn (array $left, array $right): int => json_encode($left, JSON_THROW_ON_ERROR) <=> json_encode($right, JSON_THROW_ON_ERROR));
            $rows[$name] = $data;
        }
        $rows['workspaces'] = [(array) DB::table('workspaces')->where('id', $workspaceId)->sole()];
        ksort($rows);

        return $rows;
    }

    /** @param array<string,mixed> $ids @param array<string,string> $paths */
    private function worker(string $operation, array $ids, array $paths): Process
    {
        return new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Fixtures/Billing/maintenance-exclusion-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
            json_encode(['connection' => config('database.connections.maintenance_race'), 'operation' => $operation, ...$ids, ...$paths], JSON_THROW_ON_ERROR)."\n", 30);
    }

    private function awaitFile(Process $process, string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }

    /** @param array<string,array<string,string>> $paths */
    private function assertObservedWait(Process $first, Process $second, array $paths): void
    {
        $blocker = json_decode(file_get_contents($paths['first']['ready']), true, flags: JSON_THROW_ON_ERROR)['connection_id'];
        $waiter = json_decode(file_get_contents($paths['second']['ready']), true, flags: JSON_THROW_ON_ERROR)['connection_id'];
        $deadline = microtime(true) + 1.5;
        do {
            $wait = DB::selectOne('SELECT 1 AS present FROM information_schema.INNODB_LOCK_WAITS waits
                JOIN information_schema.INNODB_TRX requesting ON requesting.trx_id = waits.requesting_trx_id
                JOIN information_schema.INNODB_TRX blocking ON blocking.trx_id = waits.blocking_trx_id
                WHERE requesting.trx_mysql_thread_id = ? AND blocking.trx_mysql_thread_id = ?', [$waiter, $blocker]);
            if ($wait !== null) {
                $this->assertTrue($first->isRunning());
                $this->assertTrue($second->isRunning());

                return;
            }
            usleep(10_000);
        } while ($second->isRunning() && microtime(true) < $deadline);
        $this->fail('No real lock wait was observed: '.$first->getOutput().$first->getErrorOutput().$second->getOutput().$second->getErrorOutput());
    }

    /** @return array<string,mixed> */
    private function workerResult(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());

        return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }
}
