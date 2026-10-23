<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentBillingAuditReadService;
use App\Services\Billing\MissingBilledOverageAuditor;
use App\Services\Billing\OpeningRolloverAuditor;
use App\Services\Billing\ScheduleGenerationPreflight;
use App\Services\Billing\UndatedCollectibleInvoiceAuditor;
use App\Services\Billing\UnplaceableInvoiceAuditor;
use App\Services\Engagement\UnlinkedProposalAgreementAuditor;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * The aggregate billing audits over REST (#459), which their MCP tools reach
 * through the same routes: the caller's workspace alone, for its managers,
 * under `billing:read`, with the preflight's budget applied to every caller.
 */
final class BillingAuditRestTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'billing:read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 12:00:00');
    }

    /** @return iterable<string, array{string, string, Closure(Workspace): array<string, mixed>}> */
    public static function audits(): iterable
    {
        yield 'unplaceable invoices' => ['billing.audit_unplaceable_invoices', 'unplaceable-invoices', static fn (Workspace $workspace): array => app(UnplaceableInvoiceAuditor::class)->count($workspace)->toArray()];
        yield 'undated collectible invoices' => ['billing.audit_undated_collectible_invoices', 'undated-collectible-invoices', static fn (Workspace $workspace): array => app(UndatedCollectibleInvoiceAuditor::class)->count($workspace)->toArray()];
        yield 'missing billed overage' => ['billing.audit_missing_billed_overage', 'missing-billed-overage', static fn (Workspace $workspace): array => app(MissingBilledOverageAuditor::class)->count($workspace)->toArray()];
        yield 'opening rollover' => ['billing.audit_opening_rollover', 'opening-rollover', static fn (Workspace $workspace): array => app(OpeningRolloverAuditor::class)->count($workspace)->toArray()];
        yield 'schedule generation preflight' => ['billing.preflight_schedule_generation', 'schedule-generation-preflight', static fn (Workspace $workspace): array => app(ScheduleGenerationPreflight::class)->run($workspace, periodCap: AgentBillingAuditReadService::PREFLIGHT_PERIOD_CAP)->toArray()];
        yield 'unlinked proposal agreements' => ['billing.audit_unlinked_proposal_agreements', 'unlinked-proposal-agreements', static fn (Workspace $workspace): array => app(UnlinkedProposalAgreementAuditor::class)->count($workspace)->toArray()];
    }

    /** @param Closure(Workspace): array<string, mixed> $expected */
    #[DataProvider('audits')]
    public function test_each_audit_reports_the_callers_workspace_alone_over_rest_and_mcp(string $operation, string $segment, Closure $expected): void
    {
        [$mine, $owner] = $this->workspace('mine');
        [$theirs, $theirOwner] = $this->workspace('theirs');
        $this->actingAsMcp($owner, self::SCOPES);

        $response = $this->getJson($this->url($mine, $segment))->assertOk();
        $rest = $response->json();
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema(json_decode((string) $response->getContent()), AgentApiResponseSchemaCatalog::forOperation($operation)), $operation);
        // As JSON carries it: a float with no fraction arrives as an integer.
        $wire = static fn (Workspace $workspace): array => json_decode((string) json_encode(['data' => $expected($workspace)]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($wire($mine), $rest);
        $mcp = $this->callTool($this->initialize(), $operation, ['workspace_id' => $mine->public_id]);
        $this->assertFalse($mcp['result']['isError'] ?? false, (string) json_encode($mcp));
        $this->assertSame($rest, $mcp['result']['structuredContent']);

        // Another tenant's workspace is not found, whatever the caller's role there.
        $this->getJson($this->url($theirs, $segment))->assertNotFound();
        $this->actingAsMcp($theirOwner, self::SCOPES);
        $this->assertSame($wire($theirs), $this->getJson($this->url($theirs, $segment))->assertOk()->json());

        // A member who does not manage the workspace is not told it has one.
        $member = User::factory()->create();
        $mine->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, self::SCOPES);
        $this->getJson($this->url($mine, $segment))->assertNotFound();

        $this->actingAsMcp($owner, ['identity:read']);
        $this->getJson($this->url($mine, $segment))->assertForbidden();
    }

    public function test_the_two_workspaces_differ_so_isolation_is_observable(): void
    {
        [$mine] = $this->workspace('mine');
        $this->workspace('theirs');

        $this->assertSame(1, app(UnlinkedProposalAgreementAuditor::class)->count($mine)->proposals);
        $this->assertSame(2, app(UnlinkedProposalAgreementAuditor::class)->count()->proposals);
    }

    public function test_balances_by_currency_are_an_object_even_when_there_are_none(): void
    {
        [$workspace, $owner] = $this->workspace('mine');
        $this->actingAsMcp($owner, self::SCOPES);

        $content = (string) $this->getJson($this->url($workspace, 'undated-collectible-invoices'))->assertOk()->getContent();
        $this->assertStringContainsString('"undated_balances":{}', $content);
        $this->assertStringContainsString('"would_become_overdue_balances":{}', $content);
    }

    public function test_the_preflight_budget_is_one_per_workspace_for_rest_and_mcp_alike(): void
    {
        [$workspace, $owner] = $this->workspace('budget');
        [$other] = $this->workspace('other');
        $other->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        ClientBillingSchedule::query()->where('workspace_id', $workspace->id)->update(['next_run_on' => '2021-01-01']);
        $this->actingAsMcp($owner, self::SCOPES);

        $first = $this->getJson($this->url($workspace, 'schedule-generation-preflight'))->assertOk()->json('data');
        $this->assertSame([AgentBillingAuditReadService::PREFLIGHT_PERIOD_CAP, false, 1], [$first['periods_classified'], $first['complete'], $first['schedules_truncated']]);
        for ($call = 2; $call <= AgentBillingAuditReadService::PREFLIGHTS_PER_WINDOW; $call++) {
            $this->getJson($this->url($workspace, 'schedule-generation-preflight'))->assertOk();
        }
        $limited = $this->getJson($this->url($workspace, 'schedule-generation-preflight'))->assertStatus(429);
        $this->assertStringContainsString('Retry in', (string) $limited->json('message'));
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));

        // MCP reaches the same route, so it meets the same budget and is told when to retry.
        $mcp = $this->callTool($this->initialize(), 'billing.preflight_schedule_generation', ['workspace_id' => $workspace->public_id]);
        $this->assertTrue($mcp['result']['isError'] ?? false, (string) json_encode($mcp));
        $this->assertStringContainsString('Retry in', (string) json_encode($mcp));

        // The budget is the workspace's, and only the preflight's.
        $this->getJson($this->url($other, 'schedule-generation-preflight'))->assertOk();
        $this->getJson($this->url($workspace, 'unlinked-proposal-agreements'))->assertOk();
    }

    private function url(Workspace $workspace, string $segment): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/billing-audits/'.$segment;
    }

    /** @return array{Workspace, User} */
    private function workspace(string $slug): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$slug, 'slug' => 'synthetic-rest-audit-'.$slug]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic '.$slug.' client', 'slug' => 'synthetic-rest-'.$slug.'-client']);
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
