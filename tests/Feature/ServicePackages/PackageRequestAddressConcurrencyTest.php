<?php

namespace Tests\Feature\ServicePackages;

use App\Models\ServicePackageRequest;
use App\Services\Packages\PackageRequestWorkflow;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageRequestStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Two public submissions from one address at once, in two MariaDB sessions
 * (#667). Each counts the address's open requests and then inserts one; the
 * address's lock key serializes them, so the second counts the first's request
 * and the cap holds - also for an address whose key does not exist yet.
 */
final class PackageRequestAddressConcurrencyTest extends TestCase
{
    use BuildsServicePackages;
    use UsesAProbeDatabase;

    /** @return iterable<string, array{bool}> */
    public static function keys(): iterable
    {
        yield 'an address with no key yet' => [false];
        // Two submissions meeting an existing key must not deadlock (#715).
        yield 'an address whose key exists' => [true];
    }

    #[DataProvider('keys')]
    #[Requirement('PUB-5')]
    public function test_a_second_submission_waits_for_the_first_and_the_open_request_cap_holds(bool $keyExists): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row-lock contention is exercised in the MariaDB lane.');
        }
        $this->bootProbeDatabase('request_address_probe');
        $original = config('database.default');
        Artisan::call('migrate', ['--database' => 'request_address_probe', '--force' => true]);
        config(['database.default' => 'request_address_probe', 'mail.default' => 'array']);
        $processes = [];
        try {
            [$workspace, $owner] = $this->packageWorkspace();
            $package = $this->publishedPackage($workspace, $owner);
            for ($i = 1; $i < PackageRequestWorkflow::MAX_OPEN_PER_ADDRESS; $i++) {
                $this->packageRequest($workspace, $package, ['company_name' => "Earlier synthetic request {$i}"]);
            }
            if (! $keyExists) {
                // As for an address seen before this key existed: none yet.
                DB::table('service_package_request_addresses')->where('workspace_id', $workspace->id)->delete();
            }
            $this->assertSame($keyExists ? 1 : 0, DB::table('service_package_request_addresses')->where('workspace_id', $workspace->id)->count());
            $configuration = ['connection' => config('database.connections.request_address_probe'), 'workspace' => $workspace->id, 'package' => $package->id];

            $firstInput = new InputStream;
            $firstInput->write(json_encode($configuration + ['company' => 'First synthetic racer', 'hold' => true], JSON_THROW_ON_ERROR)."\n");
            $first = $this->worker($firstInput);
            $processes[] = $first;
            $first->start();
            $this->assertTrue($first->waitUntil(fn (): bool => str_contains($first->getOutput(), "locked\n")), $first->getOutput());

            $secondInput = new InputStream;
            $secondInput->write(json_encode($configuration + ['company' => 'Second synthetic racer', 'hold' => false], JSON_THROW_ON_ERROR)."\n");
            $secondInput->close();
            $second = $this->worker($secondInput);
            $processes[] = $second;
            $second->start();
            $this->assertTrue($second->waitUntil(fn (): bool => str_contains($second->getOutput(), "attempting-lock\n")), $second->getOutput());
            if (preg_match('/connection:(\d+)/', $second->getOutput(), $match) !== 1) {
                $this->fail('The second worker did not report its connection: '.$second->getOutput());
            }
            $connectionId = (int) $match[1];
            $waiting = false;
            $deadline = microtime(true) + 10;
            do {
                $transaction = DB::selectOne('select trx_state from information_schema.innodb_trx where trx_mysql_thread_id = ?', [$connectionId]);
                $waiting = $transaction?->trx_state === 'LOCK WAIT';
                if (! $waiting) {
                    // MariaDB refreshes this view only after 100ms without a read.
                    usleep(1_000_000);
                }
            } while (! $waiting && $second->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second submission must wait on the first one\'s address key, not deadlock. '.$second->getOutput());

            $firstInput->write("release\n");
            $firstInput->close();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getOutput());
            $this->assertStringEndsWith("sent\n", $first->getOutput());
            $this->assertStringEndsWith(PackageRefusal::RequestRefused->value."\n", $second->getOutput());
            $this->assertSame(PackageRequestWorkflow::MAX_OPEN_PER_ADDRESS, ServicePackageRequest::query()
                ->where('workspace_id', $workspace->id)->where('status', PackageRequestStatus::New->value)->count());
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            config(['database.default' => $original]);
        }
    }

    private function worker(InputStream $input): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Fixtures/Packages/request-address-worker.php')], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], $input, 30);
    }
}
