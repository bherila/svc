<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AgentApi\AgentAgreementReadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgentAgreementReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_scopes_agreement_lookup_and_derived_terms_to_a_manager_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Agreement workspace', 'slug' => 'agreement-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Agreement client', 'slug' => 'agreement-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Agreement project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'Monthly support',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 120,
            'retainer_amount' => 20000,
        ]);
        $other = Workspace::query()->create(['name' => 'Other workspace', 'slug' => 'other-agreement-workspace']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Other client', 'slug' => 'other-agreement-client']);
        $foreign = ClientAgreement::query()->create([
            'workspace_id' => $other->id,
            'client_company_id' => $otherCompany->id,
            'title' => 'Foreign agreement',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'currency' => 'USD',
        ]);

        $principal = AgentPrincipal::query()->findOrFail($user->id);
        $service = app(AgentAgreementReadService::class);

        $result = $service->get($principal, $workspace, $agreement->public_id);
        $this->assertSame('Monthly support', $result['title']);
        $this->assertSame(120, $result['retainer_minutes_per_period']);
        $this->assertSame('Agreement project', $result['project']);

        $this->expectException(ModelNotFoundException::class);
        $service->get($principal, $workspace, $foreign->public_id);
    }
}
