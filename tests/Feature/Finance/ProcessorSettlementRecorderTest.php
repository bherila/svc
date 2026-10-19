<?php

namespace Tests\Feature\Finance;

use App\Models\ProcessorPayout;
use App\Models\Workspace;
use App\Services\Finance\ProcessorSettlementRecorder;
use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutRecordOutcome;
use App\Support\Finance\PayoutStatus;
use App\Support\Finance\PayoutType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What recording a payout report answers (S7): a change, a current report
 * that changes nothing, or a stale one - which the callers use to decide
 * whether the report's member payments may be written.
 */
final class ProcessorSettlementRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_report_is_answered_as_recorded_unchanged_or_stale(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Recorder', 'slug' => 'recorder']);
        $recorder = app(ProcessorSettlementRecorder::class);

        $this->assertSame(PayoutRecordOutcome::Recorded, $recorder->recordPayout($workspace, $this->facts(PayoutStatus::Paid, 1000), apply: false));
        $this->assertSame(0, ProcessorPayout::query()->count());
        $this->assertSame(PayoutRecordOutcome::Recorded, $recorder->recordPayout($workspace, $this->facts(PayoutStatus::Paid, 1000)));
        // The same report again: current, nothing to write.
        $this->assertSame(PayoutRecordOutcome::Unchanged, $recorder->recordPayout($workspace, $this->facts(PayoutStatus::Paid, 1000)));
        $this->assertSame(PayoutRecordOutcome::Stale, $recorder->recordPayout($workspace, $this->facts(PayoutStatus::Failed, 999)));
        $this->assertSame(PayoutRecordOutcome::Recorded, $recorder->recordPayout($workspace, $this->facts(PayoutStatus::Failed, 3000), apply: false));
        $this->assertSame(PayoutStatus::Paid->value, ProcessorPayout::query()->sole()->status);

        $this->assertTrue(PayoutRecordOutcome::Recorded->changed() && PayoutRecordOutcome::Recorded->current());
        $this->assertTrue(! PayoutRecordOutcome::Unchanged->changed() && PayoutRecordOutcome::Unchanged->current());
        $this->assertTrue(! PayoutRecordOutcome::Stale->changed() && ! PayoutRecordOutcome::Stale->current());
    }

    public function test_a_newer_report_that_changes_nothing_moves_the_report_time_but_not_the_version(): void
    {
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Report time', 'slug' => 'report-time']);
        $recorder = app(ProcessorSettlementRecorder::class);
        $recorder->recordPayout($workspace, $this->facts(PayoutStatus::Paid, 1000));
        $stored = ProcessorPayout::query()->sole();

        CarbonImmutable::setTestNow('2026-10-20 13:00:00');
        $this->assertSame(PayoutRecordOutcome::Unchanged, $recorder->recordPayout($workspace, $this->facts(PayoutStatus::Paid, 2000), apply: false));
        $this->assertSame(1000, $stored->fresh()?->provider_updated_at_ms);
        $this->assertSame(PayoutRecordOutcome::Unchanged, $recorder->recordPayout($workspace, $this->facts(PayoutStatus::Paid, 2000)));

        $fresh = $stored->fresh();
        $this->assertSame(2000, $fresh?->provider_updated_at_ms);
        // What a change feed follows has not moved.
        $this->assertSame([1, '2026-10-20T12:00:00+00:00'], [$fresh?->lock_version, $fresh?->updated_at?->toIso8601String()]);
        // So a report between the two is now recognised as older.
        $this->assertSame(PayoutRecordOutcome::Stale, $recorder->recordPayout($workspace, $this->facts(PayoutStatus::Failed, 1500)));
        $this->assertSame(PayoutStatus::Paid->value, $stored->fresh()?->status);
    }

    private function facts(PayoutStatus $status, int $observedAtMs): PayoutFacts
    {
        return new PayoutFacts('stripe', 'po_synthetic_recorder', 941, 'USD', '2026-10-21', PayoutType::Automatic, $status, $observedAtMs);
    }
}
