<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AccountingOperations;
use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use App\Services\Mcp\AgentMcpAccountingTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The accounting reads' declarations: manager-only `finance:read` REST reads,
 * three reached by an MCP tool of the same name and one a REST download, with
 * exactly the prose and parameters the checked OpenAPI document publishes,
 * without booting the application for the document checks.
 */
final class AccountingOperationContractTest extends TestCase
{
    private const array LIST_QUERY = ['entry_type', 'client_id', 'invoice_id', 'from', 'to', 'per_page', 'UpdatedSince', 'Watermark', 'Cursor'];

    /** @var array<string, array{string, string|null, string|null, list<string>}> operation => [path, handler, response, query] */
    private const array READS = [
        'finance.invoice_lines.list' => ['/workspaces/{workspace}/invoice-lines', 'invoiceLines', 'FinanceInvoiceLineEntryListResponse', self::LIST_QUERY],
        'finance.cash_income.list' => ['/workspaces/{workspace}/cash-income', 'cashIncome', 'FinanceCashEntryListResponse', self::LIST_QUERY],
        'finance.income_export.get' => ['/workspaces/{workspace}/income-export', 'incomeExport', 'FinanceIncomeExportResponse', ['year']],
        'finance.income_export.csv' => ['/workspaces/{workspace}/income-export/csv', null, null, ['year']],
    ];

    public function test_the_routes_and_definitions_are_pinned(): void
    {
        $this->assertSame(array_keys(self::READS), array_keys(AccountingOperations::ROUTES));
        $this->assertSame(['finance.invoice_lines.list', 'finance.cash_income.list', 'finance.income_export.get'], array_keys(AccountingOperations::DEFINITIONS));
        foreach (self::READS as $id => [$path, $handler, $response, $query]) {
            $this->assertSame([$path, [200]], [AccountingOperations::ROUTES[$id][1], AccountingOperations::ROUTES[$id][2]], $id);
            if ($handler !== null) {
                $this->assertSame([$handler, $response, $query], [AccountingOperations::DEFINITIONS[$id][0], AccountingOperations::DEFINITIONS[$id][2], AccountingOperations::DEFINITIONS[$id][3]], $id);
            }
        }
        $this->assertSame(['invoice-lines.index', 'cash-income.index', 'income-export.show', 'income-export.csv'], array_map(static fn (array $route): string => $route[0], array_values(AccountingOperations::ROUTES)));
        $this->assertSame([2000, 2100], [AccountingOperations::YEAR_MIN, AccountingOperations::YEAR_MAX]);
    }

    public function test_the_documentation_is_exactly_what_the_checked_document_publishes(): void
    {
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $documentation = AccountingOperations::documentation();
        $this->assertSame(array_keys(self::READS), array_keys($documentation));

        foreach (self::READS as $id => [$path, , $response, $query]) {
            $documented = $documentation[$id];
            $published = $document['paths'][$path]['get'];
            $this->assertSame('GET', $documented['method'], $id);
            $this->assertSame(['finance:read'], $documented['scopes'], $id);
            $this->assertSame([['oauth2' => ['finance:read']], ['apiToken' => []]], $published['security'], $id);
            $this->assertSame($published['description'], $documented['description'], $id);
            $this->assertStringEndsWith('Computed in the request; nothing is stored.', $documented['description'], $id);
            $this->assertStringContainsString('Workspace owners/admins only: a member who is not an owner or admin, including the holder of a Sanctum finance token, is refused with 403.', $documented['description'], $id);
            $this->assertSame([401, 403, 404, 422, 429], array_keys($documented['errors']), $id);
            $this->assertSame('WorkspaceId', $documented['parameters'][0], $id);
            $this->assertCount(count($query) + 1, $documented['parameters'], $id);
            if ($response === null) {
                $this->assertSame(['text/csv', 'A CSV file with one row per month and a total row'], $documented['binary'], $id);
                $this->assertSame(['text/csv'], array_keys($published['responses'][200]['content']), $id);
                $this->assertArrayNotHasKey('response', $documented, $id);

                continue;
            }
            $this->assertSame($response, $documented['response'], $id);
            $this->assertSame($documented['response_description'], $published['responses'][200]['description'], $id);
            $this->assertSame('#/components/schemas/'.$response, $published['responses'][200]['content']['application/json']['schema']['$ref'], $id);
        }
        $this->assertStringContainsString('50 a page unless per_page is (at most 100)', $documentation['finance.invoice_lines.list']['description']);
        $this->assertStringContainsString('now less 120 seconds', $documentation['finance.cash_income.list']['description']);
        $this->assertSame(['name' => 'year', 'in' => 'query', 'required' => true, 'description' => 'The calendar year.', 'schema' => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100]], $documentation['finance.income_export.get']['parameters'][1]);
        $this->assertSame(['type' => 'string', 'enum' => ['invoice_line', 'correction', 'void']], $documentation['finance.invoice_lines.list']['parameters'][1]['schema']);
        $this->assertSame(['payment', 'refund', 'refund_reversal', 'dispute_debit', 'dispute_credit', 'fee', 'fee_reversal'], $documentation['finance.cash_income.list']['parameters'][1]['schema']['enum']);
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50], $documentation['finance.cash_income.list']['parameters'][6]['schema']);
        $this->assertSame(['UpdatedSince', 'Watermark', 'Cursor'], array_slice($documentation['finance.cash_income.list']['parameters'], 7));
    }

    public function test_each_read_is_a_manager_only_finance_read(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            foreach (self::READS as $id => [$path, $handler]) {
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertSame(['finance:read'], $operation->requirement->scopes, $id);
                $this->assertSame(['GET', $path, [200]], [$operation->rest?->method, $operation->rest?->path, $operation->rest?->successStatuses], $id);
                if ($handler === null) {
                    $this->assertSame(Effect::Download, $operation->effect, $id);
                    $this->assertNull($operation->mcp, $id);

                    continue;
                }
                $this->assertSame(Effect::Read, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertSame([AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.'mcp.read', AgentDeploymentFlags::MCP_SWITCH.$id], $operation->requirement->flags, $id);
                $this->assertSame([AgentMcpAccountingTools::class, $handler], $operation->mcp?->handler, $id);
                $this->assertEquals(SchemaRef::responseOf($id), $operation->output, $id);
                $this->assertSame(AgentRestDocumentation::operations()[$id]['description'], $operation->description, $id);
                $this->assertSame(['x-svc-flags' => [], 'x-mcp-tool' => $id], $operation->extensions, $id);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
