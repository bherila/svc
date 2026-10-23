<?php

namespace Tests\Feature\Finance;

use App\Models\Workspace;
use App\Services\Square\SquarePendingSettlements;
use App\Support\Finance\PaymentSettlement;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesSquare;
use Tests\TestCase;

final class SquarePendingSettlementPruneTest extends TestCase
{
    use FakesSquare;
    use RefreshDatabase;

    public function test_pruning_is_tenant_scoped_bounded_and_keeps_the_retention_boundary(): void
    {
        Date::setTestNow('2030-03-05 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Synthetic prune workspace', 'slug' => 'synthetic-prune']);
        $other = Workspace::query()->create(['name' => 'Synthetic other prune workspace', 'slug' => 'synthetic-other-prune']);
        $this->connectSquare($workspace);
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = $this->entry($workspace, 'pay_fixture_expired_'.$i, '2030-02-01 12:00:00');
        }
        DB::table('square_pending_settlements')->insert([...$rows,
            $this->entry($workspace, 'pay_fixture_boundary', '2030-02-03 12:00:00'),
            $this->entry($workspace, 'pay_fixture_recent', '2030-03-04 12:00:00'),
            $this->entry($other, 'pay_fixture_foreign', '2030-02-01 12:00:00'),
        ]);

        $this->artisan('svc:square:prune-pending-settlements', ['--apply' => true])->assertSuccessful();

        $this->assertSame(4, DB::table('square_pending_settlements')->count(), 'Only 1,000 entries are pruned per invocation.');
        $this->artisan('svc:square:prune-pending-settlements', ['--apply' => true])->assertSuccessful();
        $this->assertSame(['pay_fixture_boundary', 'pay_fixture_recent', 'pay_fixture_foreign'], DB::table('square_pending_settlements')->orderBy('id')->pluck('payment_identifier')->all());
        $this->artisan('svc:square:prune-pending-settlements', ['--apply' => true])->assertSuccessful();
        $this->assertSame(3, DB::table('square_pending_settlements')->count());
    }

    public function test_default_preview_keeps_entries_and_reports_the_same_bounded_prune(): void
    {
        Date::setTestNow('2030-03-05 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Synthetic preview', 'slug' => 'synthetic-preview']);
        $this->connectSquare($workspace);
        DB::table('square_pending_settlements')->insert($this->entry($workspace, 'pay_fixture_preview', '2030-02-01 12:00:00'));
        $this->artisan('svc:square:prune-pending-settlements', ['--format' => 'json'])
            ->expectsOutput('{"dry_run":true,"pruned":1}')->assertSuccessful();
        $this->assertDatabaseCount('square_pending_settlements', 1);
    }

    public function test_configured_retention_is_used_and_a_newer_report_is_rechecked_before_deletion(): void
    {
        Date::setTestNow('2030-03-05 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Synthetic retention workspace', 'slug' => 'synthetic-retention']);
        $this->connectSquare($workspace, ['pending_settlement_retention_days' => 7]);
        DB::table('square_pending_settlements')->insert([
            $this->entry($workspace, 'pay_fixture_refresh', '2030-02-20 12:00:00'),
            $this->entry($workspace, 'pay_fixture_expired', '2030-02-25 12:00:00'),
            $this->entry($workspace, 'pay_fixture_current', '2030-03-01 12:00:00'),
        ]);
        $refreshed = false;
        DB::listen(function (QueryExecuted $query) use (&$refreshed, $workspace): void {
            if ($refreshed || ! str_starts_with(str_replace('`', '"', $query->sql), 'select "id" from "square_pending_settlements"')) {
                return;
            }
            $refreshed = true;
            app(SquarePendingSettlements::class)->remember($workspace, 'pay_fixture_refresh',
                PaymentSettlement::payout('po_fixture_refresh', '2030-03-06', 2000, null, 5, 'USD'));
        });

        $this->artisan('svc:square:prune-pending-settlements', ['--apply' => true])->assertSuccessful();

        $this->assertTrue($refreshed);
        $this->assertSame(['pay_fixture_refresh', 'pay_fixture_current'], DB::table('square_pending_settlements')->orderBy('id')->pluck('payment_identifier')->all());
    }

    public function test_duplicate_and_stale_reports_do_not_extend_retention_and_invalid_retention_fails_closed(): void
    {
        Date::setTestNow('2030-03-05 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Synthetic stable retention', 'slug' => 'synthetic-stable-retention']);
        $this->connectSquare($workspace);
        $pending = app(SquarePendingSettlements::class);
        $facts = PaymentSettlement::payout('po_fixture_stable', '2030-03-06', 2000, null, 5, 'USD');
        $pending->remember($workspace, 'pay_fixture_stable', $facts);
        $before = (array) DB::table('square_pending_settlements')->sole();
        Date::setTestNow('2030-03-06 12:00:00');
        $pending->remember($workspace, 'pay_fixture_stable', $facts);
        $pending->remember($workspace, 'pay_fixture_stable', PaymentSettlement::payout('po_fixture_older', null, 1000, null, null, null));
        $this->assertSame($before, (array) DB::table('square_pending_settlements')->sole());
        config(['services.square.pending_settlement_retention_days' => 0]);
        try {
            $pending->prune($workspace);
            $this->fail('Invalid retention must not authorize deletion.');
        } catch (\LogicException $exception) {
            $this->assertSame('Pending Square settlement retention must be at least one day.', $exception->getMessage());
        }
        $this->assertSame($before, (array) DB::table('square_pending_settlements')->sole());
    }

    /** @return array<string, mixed> */
    private function entry(Workspace $workspace, string $id, string $received): array
    {
        return ['workspace_id' => $workspace->id, 'payment_identifier' => $id, 'payout_identifier' => 'po_fixture_prune',
            'arrival_date' => null, 'fee_amount' => null, 'fee_currency' => null, 'reported_at_ms' => 1000, 'received_at' => $received];
    }
}
