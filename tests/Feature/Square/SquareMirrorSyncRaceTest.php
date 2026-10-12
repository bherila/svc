<?php

namespace Tests\Feature\Square;

use App\Models\SquareSyncRun;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\FakesSquare;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * Two Square mirror syncs at once, in separate processes, against MariaDB
 * (#438).
 *
 * Each worker reads the same synthetic invoices and then waits until both
 * have, so both decide every invoice is new before either writes one. The
 * unique (workspace, Square id) keys and insert-or-ignore leave exactly one
 * row per Square object, and both runs complete.
 */
final class SquareMirrorSyncRaceTest extends TestCase
{
    use FakesSquare;
    use UsesAProbeDatabase;

    public function test_two_concurrent_syncs_leave_one_row_per_square_object(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Cross-process insert contention is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('mirror_race');
        Artisan::call('migrate', ['--database' => 'mirror_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('mirror_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-square-mirror-race-'.Str::lower(Str::random(16));
        $paths = [
            'first-ready' => $barrier.'-first-ready',
            'second-ready' => $barrier.'-second-ready',
            'start' => $barrier.'-start',
            'first-fetched' => $barrier.'-first-fetched',
            'second-fetched' => $barrier.'-second-fetched',
            'write' => $barrier.'-write',
        ];
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic mirror race', 'slug' => 'synthetic-mirror-race']);
            $this->connectSquare($workspace);
            $paid = self::squareInvoice('inv:0-fixture2', '000001-R-0002');
            $paid['status'] = 'PAID';
            $paid['payment_requests'][0]['total_completed_amount_money'] = ['amount' => 12_345, 'currency' => 'USD'];
            $input = [
                'workspace' => $workspace->id,
                'square' => config('services.square'),
                'locations' => [self::squareLocation()],
                'customers' => [self::squareCustomer(), self::squareCustomer('cust_fixture_b')],
                'invoices' => [self::squareInvoice(), $paid, self::squareInvoice('inv:0-fixture3', '000002')],
                'order' => self::squareOrder(),
            ];

            $first = $this->worker($input, $paths['first-ready'], $paths['start'], $paths['first-fetched'], $paths['write']);
            $second = $this->worker($input, $paths['second-ready'], $paths['start'], $paths['second-fetched'], $paths['write']);
            $processes = [$first, $second];
            $first->start();
            $second->start();
            $this->awaitFile($first, $paths['first-ready']);
            $this->awaitFile($second, $paths['second-ready']);
            $this->assertTrue(touch($paths['start']), 'Could not start the syncs.');
            $this->awaitFile($first, $paths['first-fetched']);
            $this->awaitFile($second, $paths['second-fetched']);
            $this->assertTrue(touch($paths['write']), 'Could not release the syncs.');
            $first->wait();
            $second->wait();
            $results = [$this->workerResult($first), $this->workerResult($second)];
            $context = json_encode($results, JSON_THROW_ON_ERROR);

            DB::purge('mirror_race');
            $this->assertSame(['completed', 'completed'], array_column($results, 'outcome'), $context);
            foreach (['square_invoice_snapshots' => 'square_invoice_id', 'square_customer_snapshots' => 'square_customer_id', 'square_location_snapshots' => 'square_location_id'] as $table => $key) {
                $ids = DB::table($table)->where('workspace_id', $workspace->id)->pluck($key)->all();
                sort($ids);
                $this->assertSame(array_values(array_unique($ids)), $ids, "{$table} holds a Square object twice. {$context}");
            }
            $this->assertSame(3, DB::table('square_invoice_snapshots')->where('workspace_id', $workspace->id)->count(), $context);
            $this->assertSame(2, DB::table('square_customer_snapshots')->where('workspace_id', $workspace->id)->count(), $context);
            // Between them the two runs wrote each invoice once.
            $this->assertSame(3, array_sum(array_column($results, 'changed')), $context);
            $this->assertSame(2, SquareSyncRun::query()->where('workspace_id', $workspace->id)->where('status', SquareSyncRun::COMPLETED)->count(), $context);
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            foreach ($paths as $path) {
                @unlink($path);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /** @param  array<string, mixed>  $input */
    private function worker(array $input, string $ready, string $start, string $fetched, string $write): Process
    {
        $payload = base64_encode(json_encode([
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'ready' => $ready,
            'start' => $start,
            'fetched' => $fetched,
            'write' => $write,
            ...$input,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Square/mirror-sync-race-worker.php'), $payload],
            base_path(),
            ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
            null,
            60,
        );
    }

    private function awaitFile(Process $process, string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
