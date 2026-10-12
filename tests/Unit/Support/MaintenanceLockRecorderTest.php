<?php

namespace Tests\Unit\Support;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
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
}
