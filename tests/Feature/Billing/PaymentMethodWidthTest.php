<?php

namespace Tests\Feature\Billing;

use App\Http\Requests\Billing\StorePaymentRequest;
use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Mcp\AgentMcpWriteTools;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mcp\Capability\Attribute\Schema as McpSchema;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

/**
 * Every rule that accepts a payment method is the column's width, and the
 * column is that width.
 *
 * SQLite ignores declared widths, so the local suite cannot catch a rule wider
 * than `client_invoice_payments.method`; MariaDB refuses or truncates the
 * value instead. The width is read from the driver where it reports one (the
 * MariaDB lane) and from the migration that declares it everywhere.
 */
final class PaymentMethodWidthTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_column_is_the_declared_width(): void
    {
        $migration = (string) file_get_contents(database_path('migrations/2026_08_15_020000_create_billing_workflow.php'));
        $this->assertMatchesRegularExpression(
            '/->string\(\'method\', '.ClientInvoicePayment::METHOD_MAX_LENGTH.'\)/',
            $migration,
        );
        // A later migration that changed the column would leave the one
        // above describing a width that no longer exists; SQLite reports
        // none to catch it with, so any such migration must update this test.
        $touching = array_values(array_filter(
            glob(database_path('migrations/*.php')) ?: [],
            static function (string $path): bool {
                $source = (string) file_get_contents($path);

                return str_contains($source, 'client_invoice_payments')
                    && preg_match('/[\'"]method[\'"]/', $source) === 1;
            },
        ));
        $this->assertSame(['2026_08_15_020000_create_billing_workflow.php'], array_map(basename(...), $touching));

        $column = collect(Schema::getColumns('client_invoice_payments'))->firstWhere('name', 'method');
        $this->assertIsArray($column);
        if (preg_match('/\((\d+)\)/', (string) $column['type'], $width) === 1) {
            $this->assertSame(ClientInvoicePayment::METHOD_MAX_LENGTH, (int) $width[1]);
        }
    }

    public function test_the_operator_form_uses_the_column_width(): void
    {
        $this->assertContains('max:'.ClientInvoicePayment::METHOD_MAX_LENGTH, (new StorePaymentRequest)->rules()['method']);
    }

    /** The published agent contract offers no more than the column holds. */
    public function test_the_agent_contract_uses_the_column_width(): void
    {
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true);
        foreach (['ReceivedPaymentRecordRequest', 'ReceivedPaymentCorrectionRequest', 'InvoiceIssuePayment'] as $schema) {
            $this->assertSame(
                ClientInvoicePayment::METHOD_MAX_LENGTH,
                $document['components']['schemas'][$schema]['properties']['method']['maxLength'] ?? null,
                $schema,
            );
        }
    }

    /** The MCP tools declare the same bound in the schema an agent is shown. */
    public function test_the_mcp_tools_use_the_column_width(): void
    {
        foreach (['paymentsRecord', 'paymentsCorrect'] as $tool) {
            $parameter = collect((new ReflectionMethod(AgentMcpWriteTools::class, $tool))->getParameters())
                ->firstOrFail(static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'method');
            $schema = $parameter->getAttributes(McpSchema::class)[0]->getArguments();
            $this->assertSame(ClientInvoicePayment::METHOD_MAX_LENGTH, $schema['maxLength'] ?? null, $tool);
        }
    }

    /**
     * The service every recording path crosses bounds it too, after trimming:
     * the command line and imports reach it without a form rule.
     */
    public function test_recording_refuses_a_method_longer_than_its_column(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic width', 'slug' => 'synthetic-width']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'SYNTHETIC-WIDTH-1'], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 10000],
        ]), $workspace);
        $payment = ['amount' => 100, 'currency' => 'USD', 'received_on' => now()->toDateString()];

        try {
            $service->applyPayment($invoice, [...$payment, 'method' => str_repeat('m', 41), 'idempotency_key' => 'synthetic-long'], $workspace);
            $this->fail('A method longer than its column was recorded');
        } catch (DomainException $refusal) {
            $this->assertSame('method may not be longer than 40 characters.', $refusal->getMessage());
        }
        $this->assertDatabaseCount('client_invoice_payments', 0);

        $recorded = $service->applyPayment($invoice, [...$payment, 'method' => ' '.str_repeat('m', 40).' ', 'idempotency_key' => 'synthetic-width'], $workspace);
        $this->assertSame(str_repeat('m', 40), $recorded->method);
    }
}
