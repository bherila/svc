<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ProcessorPayout;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\ProcessorSettlementRecorder;
use App\Support\Finance\PaymentSettlement;
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

    public function test_a_payments_payout_facts_follow_only_the_newest_report_of_them(): void
    {
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Members', 'slug' => 'members']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Members client', 'slug' => 'members-client']);
        $invoices = app(InvoiceLifecycleService::class);
        $invoice = $invoices->createDraft($workspace, $company, ['invoice_number' => 'INV-MEMBERS', 'currency' => 'USD'],
            [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $invoices->issue($invoice, $workspace);
        $payment = $invoices->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'succeeded',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_members'], $workspace);
        $recorder = app(ProcessorSettlementRecorder::class);
        $settle = static fn (string $arrival, int $at): PaymentSettlement => PaymentSettlement::payout('po_synthetic_members', $arrival, $at, null, 59, 'USD');

        $this->assertTrue($recorder->recordPaymentSettlement($workspace, 'stripe', ['pi_synthetic_members'], $settle('2026-10-21', 2000)));
        $settled = $payment->fresh();
        // Older: refused, under the payment's lock, without a write.
        $this->assertFalse($recorder->recordPaymentSettlement($workspace, 'stripe', ['pi_synthetic_members'], $settle('2026-10-20', 1000)));
        $this->assertSame(['2026-10-21', 2000, $settled?->lock_version], [$payment->fresh()?->provider_payout_arrival_date?->toDateString(), $payment->fresh()?->provider_payout_reported_at_ms, $payment->fresh()?->lock_version]);
        // Newer with nothing new: the report time moves, the revision does not.
        CarbonImmutable::setTestNow('2026-10-20 13:00:00');
        $this->assertFalse($recorder->recordPaymentSettlement($workspace, 'stripe', ['pi_synthetic_members'], $settle('2026-10-21', 3000)));
        $this->assertSame([3000, $settled?->lock_version, $settled?->updated_at?->toIso8601String()],
            [$payment->fresh()?->provider_payout_reported_at_ms, $payment->fresh()?->lock_version, $payment->fresh()?->updated_at?->toIso8601String()]);
        // So a report between the two is now older too.
        $this->assertFalse($recorder->recordPaymentSettlement($workspace, 'stripe', ['pi_synthetic_members'], $settle('2026-10-25', 2500)));
        $this->assertSame('2026-10-21', $payment->fresh()?->provider_payout_arrival_date?->toDateString());
        // A charge-time report carries no payout time and is not held to one.
        $this->assertTrue($recorder->recordPaymentSettlement($workspace, 'stripe', ['pi_synthetic_members'], PaymentSettlement::charge(CarbonImmutable::parse('2026-10-18 09:00:00'), 70, 'USD')));
        $this->assertSame([59, 3000], [$payment->fresh()?->provider_fee_amount, $payment->fresh()?->provider_payout_reported_at_ms]);
        // A newer report moves the facts on.
        $this->assertTrue($recorder->recordPaymentSettlement($workspace, 'stripe', ['pi_synthetic_members'], $settle('2026-10-24', 4000)));
        $this->assertSame(['2026-10-24', 4000], [$payment->fresh()?->provider_payout_arrival_date?->toDateString(), $payment->fresh()?->provider_payout_reported_at_ms]);
    }

    private function facts(PayoutStatus $status, int $observedAtMs): PayoutFacts
    {
        return new PayoutFacts('stripe', 'po_synthetic_recorder', 941, 'USD', '2026-10-21', PayoutType::Automatic, $status, $observedAtMs);
    }
}
