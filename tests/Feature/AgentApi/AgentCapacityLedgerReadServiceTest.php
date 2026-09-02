<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AgentApi\AgentCapacityLedgerReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class AgentCapacityLedgerReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_canonical_ledger_and_bounds_the_returned_months(): void
    {
        Date::setTestNow('2026-09-15 12:00:00');
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Ledger workspace', 'slug' => 'ledger-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Ledger client', 'slug' => 'ledger-client']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Ledger agreement',
            'status' => 'active',
            'starts_on' => '2026-07-01',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 600,
        ]);

        $result = app(AgentCapacityLedgerReadService::class)->get(
            AgentPrincipal::query()->findOrFail($user->id),
            $workspace,
            $agreement->public_id,
            2,
        );

        $this->assertSame($agreement->public_id, $result['agreement_id']);
        $this->assertSame('2026-09-15', $result['through']);
        $this->assertCount(2, $result['months']);
        $this->assertSame('2026-08', $result['months'][0]['period']);
        $this->assertSame(10.0, $result['months'][0]['retainer_hours']);
    }
}
