<?php

namespace Tests\Unit\Billing;

use App\Console\Commands\Billing\RehearseGenerationCommand;
use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Exceptions\BillingMaintenanceBusy;
use App\Services\Billing\BillingMaintenance;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LogicException;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BillingMaintenanceProtocolTest extends TestCase
{
    public static function modes(): iterable
    {
        yield 'writer' => [null, false];
        yield 'replay' => [ReplayInvoicesCommand::class, true];
        yield 'rehearsal' => [RehearseGenerationCommand::class, true];
    }

    #[DataProvider('modes')]
    public function test_the_workspace_lock_uses_the_requested_mode_and_restores_the_session_bound(?string $command, bool $exclusive): void
    {
        $query = $this->lockQuery();
        $query->shouldReceive('first')->once()->andReturn((object) ['workspace_id' => 7]);
        $command === null ? BillingMaintenance::share(7) : BillingMaintenance::exclusive(7, $command);
        $this->assertSame($exclusive, $query->lock);
    }

    #[DataProvider('modes')]
    public function test_a_timeout_is_a_retryable_conflict_and_the_old_session_bound_is_restored(?string $command, bool $exclusive): void
    {
        $query = $this->lockQuery();
        $query->shouldReceive('first')->once()->andThrow($this->error(1205));
        try {
            $command === null ? BillingMaintenance::share(7) : BillingMaintenance::exclusive(7, $command);
            $this->fail('The lock timeout was accepted.');
        } catch (BillingMaintenanceBusy $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(['Retry-After' => '2'], $exception->getHeaders());
            $this->assertSame($exclusive ? 'Billing writers are in progress; maintenance could not start within 2 seconds. Nothing changed.'
                : 'Billing maintenance in progress, try again.', $exception->getMessage());
        }
    }

    public function test_a_different_database_error_is_not_disguised_as_a_maintenance_timeout(): void
    {
        $error = $this->error(1213);
        $query = $this->lockQuery();
        $query->shouldReceive('first')->once()->andThrow($error);
        $this->expectExceptionObject($error);
        BillingMaintenance::share(7);
    }

    public function test_a_missing_tenant_lock_fails_closed(): void
    {
        $query = $this->lockQuery();
        $query->shouldReceive('first')->once()->andReturnNull();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The workspace billing maintenance row is missing.');
        BillingMaintenance::share(7);
    }

    public function test_autocommit_cannot_release_the_lock_before_the_writer(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Billing maintenance locks must be held by a transaction.');
        BillingMaintenance::share(7);
    }

    public function test_only_the_two_rollback_only_commands_may_take_the_lock_exclusively(): void
    {
        $sites = [];
        $exemptionSites = [];
        foreach (File::allFiles(app_path()) as $file) {
            if (str_contains($file->getContents(), 'BillingMaintenance::exclusive(')) {
                $sites[] = $file->getRelativePathname();
            }
            if (str_contains($file->getContents(), 'LockOrderRecorder::maintenanceExclusive(')) {
                $exemptionSites[] = $file->getRelativePathname();
            }
        }
        sort($sites);
        $this->assertSame(['Console/Commands/Billing/RehearseGenerationCommand.php', 'Console/Commands/Billing/ReplayInvoicesCommand.php'], $sites);
        $this->assertSame(['Services/Billing/BillingMaintenance.php'], $exemptionSites);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only the rollback-only billing commands may acquire maintenance exclusively.');
        BillingMaintenance::exclusive(7, self::class);
    }

    public static function unreadableSettings(): iterable
    {
        yield 'no result' => [null];
        yield 'wrong fetch shape' => [['seconds' => 37]];
        yield 'missing setting' => [(object) []];
        yield 'null setting' => [(object) ['seconds' => null]];
        yield 'unreadable setting' => [(object) ['seconds' => 'synthetic-invalid']];
    }

    #[DataProvider('unreadableSettings')]
    public function test_an_unreadable_wait_setting_refuses_the_protocol_before_acquiring_any_lock(mixed $setting): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->once()->with('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->andReturn($setting);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The billing lock wait setting could not be read.');
        BillingMaintenance::share(7);
    }

    public static function drivers(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'mariadb' => ['mariadb'];
        yield 'sqlite' => ['sqlite'];
    }

    #[DataProvider('drivers')]
    public function test_the_share_mode_applies_on_each_supported_database_driver(string $driver): void
    {
        $query = $this->lockQuery($driver);
        $query->shouldReceive('first')->once()->andReturn((object) ['workspace_id' => 7]);
        BillingMaintenance::share(7);
        $this->assertFalse($query->lock);
    }

    private function lockQuery(string $driver = 'mariadb'): Builder
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('getDriverName')->once()->andReturn($driver);
        if ($driver !== 'sqlite') {
            $connection->shouldReceive('selectOne')->once()->with('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->andReturn((object) ['seconds' => 37]);
            $connection->shouldReceive('statement')->once()->with('SET SESSION innodb_lock_wait_timeout = 2')->andReturnTrue();
            $connection->shouldReceive('statement')->once()->with('SET SESSION innodb_lock_wait_timeout = 37')->andReturnTrue();
        }
        $query = Mockery::mock(Builder::class)->makePartial();
        $query->from = 'billing_maintenance_locks';
        $query->shouldReceive('where')->once()->with('workspace_id', 7)->andReturnSelf();
        $connection->shouldReceive('table')->once()->with('billing_maintenance_locks')->andReturn($query);
        DB::shouldReceive('connection')->once()->andReturn($connection);

        return $query;
    }

    private function error(int $code): QueryException
    {
        $error = new PDOException('Synthetic lock failure');
        $error->errorInfo = ['HY000', $code, 'Synthetic lock failure'];

        return new QueryException('synthetic', 'select synthetic', [], $error);
    }
}
