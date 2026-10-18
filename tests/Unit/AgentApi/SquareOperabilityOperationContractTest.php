<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentInlineSchemas;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\Billing\PeriodRefusalReason;
use App\Support\Billing\ScheduleDefect;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The declarations behind the #458 operations: what each one is, who may
 * call it, and the exact shapes it promises, without booting the application.
 */
final class SquareOperabilityOperationContractTest extends TestCase
{
    public function test_the_workspace_audit_tools_are_manager_only_idempotent_reads_with_exact_outputs(): void
    {
        $count = ['type' => 'integer', 'minimum' => 0];
        $byCase = static fn (array $cases): array => ['type' => 'object', 'additionalProperties' => false, 'required' => $cases, 'properties' => array_fill_keys($cases, $count)];
        $expected = [
            'billing.preflight_schedule_generation' => [
                'schedules' => $count, 'schedules_due' => $count, 'periods_classified' => $count, 'complete' => ['type' => 'boolean'],
                'would_halt' => $count, 'halted_by_a_refusal' => $count, 'halted_by_a_pending_draft' => $count, 'halted_by_a_schedule_defect' => $count,
                'schedules_truncated' => $count,
                'refusals_by_reason' => $byCase(array_map(static fn (PeriodRefusalReason $reason): string => $reason->value, PeriodRefusalReason::cases())),
                'defects_by_kind' => $byCase(array_map(static fn (ScheduleDefect $defect): string => $defect->value, ScheduleDefect::cases())),
            ],
            'billing.audit_unlinked_proposal_agreements' => [
                'proposals' => $count, 'sent_without_a_linked_agreement' => $count, 'with_an_unlinked_agreement_on_the_company' => $count,
                'with_an_active_unlinked_agreement' => $count, 'accepted_without_a_linked_agreement' => $count, 'unlinked_agreements' => $count,
            ],
        ];
        $this->withCatalog(function (AgentOperationCatalog $catalog) use ($expected): void {
            foreach ($expected as $id => $properties) {
                $this->assertSame($properties, AgentInlineSchemas::billingAuditProperties($id), $id);
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame(Effect::Read, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertFalse($catalog->readsExternalSystem($id), $id);
                $this->assertNull($operation->rest, $id);
                $this->assertSame(['billing:read'], $operation->requirement->scopes, $id);
                $this->assertSame([AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.'mcp.read.'.$id, AgentDeploymentFlags::MCP_SWITCH.$id], $operation->requirement->flags, $id);
                $this->assertSame(AgentInlineSchemas::billingAuditInput(), $operation->input, $id);
                $this->assertSame(AgentInlineSchemas::billingAuditOutput($properties), $operation->output, $id);
                $this->assertStringStartsWith('Workspace managers only.', $operation->description, $id);
            }
        });
    }

    public function test_square_reconcile_is_a_manager_only_idempotent_write_that_reads_square(): void
    {
        $this->withCatalog(function (AgentOperationCatalog $catalog): void {
            $id = 'invoices.square_reconcile';
            $operation = $catalog->registry()->find($id);
            $this->assertNotNull($operation);
            $this->assertSame(Effect::LocalWrite, $operation->effect);
            $this->assertTrue($operation->isIdempotent());
            $this->assertTrue($catalog->isManagerOnly($id));
            $this->assertTrue($catalog->readsExternalSystem($id));
            $this->assertSame([AgentDeploymentFlags::WRITES, AgentDeploymentFlags::SQUARE, AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.'mcp.write', AgentDeploymentFlags::MCP_SWITCH.$id], $operation->requirement->flags);
            $this->assertNotNull($operation->rest);
            $this->assertSame(['POST', '/workspaces/{workspace}/invoices/{invoice}/square-reconcile', [200]], [$operation->rest->method, $operation->rest->path, $operation->rest->successStatuses]);
            $documented = AgentRestDocumentation::operations()[$id];
            $this->assertSame([
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceSquareReconcileRequest',
                'response' => 'InvoiceSquareResponse',
                'response_description' => 'The invoice after reconciliation',
                'extensions' => ['x-mcp-tool' => $id, 'x-svc-flags' => ['AGENT_API_WRITES_ENABLED']],
            ], array_diff_key($documented, ['description' => true]));
            $this->assertStringContainsString('Nothing is created, changed or paid in Square', $documented['description']);
        });
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
