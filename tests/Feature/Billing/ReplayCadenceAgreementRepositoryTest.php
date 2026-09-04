<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Billing\ReplayCadenceAgreementRepository;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleProration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Pins the nullable persistence terms copied into the database-free replay DTO. */
final class ReplayCadenceAgreementRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_distinguishes_absent_zero_and_populated_replay_terms(): void
    {
        $workspace = Workspace::query()->create([
            'name' => 'Replay hydration',
            'slug' => 'replay-hydration',
        ]);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic replay client',
            'slug' => 'synthetic-replay-client',
        ]);

        $absent = $this->agreement($workspace, $company, 'Absent terms', [
            'retainer_minutes' => null,
            'retainer_amount' => null,
            'period_retainer_minutes' => null,
            'period_retainer_amount' => null,
            'catch_up_threshold_minutes' => null,
            'hourly_rate_amount' => null,
            'rollover_months' => null,
            'initial_rollover_minutes' => null,
        ]);
        $zero = $this->agreement($workspace, $company, 'Zero terms', [
            'retainer_minutes' => 0,
            'retainer_amount' => 0,
            'period_retainer_minutes' => 0,
            'period_retainer_amount' => 0,
            'catch_up_threshold_minutes' => 0,
            'hourly_rate_amount' => 0,
            'rollover_months' => 0,
            'initial_rollover_minutes' => 0,
        ]);
        $populated = $this->agreement($workspace, $company, 'Populated terms', [
            'starts_on' => '2026-01-15',
            'ends_on' => '2026-12-31',
            'retainer_minutes' => 180,
            'retainer_amount' => 45678,
            'period_retainer_minutes' => 61,
            'period_retainer_amount' => 12345,
            'catch_up_threshold_minutes' => 61,
            'hourly_rate_amount' => 9876,
            'billing_cadence' => 'quarterly',
            'first_cycle_proration' => 'full_period',
            'rollover_months' => 2,
            'initial_rollover_minutes' => 59,
        ]);

        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });
        $loaded = app(ReplayCadenceAgreementRepository::class)
            ->forWorkspaceCompanies($workspace, [$company->id]);

        $this->assertSame(1, $queryCount);
        $byId = collect($loaded[$company->id])->keyBy('agreementId');

        $absentDto = $byId->get($absent->id);
        $this->assertNotNull($absentDto);
        $this->assertNull($absentDto->endsOn);
        $this->assertSame(0.0, $absentDto->monthlyHours);
        $this->assertSame(0.0, $absentDto->monthlyFee);
        $this->assertNull($absentDto->periodHoursOverride);
        $this->assertNull($absentDto->periodFeeOverride);
        $this->assertSame(0, $absentDto->hourlyRateAmount);
        $this->assertSame(0, $absentDto->catchUpThresholdMinutes);
        $this->assertSame(0, $absentDto->rolloverMonths);
        $this->assertSame(0, $absentDto->initialRolloverMinutes);
        $this->assertFalse($absentDto->usesPeriodRetainerTerms);

        $zeroDto = $byId->get($zero->id);
        $this->assertNotNull($zeroDto);
        $this->assertSame(0.0, $zeroDto->periodHoursOverride);
        $this->assertSame(0.0, $zeroDto->periodFeeOverride);
        $this->assertSame(0, $zeroDto->hourlyRateAmount);
        $this->assertSame(0, $zeroDto->catchUpThresholdMinutes);
        $this->assertSame(0, $zeroDto->rolloverMonths);
        $this->assertSame(0, $zeroDto->initialRolloverMinutes);
        $this->assertTrue($zeroDto->usesPeriodRetainerTerms);

        $populatedDto = $byId->get($populated->id);
        $this->assertNotNull($populatedDto);
        $this->assertSame('2026-01-15', $populatedDto->startsOn->toDateString());
        $this->assertSame('2026-12-31', $populatedDto->endsOn?->toDateString());
        $this->assertSame(BillingCadence::Quarterly, $populatedDto->cadence);
        $this->assertSame(FirstCycleProration::FullPeriod, $populatedDto->firstCycleProration);
        $this->assertSame(3.0, $populatedDto->monthlyHours);
        $this->assertSame(456.78, $populatedDto->monthlyFee);
        $this->assertSame(61 / 60, $populatedDto->periodHoursOverride);
        $this->assertSame(123.45, $populatedDto->periodFeeOverride);
        $this->assertSame(9876, $populatedDto->hourlyRateAmount);
        $this->assertSame(61, $populatedDto->catchUpThresholdMinutes);
        $this->assertSame(2, $populatedDto->rolloverMonths);
        $this->assertSame(59, $populatedDto->initialRolloverMinutes);
        $this->assertTrue($populatedDto->usesPeriodRetainerTerms);
        $this->assertSame(0.3389, $populatedDto->retainerHoursPerMonth());
    }

    /** @param array<string, mixed> $terms */
    private function agreement(
        Workspace $workspace,
        ClientCompany $company,
        string $title,
        array $terms,
    ): ClientAgreement {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => $title,
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly',
            ...$terms,
        ]);
    }
}
