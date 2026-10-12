<?php

namespace App\Services\Billing;

use App\Console\Commands\Billing\RehearseGenerationCommand;
use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Exceptions\BillingMaintenanceBusy;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\Locks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Writers that lock time entries or tasks take the maintenance share lock first. */
final class BillingMaintenance
{
    public static function share(int $workspaceId): void
    {
        self::acquire($workspaceId, false);
    }

    /** @param class-string<ReplayInvoicesCommand>|class-string<RehearseGenerationCommand> $command */
    public static function exclusive(int $workspaceId, string $command): void
    {
        if (! in_array($command, [ReplayInvoicesCommand::class, RehearseGenerationCommand::class], true)) {
            throw new LogicException('Only the rollback-only billing commands may acquire maintenance exclusively.');
        }
        self::acquire($workspaceId, true);
        // Mark only after the locking query succeeds. A timed-out command has
        // no exemption, and a built-but-unexecuted query cannot create one.
        LockOrderRecorder::maintenanceExclusive($command);
    }

    private static function acquire(int $workspaceId, bool $exclusive): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Billing maintenance locks must be held by a transaction.');
        }
        $timeout = null;
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $setting = $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds');
            if (! $setting instanceof \stdClass || ! isset($setting->seconds) || ! is_numeric($setting->seconds)) {
                throw new LogicException('The billing lock wait setting could not be read.');
            }
            $timeout = (int) $setting->seconds;
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 2');
        }
        try {
            $row = $connection->table('billing_maintenance_locks')->where('workspace_id', $workspaceId)
                ->tap($exclusive ? Locks::forUpdate() : Locks::forShare())->first();
            if ($row === null) {
                throw new LogicException('The workspace billing maintenance row is missing.');
            }
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1205) {
                throw new BillingMaintenanceBusy($exclusive);
            }
            throw $exception;
        } finally {
            if ($timeout !== null) {
                $connection->statement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
            }
        }
    }
}
