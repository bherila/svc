<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Support\Billing\BillingCadence;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceLedgerBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_agreement_ledger_through_summarizes_monthly_entries(): void
    {
        $company = $this->company();
        $project = $this->project($company);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'termination_date' => null,
            'monthly_retainer_hours' => 10,
            'rollover_months' => 0,
            'initial_rollover_hours' => 0,
            'retainer_hours' => null,
        ]);

        $this->entry($company, $project, [
            'date_worked' => '2026-01-15',
            'minutes_worked' => 120,
            'is_billable' => true,
        ]);
        $this->entry($company, $project, [
            'date_worked' => '2026-01-20',
            'minutes_worked' => 60,
            'is_billable' => false,
        ]);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );

        $this->assertCount(1, $ledger);
        $this->assertSame('2026-01', $ledger[0]->yearMonth);
        $this->assertSame(2.0, $ledger[0]->hoursWorked);
        $this->assertSame(10.0, $ledger[0]->retainerHours);
        $this->assertSame(8.0, $ledger[0]->closing->unusedHours);
    }

    public function test_the_ledger_refuses_time_whose_project_belongs_to_another_company(): void
    {
        $company = $this->company();
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $company->workspace_id,
            'name' => 'Other Ledger Client',
            'slug' => 'other-ledger-client-'.uniqid(),
        ]);
        $otherProject = $this->project($otherCompany);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
        ]);

        $this->entry($company, $otherProject, [
            'date_worked' => '2026-01-15',
            'minutes_worked' => 120,
            'is_billable' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('project outside this client company');

        (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );
    }

    public function test_the_ledger_refuses_time_whose_project_belongs_to_another_workspace(): void
    {
        $company = $this->company();
        $otherCompany = $this->company();
        $otherProject = $this->project($otherCompany);
        $agreement = $this->agreement($company, [
            'active_date' => '2026-01-01',
            'monthly_retainer_hours' => 10,
        ]);

        $this->entry($company, $otherProject, [
            'date_worked' => '2026-01-15',
            'minutes_worked' => 120,
            'is_billable' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('project outside this client company');

        (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $company,
            $agreement,
            Carbon::parse('2026-01-31'),
        );
    }

    public function test_summarize_legacy_monthly_ledger_counts_mid_month_boundary_once(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2024-02-15',
            'termination_date' => null,
            'monthly_retainer_hours' => 10,
            'rollover_months' => 0,
            'initial_rollover_hours' => 0,
            'retainer_hours' => null,
            'billing_cadence' => BillingCadence::Quarterly->value,
        ]);

        $cycles = iterator_to_array((new BillingCycleResolver)->cyclesForAgreement(
            $agreement,
            Carbon::parse('2024-08-14'),
        ));
        $ledger = [
            $this->summary('2024-02', hoursWorked: 1.0, retainerHours: 10.0),
            $this->summary('2024-03', hoursWorked: 2.0, retainerHours: 10.0),
            $this->summary('2024-04', hoursWorked: 3.0, retainerHours: 10.0),
            $this->summary('2024-05', hoursWorked: 4.0, retainerHours: 10.0),
            $this->summary('2024-06', hoursWorked: 5.0, retainerHours: 10.0),
            $this->summary('2024-07', hoursWorked: 6.0, retainerHours: 10.0),
            $this->summary('2024-08', hoursWorked: 7.0, retainerHours: 10.0),
        ];

        $builder = new InvoiceLedgerBuilder;
        $firstCycle = $builder->summarizeLedgerForCycle($agreement, $ledger, $cycles[0]);
        $secondCycle = $builder->summarizeLedgerForCycle($agreement, $ledger, $cycles[1]);

        $this->assertSame(70.0, $firstCycle['retainer_hours'] + $secondCycle['retainer_hours']);
        $this->assertSame(28.0, $firstCycle['hours_worked'] + $secondCycle['hours_worked']);
        $this->assertSame(30.0, $secondCycle['retainer_hours']);
        $this->assertSame(18.0, $secondCycle['hours_worked']);
    }

    public function test_summarize_legacy_monthly_ledger_moves_boundary_month_to_truncated_final_cycle(): void
    {
        $company = $this->company();
        $agreement = $this->agreement($company, [
            'active_date' => '2024-02-15',
            'termination_date' => '2024-05-20',
            'monthly_retainer_hours' => 10,
            'rollover_months' => 0,
            'initial_rollover_hours' => 0,
            'retainer_hours' => null,
            'billing_cadence' => BillingCadence::Quarterly->value,
        ]);

        $cycles = iterator_to_array((new BillingCycleResolver)->cyclesForAgreement(
            $agreement,
            Carbon::parse('2024-08-14'),
        ));
        $ledger = [
            $this->summary('2024-02', hoursWorked: 1.0, retainerHours: 10.0),
            $this->summary('2024-03', hoursWorked: 2.0, retainerHours: 10.0),
            $this->summary('2024-04', hoursWorked: 3.0, retainerHours: 10.0),
            $this->summary('2024-05', hoursWorked: 4.0, retainerHours: 10.0),
        ];

        $builder = new InvoiceLedgerBuilder;
        $firstCycle = $builder->summarizeLedgerForCycle($agreement, $ledger, $cycles[0]);
        $finalCycle = $builder->summarizeLedgerForCycle($agreement, $ledger, $cycles[1]);

        $this->assertSame('2024-05-15', $cycles[1]->start->toDateString());
        $this->assertSame('2024-05-20', $cycles[1]->end->toDateString());
        $this->assertSame(30.0, $firstCycle['retainer_hours']);
        $this->assertSame(6.0, $firstCycle['hours_worked']);
        $this->assertSame(10.0, $finalCycle['retainer_hours']);
        $this->assertSame(4.0, $finalCycle['hours_worked']);
        $this->assertSame(40.0, $firstCycle['retainer_hours'] + $finalCycle['retainer_hours']);
        $this->assertSame(10.0, $firstCycle['hours_worked'] + $finalCycle['hours_worked']);
    }

    public function test_ledger_row_belongs_to_cycle_through_respects_cycle_owner_and_period_end(): void
    {
        $builder = new InvoiceLedgerBuilder;
        $cycleMonthStart = Carbon::parse('2026-02-01');
        $periodMonthEnd = Carbon::parse('2026-03-01');

        $this->assertTrue($builder->ledgerRowBelongsToCycleThrough(
            $this->summary('2026-03', '2026-02-01'),
            '2026-02-01',
            $cycleMonthStart,
            $periodMonthEnd,
        ));
        $this->assertFalse($builder->ledgerRowBelongsToCycleThrough(
            $this->summary('2026-04', '2026-02-01'),
            '2026-02-01',
            $cycleMonthStart,
            $periodMonthEnd,
        ));
        $this->assertTrue($builder->ledgerRowBelongsToCycleThrough(
            $this->summary('2026-03'),
            '2026-02-01',
            $cycleMonthStart,
            $periodMonthEnd,
        ));
    }

    public function test_find_ledger_month_prefers_matching_cycle_start(): void
    {
        $first = $this->summary('2026-03', '2026-02-01');
        $second = $this->summary('2026-03', '2026-03-01');

        $builder = new InvoiceLedgerBuilder;

        $this->assertSame($second, $builder->findLedgerMonth([$first, $second], '2026-03', '2026-03-01'));
        $this->assertSame($first, $builder->findLedgerMonth([$first, $second], '2026-03'));
        $this->assertNull($builder->findLedgerMonth([$first, $second], '2026-04'));
    }

    private function summary(
        string $yearMonth,
        ?string $cycleStart = null,
        float $hoursWorked = 0.0,
        float $retainerHours = 0.0,
    ): MonthSummary {
        return new MonthSummary(
            opening: new OpeningBalance(
                retainerHours: 0.0,
                rolloverHours: 0.0,
                expiredHours: 0.0,
                totalAvailable: 0.0,
                negativeOffset: 0.0,
                invoicedNegativeBalance: 0.0,
                effectiveRetainerHours: 0.0,
                remainingNegativeBalance: 0.0,
            ),
            closing: new ClosingBalance(
                hoursUsedFromRetainer: 0.0,
                hoursUsedFromRollover: 0.0,
                unusedHours: 0.0,
                excessHours: 0.0,
                negativeBalance: 0.0,
                remainingRollover: 0.0,
            ),
            hoursWorked: $hoursWorked,
            yearMonth: $yearMonth,
            retainerHours: $retainerHours,
            cycleStart: $cycleStart,
        );
    }

    // ── Construction only ────────────────────────────────────────────────────
    // These translate the engine's vocabulary to this schema's columns and
    // units. Every assertion above is the one the predecessor shipped.

    private function company(): ClientCompany
    {
        $workspace = Workspace::query()->create([
            'name' => 'Ledger', 'slug' => 'ledger-'.uniqid(),
        ]);

        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ledger Client',
            'slug' => 'ledger-client-'.uniqid(),
        ]);
    }

    private function project(ClientCompany $company): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'name' => 'Ledger Project',
        ]);
    }

    /** @param array<string, mixed> $terms */
    private function agreement(ClientCompany $company, array $terms): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => $terms['active_date'] ?? null,
            'ends_on' => $terms['termination_date'] ?? null,
            'billing_cadence' => $terms['billing_cadence'] ?? 'monthly',
            'rollover_months' => $terms['rollover_months'] ?? 0,
            'retainer_minutes' => (int) round((float) ($terms['monthly_retainer_hours'] ?? 0) * 60),
            'initial_rollover_minutes' => (int) round((float) ($terms['initial_rollover_hours'] ?? 0) * 60),
            'period_retainer_minutes' => ($terms['retainer_hours'] ?? null) === null
                ? null
                : (int) round((float) $terms['retainer_hours'] * 60),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function entry(ClientCompany $company, ClientProject $project, array $attributes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => $attributes['date_worked'],
            'minutes' => $attributes['minutes_worked'],
            'description' => 'Ledger work',
            'is_billable' => $attributes['is_billable'] ?? true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
