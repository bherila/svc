<?php

namespace Tests\Feature\Api;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EngagementBrowserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_schedule_creation_is_versioned_and_replayable_with_agent_writes_disabled(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        $agreement = $this->agreement($workspace, $company, 'active');
        config(['agent_api.writes_enabled' => false, 'agent_api.invoice_writes_enabled' => false]);
        $this->actingAs($owner)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str()->random(40))->withHeader('Idempotency-Key', 'synthetic-schedule');
        $url = route('agent-api.v1.billing-schedules.store', $workspace, absolute: false);
        $body = ['company_id' => $company->public_id, 'client_agreement' => $agreement->public_id, 'expected_version' => AgentApiVersion::for($agreement), 'cadence' => 'monthly', 'next_run_on' => '2026-10-01', 'due_days' => 30, 'currency' => 'USD', 'line_template' => [['type' => 'service', 'description' => 'Synthetic recurring work', 'quantity' => '1', 'unit_amount' => 10000]]];
        $response = $this->postJson($url, $body)->assertCreated();
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertDatabaseCount('client_billing_schedules', 1);
        $agreement->forceFill(['title' => 'Synthetic corrected terms'])->save();
        $this->withHeader('Idempotency-Key', 'synthetic-stale')->postJson($url, $body)->assertConflict();
        $this->assertDatabaseCount('client_billing_schedules', 1);
    }

    public function test_schedule_creation_cannot_reference_another_workspaces_agreement(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        [, $other, $otherCompany] = $this->tenant();
        $agreement = $this->agreement($other, $otherCompany, 'active');
        $this->actingAs($owner)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str()->random(40))->withHeader('Idempotency-Key', 'synthetic-cross-tenant')
            ->postJson(route('agent-api.v1.billing-schedules.store', $workspace), ['company_id' => $company->public_id, 'client_agreement' => $agreement->public_id, 'expected_version' => AgentApiVersion::for($agreement), 'cadence' => 'monthly', 'next_run_on' => '2026-10-01', 'due_days' => 30, 'currency' => 'USD', 'line_template' => [['type' => 'service', 'description' => 'Synthetic forbidden work', 'quantity' => '1', 'unit_amount' => 10000]]])->assertNotFound();
        $this->assertDatabaseCount('client_billing_schedules', 0);
    }

    /** @return array{User, Workspace, ClientCompany} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Engagement', 'slug' => 'synthetic-engagement-'.str()->random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Engagement Client', 'slug' => 'synthetic-engagement-client-'.str()->random(8)]);

        return [$owner, $workspace, $company];
    }

    private function agreement(Workspace $workspace, ClientCompany $company, string $status): ClientAgreement
    {
        return ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic '.$status.' agreement', 'status' => $status, 'currency' => 'USD', 'starts_on' => '2026-10-01', 'billing_cadence' => 'monthly']);
    }
}
