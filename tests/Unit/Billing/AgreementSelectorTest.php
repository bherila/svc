<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Services\Billing\AgreementSelector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgreementSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successor_must_cover_the_same_project_scope(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Successors', 'slug' => 'successors']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Successor Client',
            'slug' => 'successor-client',
        ]);
        $firstProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'First Project',
        ]);
        $secondProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Second Project',
        ]);

        $outgoing = $this->agreement($company, $firstProject, '2026-01-01', 'Outgoing');
        $otherProjectsAgreement = $this->agreement($company, $secondProject, '2026-03-01', 'Concurrent');
        $actualSuccessor = $this->agreement($company, $firstProject, '2026-06-01', 'Replacement');

        $successor = (new AgreementSelector)->successorAgreementForGeneration(
            collect([$outgoing, $otherProjectsAgreement, $actualSuccessor]),
            $outgoing,
        );

        $this->assertSame($actualSuccessor->id, $successor?->id);
    }

    public function test_month_end_selection_includes_only_agreements_starting_by_the_next_calendar_month(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Month end selection', 'slug' => 'month-end-selection']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Month End Client',
            'slug' => 'month-end-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Month End Project',
        ]);
        $current = $this->agreement($company, $project, '2026-01-01', 'Current');
        $september = $this->agreement($company, $project, '2026-09-30', 'September boundary');
        $october = $this->agreement($company, $project, '2026-10-01', 'October is too far');
        $this->travelTo(Carbon::parse('2026-08-31 12:00:00'));

        $selectedIds = (new AgreementSelector)
            ->agreementsForInvoiceGeneration($company)
            ->pluck('id')
            ->all();

        $this->assertSame([$current->id, $september->id], $selectedIds);
        $this->assertNotContains($october->id, $selectedIds);
    }

    private function agreement(
        ClientCompany $company,
        ClientProject $project,
        string $startsOn,
        string $title,
    ): ClientAgreement {
        return ClientAgreement::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => $title,
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => $startsOn,
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
        ]);
    }
}
