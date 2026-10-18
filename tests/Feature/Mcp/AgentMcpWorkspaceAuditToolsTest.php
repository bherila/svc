<?php

namespace Tests\Feature\Mcp;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ScheduleGenerationPreflight;
use App\Services\Engagement\UnlinkedProposalAgreementAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * The schedule-generation preflight and the unlinked-proposal audit over MCP
 * (#458): the same services the operator commands run for every workspace,
 * scoped to the caller's workspace and its managers.
 */
final class AgentMcpWorkspaceAuditToolsTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    public function test_the_tools_report_the_callers_workspace_alone(): void
    {
        $this->travelTo('2026-10-15 12:00:00');
        [$mine, $owner] = $this->workspace('mine');
        [$theirs] = $this->workspace('theirs');
        $this->actingAsMcp($owner, ['mcp:use', 'billing:read']);
        $session = $this->initialize();

        $preflight = $this->callTool($session, 'billing.preflight_schedule_generation', ['workspace_id' => $mine->public_id]);
        $this->assertFalse($preflight['result']['isError'] ?? false, (string) json_encode($preflight));
        $this->assertSame(['data' => app(ScheduleGenerationPreflight::class)->run($mine)->toArray()], $preflight['result']['structuredContent']);
        $this->assertSame([1, 1, 3, true, 0], [
            $preflight['result']['structuredContent']['data']['schedules'], $preflight['result']['structuredContent']['data']['schedules_due'],
            $preflight['result']['structuredContent']['data']['periods_classified'], $preflight['result']['structuredContent']['data']['complete'],
            $preflight['result']['structuredContent']['data']['would_halt'],
        ]);

        $unlinked = $this->callTool($session, 'billing.audit_unlinked_proposal_agreements', ['workspace_id' => $mine->public_id]);
        $this->assertSame(['data' => [
            'proposals' => 1, 'sent_without_a_linked_agreement' => 1, 'with_an_unlinked_agreement_on_the_company' => 1,
            'with_an_active_unlinked_agreement' => 1, 'accepted_without_a_linked_agreement' => 0, 'unlinked_agreements' => 1,
        ]], $unlinked['result']['structuredContent']);
        $this->assertSame(2, app(UnlinkedProposalAgreementAuditor::class)->count()->proposals, 'The other workspace holds the same shape.');

        // Not this caller's workspace, and not for a member.
        foreach (['billing.preflight_schedule_generation', 'billing.audit_unlinked_proposal_agreements'] as $tool) {
            $foreign = $this->callTool($session, $tool, ['workspace_id' => $theirs->public_id]);
            $this->assertTrue(isset($foreign['error']) || ($foreign['result']['isError'] ?? false), (string) json_encode($foreign));
        }
        $member = User::factory()->create();
        $mine->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['mcp:use', 'billing:read']);
        $this->assertNotContains('billing.preflight_schedule_generation', $this->toolNames($this->initialize()));
    }

    /** @return array{Workspace, User} */
    private function workspace(string $slug): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$slug, 'slug' => 'synthetic-audit-'.$slug]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic '.$slug.' client', 'slug' => 'synthetic-'.$slug.'-client']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01', 'retainer_minutes' => 600]);
        ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [['type' => 'service', 'description' => 'Retainer', 'quantity' => '1', 'unit_amount' => 100000, 'tax_amount' => 0, 'sort_order' => 1]],
        ]);
        $proposal = ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Proposal', 'currency' => 'USD', 'status' => 'draft']);
        $proposal->forceFill(['status' => 'sent'])->save();

        return [$workspace, $owner];
    }
}
