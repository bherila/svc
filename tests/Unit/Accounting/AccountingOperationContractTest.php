<?php

namespace Tests\Unit\Accounting;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpFinanceTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/** The published wire contract remains authoritative for both accounting transports. */
final class AccountingOperationContractTest extends TestCase
{
    public function test_accounting_routes_scopes_parameters_and_media_types_match_the_published_contract(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $document = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
            $catalog = new AgentOperationCatalog;
            foreach ([
                'finance.invoice_lines.list' => ['invoice-lines', 'invoiceLines'],
                'finance.cash_income.list' => ['cash-income', 'cashIncome'],
                'finance.income.yearly' => ['income', 'yearlyIncome'],
            ] as $id => [$suffix, $method]) {
                $path = '/workspaces/{workspace}/accounting/'.$suffix;
                $published = $document['paths'][$path]['get'];
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation);
                $this->assertSame($id, $operation->mcpName());
                $this->assertSame([AgentMcpFinanceTools::class, $method], $operation->mcp->handler);
                $this->assertTrue($catalog->isManagerOnly($id));
                $this->assertSame(['finance:read'], $operation->requirement->scopes);
                $this->assertSame($published['security'][0]['oauth2'], $operation->requirement->scopes);
                $rest = $operation->rest;
                $this->assertNotNull($rest);
                $this->assertSame($path, $rest->path);
                $this->assertSame('GET', $rest->method);
                $this->assertSame($published['description'], $rest->description);
                $this->assertSame($published['parameters'], array_map(
                    static fn (string|array $parameter): array => is_string($parameter) ? ['$ref' => '#/components/parameters/'.$parameter] : $parameter,
                    $rest->parameters,
                ));
                $this->assertSame([200], $rest->successStatuses);
                $this->assertSame($published['responses'][200]['content']['application/json']['schema']['$ref'], '#/components/schemas/'.$rest->responseSchema->component);
                $statuses = $id === 'finance.income.yearly' ? [200, 403, 404, 422] : [403, 404, 422];
                $this->assertSame($statuses, array_keys($rest->responses));
                foreach ($rest->responses as $status => $response) {
                    $this->assertSame($published['responses'][$status], $response);
                }
                if ($id === 'finance.income.yearly') {
                    $this->assertSame(['application/json', 'text/csv'], array_keys($rest->responses[200]['content']));
                }
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
