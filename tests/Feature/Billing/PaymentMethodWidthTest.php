<?php

namespace Tests\Feature\Billing;

use App\Http\Requests\Billing\StorePaymentRequest;
use App\Models\ClientInvoicePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
}
