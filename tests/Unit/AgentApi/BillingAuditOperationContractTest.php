<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentInlineSchemas;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use App\Services\Mcp\AgentMcpBillingAuditTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\Billing\PeriodRefusalReason;
use App\Support\Billing\ScheduleDefect;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The declarations behind the aggregate billing audits (#459): one REST
 * operation each, which the MCP tool of the same name reaches, with the
 * exact shape the checked OpenAPI document promises, without booting the
 * application.
 */
final class BillingAuditOperationContractTest extends TestCase
{
    /** @var array<string, array{string, string, string, string}> operation => [route name, path, MCP handler, response component] */
    private const array AUDITS = [
        'billing.audit_unplaceable_invoices' => ['billing-audits.unplaceable-invoices', '/workspaces/{workspace}/billing-audits/unplaceable-invoices', 'unplaceableInvoices', 'UnplaceableInvoiceAuditResponse'],
        'billing.audit_undated_collectible_invoices' => ['billing-audits.undated-collectible-invoices', '/workspaces/{workspace}/billing-audits/undated-collectible-invoices', 'undatedCollectibleInvoices', 'UndatedCollectibleInvoiceAuditResponse'],
        'billing.audit_missing_billed_overage' => ['billing-audits.missing-billed-overage', '/workspaces/{workspace}/billing-audits/missing-billed-overage', 'missingBilledOverage', 'MissingBilledOverageAuditResponse'],
        'billing.audit_opening_rollover' => ['billing-audits.opening-rollover', '/workspaces/{workspace}/billing-audits/opening-rollover', 'openingRollover', 'OpeningRolloverAuditResponse'],
        'billing.preflight_schedule_generation' => ['billing-audits.schedule-generation-preflight', '/workspaces/{workspace}/billing-audits/schedule-generation-preflight', 'scheduleGenerationPreflight', 'ScheduleGenerationPreflightResponse'],
        'billing.audit_unlinked_proposal_agreements' => ['billing-audits.unlinked-proposal-agreements', '/workspaces/{workspace}/billing-audits/unlinked-proposal-agreements', 'unlinkedProposalAgreements', 'UnlinkedProposalAgreementAuditResponse'],
    ];

    public function test_each_audit_is_one_manager_only_rest_read_that_its_mcp_tool_reaches(): void
    {
        $this->withCatalog(function (AgentOperationCatalog $catalog): void {
            foreach (self::AUDITS as $id => [$route, $path, $handler, $response]) {
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame(Effect::Read, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertFalse($catalog->readsExternalSystem($id), $id);
                $this->assertSame(['billing:read'], $operation->requirement->scopes, $id);
                // No write cutover: a read is served wherever MCP and its switch are.
                $this->assertSame([AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.'mcp.read.'.$id, AgentDeploymentFlags::MCP_SWITCH.$id], $operation->requirement->flags, $id);
                $this->assertNotNull($operation->rest, $id);
                $this->assertSame(['GET', $path, 'agent-api.v1.'.$route, ['workspace'], [200]], [$operation->rest->method, $operation->rest->path, $operation->rest->routeName, $operation->rest->pathParameters, $operation->rest->successStatuses], $id);
                $this->assertSame([AgentMcpBillingAuditTools::class, $handler], $operation->mcp?->handler, $id);
                $this->assertSame(AgentInlineSchemas::billingAuditInput(), $operation->input, $id);
                $this->assertEquals(SchemaRef::responseOf($id), $operation->output, $id);
                // One text for both: MCP describes the tool with its REST operation's prose.
                $this->assertSame(AgentRestDocumentation::operations()[$id]['description'], $operation->description, $id);

                $documented = AgentRestDocumentation::operations()[$id];
                $this->assertSame(['WorkspaceId'], $documented['parameters'], $id);
                $this->assertSame($response, $documented['response'], $id);
                $this->assertSame(['x-svc-flags' => [], 'x-mcp-tool' => $id], $documented['extensions'], $id);
                $this->assertSame([401, 403, 404, 429], array_keys($documented['errors']), $id);
                $this->assertStringStartsWith('Workspace managers only.', $documented['description'], $id);
            }
        });
    }

    public function test_the_checked_components_are_exactly_the_declared_counts(): void
    {
        $schemas = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
        foreach (self::AUDITS as $id => [, , , $response]) {
            $properties = AgentInlineSchemas::billingAuditProperties($id);
            $component = substr($response, 0, -strlen('Response'));
            $this->assertSame([
                'type' => 'object', 'additionalProperties' => false, 'required' => ['data'],
                'properties' => ['data' => ['$ref' => '#/components/schemas/'.$component]],
            ], $schemas[$response], $id);
            // Declared from the enums, so a new refusal reason or defect
            // fails here until the document carries it too.
            $this->assertSame([
                'type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties,
            ], $schemas[$component], $id);
        }
    }

    public function test_the_preflight_counts_every_refusal_reason_and_schedule_defect(): void
    {
        $count = ['type' => 'integer', 'minimum' => 0];
        $byCase = static fn (array $cases): array => ['type' => 'object', 'additionalProperties' => false, 'required' => $cases, 'properties' => array_fill_keys($cases, $count)];

        $this->assertSame([
            'schedules' => $count, 'schedules_due' => $count, 'periods_classified' => $count, 'complete' => ['type' => 'boolean'],
            'would_halt' => $count, 'halted_by_a_refusal' => $count, 'halted_by_a_pending_draft' => $count, 'halted_by_a_schedule_defect' => $count,
            'schedules_truncated' => $count,
            'refusals_by_reason' => $byCase(array_map(static fn (PeriodRefusalReason $reason): string => $reason->value, PeriodRefusalReason::cases())),
            'defects_by_kind' => $byCase(array_map(static fn (ScheduleDefect $defect): string => $defect->value, ScheduleDefect::cases())),
        ], AgentInlineSchemas::billingAuditProperties('billing.preflight_schedule_generation'));
        $this->assertSame([
            'proposals' => $count, 'sent_without_a_linked_agreement' => $count, 'with_an_unlinked_agreement_on_the_company' => $count,
            'with_an_active_unlinked_agreement' => $count, 'accepted_without_a_linked_agreement' => $count, 'unlinked_agreements' => $count,
        ], AgentInlineSchemas::billingAuditProperties('billing.audit_unlinked_proposal_agreements'));
    }

    public function test_an_audit_with_no_declared_output_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        AgentInlineSchemas::billingAuditProperties('billing.audit_unknown');
    }

    private function withCatalog(callable $assertions): void
    {
        // Only path helpers need a container; no providers, routes or database boot.
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $assertions(new AgentOperationCatalog);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
