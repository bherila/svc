<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Services\Billing\AgreementSelector;
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
