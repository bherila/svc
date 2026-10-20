<?php

namespace Tests\Feature\Finance;

use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquarePendingSettlements;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FakesSquarePaymentSync;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/** The CI MariaDB lane exercises a current read through an older repeatable-read snapshot. */
final class SquarePendingSettlementSnapshotTest extends TestCase
{
    use FakesSquarePaymentSync;
    use UsesAProbeDatabase;

    public function test_delivery_reads_a_pending_entry_committed_after_the_outer_snapshot(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A repeatable-read snapshot requires the MariaDB CI lane.');
        }
        $this->bootProbeDatabase('square_pending_probe');
        Artisan::call('migrate', ['--database' => 'square_pending_probe', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('square_pending_probe');
        Schema::clearResolvedInstance('db.schema');
        config(['database.connections.square_pending_rival' => config('database.connections.square_pending_probe')]);
        try {
            [$workspace, , , $invoice] = $this->paymentFixture();
            app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
            $connection = DB::connection();
            $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $connection->beginTransaction();
            $this->assertSame(0, DB::table('square_pending_settlements')->count());
            DB::connection('square_pending_rival')->table('square_pending_settlements')->insert([
                'workspace_id' => $workspace->id, 'payment_identifier' => 'pay_fixture_1',
                'payout_identifier' => 'po_fixture_late', 'arrival_date' => '2030-02-03',
                'fee_amount' => 390, 'fee_currency' => 'USD', 'reported_at_ms' => 2000,
                'received_at' => '2030-02-02 12:00:00',
            ]);
            $this->assertSame(0, DB::table('square_pending_settlements')->count(), 'An ordinary read still sees the old snapshot.');

            $this->assertTrue(app(SquarePendingSettlements::class)->deliver($workspace, 'pay_fixture_1'));

            $payment = $invoice->payments()->sole();
            $this->assertSame(['po_fixture_late', '2030-02-03', 390], [$payment->provider_payout_identifier, $payment->provider_payout_arrival_date?->toDateString(), $payment->provider_fee_amount]);
            $this->assertSame(0, DB::table('square_pending_settlements')->count());
        } finally {
            while (DB::connection('square_pending_probe')->transactionLevel() > 0) {
                DB::connection('square_pending_probe')->rollBack();
            }
            DB::purge('square_pending_rival');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
