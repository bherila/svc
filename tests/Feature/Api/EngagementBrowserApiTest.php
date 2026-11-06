<?php

namespace Tests\Feature\Api;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class EngagementBrowserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agreements_page_sends_versions_and_finished_manager_urls_and_withholds_them_from_viewers(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        $proposal = ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic proposal', 'status' => 'draft', 'currency' => 'USD']);
        $draft = $this->agreement($workspace, $company, 'draft');
        $active = $this->agreement($workspace, $company, 'active');
        $schedule = ClientBillingSchedule::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $active->id, 'cadence' => 'monthly', 'next_run_on' => '2026-10-01', 'due_days' => 30, 'currency' => 'USD', 'is_active' => true, 'line_template' => []]);
        $this->actingAs($owner)->get(route('clients.agreements', [$workspace, $company]))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('clients/agreements')
            ->where('company.id', $company->public_id)
            ->where('version', AgentApiVersion::for($company))
            ->where('create_proposal_href', route('agent-api.v1.proposals.store', $workspace, absolute: false))
            ->where('proposals.0.version', AgentApiVersion::for($proposal))
            ->where('proposals.0.send_href', route('agent-api.v1.proposals.send', [$workspace, $proposal->public_id], absolute: false))
            ->where('agreements', function ($agreements) use ($draft, $workspace): bool {
                $data = collect($agreements)->keyBy('id');

                return $data[$draft->public_id]['activate_href'] === route('agent-api.v1.agreements.activate', [$workspace, $draft->public_id], absolute: false)
                    && collect($agreements)->every(fn (array $agreement): bool => $agreement['create_schedule_href'] === null);
            })
            ->where('billing_schedules.0.agreement_id', $active->public_id)
            ->where('billing_schedules.0.generate_href', route('agent-api.v1.billing-schedules.generate', [$workspace, $schedule->public_id], absolute: false)));
        $viewer = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'viewer']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic reachable project', 'slug' => 'synthetic-reachable-project']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'contributor']);
        $this->actingAs($viewer)->get(route('clients.agreements', [$workspace, $company]))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->where('create_proposal_href', null)
            ->where('proposals.0.send_href', null)
            ->where('agreements', fn ($agreements): bool => collect($agreements)->every(fn (array $agreement): bool => $agreement['activate_href'] === null && $agreement['create_schedule_href'] === null))
            ->where('billing_schedules.0.generate_href', null));
    }

    public function test_agreements_page_is_scoped_to_its_workspace_and_to_the_clients_a_member_reaches(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        [, $other, $otherCompany] = $this->tenant();
        ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic own proposal', 'status' => 'draft', 'currency' => 'USD']);
        ClientProposal::query()->create(['workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'title' => 'Synthetic foreign proposal', 'status' => 'draft', 'currency' => 'USD']);
        $this->agreement($other, $otherCompany, 'draft');
        $this->actingAs($owner)->get(route('clients.agreements', [$workspace, $company]))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->has('proposals', 1)
            ->where('proposals.0.title', 'Synthetic own proposal')
            ->has('agreements', 0)
            ->has('billing_schedules', 0));
        // Another workspace's company is not reachable through this workspace's URL.
        $this->get(route('clients.agreements', [$workspace, $otherCompany]))->assertNotFound();
        // A member with no project of this client reaches nothing of it.
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAs($member)->get(route('clients.agreements', [$workspace, $company]))->assertNotFound();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('clients.agreements', [$workspace, $company]))->assertForbidden();
    }

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
