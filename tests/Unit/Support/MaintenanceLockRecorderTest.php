<?php

namespace Tests\Unit\Support;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Services\Billing\BillingMaintenance;
use App\Support\Concurrency\LockMode;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MaintenanceLockRecorderTest extends TestCase
{
    protected function tearDown(): void
    {
        LockOrderRecorder::stop();
        parent::tearDown();
    }

    public function test_modes_and_exemption_follow_the_outer_transaction_and_never_leak_to_the_next(): void
    {
        LockOrderRecorder::start();
        DB::beginTransaction();
        DB::table('billing_maintenance_locks')->tap(Locks::forUpdate());
        LockOrderRecorder::maintenanceExclusive(ReplayInvoicesCommand::class);
        DB::transaction(function (): void {
            DB::table('billing_maintenance_locks')->tap(Locks::forShare());
        });
        $this->assertSame([ReplayInvoicesCommand::class], LockOrderRecorder::maintenanceExemptions());
        $this->assertSame([LockMode::Exclusive, LockMode::Share], array_map(static fn ($lock) => $lock->mode, LockOrderRecorder::acquisitions()[0]));
        $this->assertSame([LockResource::BillingMaintenance, LockResource::BillingMaintenance], LockOrderRecorder::sequences()[0]);
        foreach (LockOrderRecorder::acquisitions()[0] as $lock) {
            $this->assertSame(__FILE__, $lock->file);
            $this->assertGreaterThan(0, $lock->line);
        }
        DB::rollBack();
        DB::transaction(static function (): void {
            DB::table('billing_maintenance_locks')->tap(Locks::forShare());
        });
        $this->assertSame([ReplayInvoicesCommand::class, null], LockOrderRecorder::maintenanceExemptions());
        $this->assertSame(LockMode::Share, LockOrderRecorder::acquisitions()[1][0]->mode);
        LockOrderRecorder::start();
        $this->assertSame([], LockOrderRecorder::maintenanceExemptions());
        LockOrderRecorder::stop();
        LockOrderRecorder::maintenanceExclusive(ReplayInvoicesCommand::class);
        $this->assertSame([], LockOrderRecorder::acquisitions());
        $this->assertSame([], LockOrderRecorder::maintenanceExemptions());
    }

    public function test_direct_records_and_internal_callbacks_use_the_unknown_source_marker(): void
    {
        LockOrderRecorder::start();
        LockOrderRecorder::record(LockResource::ClientInvoice);
        foreach (['forUpdate', 'forShare'] as $mode) {
            $callback = array_map([Locks::class, $mode], [null])[0];
            DB::table('billing_maintenance_locks')->tap($callback);
        }
        foreach (LockOrderRecorder::acquisitions() as $sequence) {
            $this->assertSame('', $sequence[0]->file);
            $this->assertSame(0, $sequence[0]->line);
        }
        $this->assertSame([LockMode::Exclusive, LockMode::Exclusive, LockMode::Share], array_map(static fn ($sequence) => $sequence[0]->mode, LockOrderRecorder::acquisitions()));
        $this->assertSame([null, null, null], LockOrderRecorder::maintenanceExemptions());
    }

    public function test_exemption_is_assigned_only_after_the_maintenance_row_is_acquired(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.maintenance_unit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('maintenance_unit');
        try {
            DB::statement('CREATE TABLE billing_maintenance_locks (workspace_id INTEGER PRIMARY KEY)');
            DB::table('billing_maintenance_locks')->insert(['workspace_id' => 7]);
            LockOrderRecorder::start();
            DB::beginTransaction();
            BillingMaintenance::exclusive(7, ReplayInvoicesCommand::class);
            $this->assertSame([ReplayInvoicesCommand::class], LockOrderRecorder::maintenanceExemptions());
            DB::rollBack();
            DB::beginTransaction();
            try {
                BillingMaintenance::exclusive(99, ReplayInvoicesCommand::class);
                $this->fail('A missing workspace lock was accepted.');
            } catch (\LogicException $exception) {
                $this->assertSame('The workspace billing maintenance row is missing.', $exception->getMessage());
            }
            $this->assertSame([ReplayInvoicesCommand::class, null], LockOrderRecorder::maintenanceExemptions());
            DB::rollBack();
        } finally {
            LockOrderRecorder::stop();
            DB::purge('maintenance_unit');
            DB::setDefaultConnection($original);
        }
    }
}
