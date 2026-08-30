<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The audit behind the fail-closed overage window.
 *
 * Four conditions decide whether an unplaceable invoice can reach a billed
 * overage sum, and each is asserted here by its own exclusion rather than by
 * one happy path. An audit that counted every invoice with a missing service
 * period would report a population several times the real one, and "none of
 * them touches money" is the answer this command exists to be trusted about.
 */
final class AuditUnplaceableInvoicesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create([
            'name' => 'Unplaceable Workspace',
            'slug' => 'unplaceable-workspace',
        ]);

        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Unplaceable Client',
            'slug' => 'unplaceable-client',
        ]);

        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Undated retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);
    }

    public function test_a_charged_invoice_with_overage_and_no_period_is_counted(): void
    {
        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '5.5']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['affected']);
        $this->assertSame(5.5, $summary['overage_hours_at_stake']);
    }

    public function test_an_invoice_with_a_service_period_is_not_counted(): void
    {
        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '5',
            'service_period_end' => '2026-01-31',
        ]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['invoices']);
        $this->assertSame(0, $summary['without_a_service_period']);
        $this->assertSame(0, $summary['affected']);
    }

    public function test_an_uncharged_invoice_is_not_counted(): void
    {
        // A draft is excluded from the overage sum by status before the date
        // window is ever reached, so a missing period on one costs nothing.
        $this->invoice(['status' => 'draft', 'hours_billed_at_rate' => '5']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['without_a_service_period'], 'It has no period');
        $this->assertSame(0, $summary['charged_of_those'], 'But it has charged nobody');
        $this->assertSame(0, $summary['affected']);
    }

    public function test_an_invoice_on_no_agreement_is_not_counted(): void
    {
        // The sum is taken per agreement. An invoice naming none is never one
        // of the rows it reads, whatever its period says.
        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '5',
            'client_agreement_id' => null,
        ]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['charged_of_those'], 'Charged with no period');
        $this->assertSame(0, $summary['on_an_agreement_of_those'], 'But belongs to no sum');
        $this->assertSame(0, $summary['affected']);
    }

    public function test_an_invoice_carrying_no_overage_is_not_counted(): void
    {
        // It is inside the sum and contributes zero. Reporting it as at stake
        // would put the largest population in the report on rows that cannot
        // change any number.
        $this->invoice(['status' => 'paid', 'hours_billed_at_rate' => null]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['on_an_agreement_of_those'], 'It reaches the sum');
        $this->assertSame(0, $summary['affected'], 'And adds nothing to it');
        $this->assertSame(0.0, $summary['overage_hours_at_stake']);
    }

    public function test_the_report_names_no_workspace_company_agreement_or_invoice(): void
    {
        // The value of this command is that its output can be pasted into a
        // public issue. A count is safe to publish; the invoice number it
        // counted carries a client prefix and is not.
        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '5']);

        $this->assertSame(0, Artisan::call('svc:billing:audit-unplaceable-invoices'));
        $report = Artisan::output();

        $this->assertStringContainsString('Overage hours at stake', $report, 'The report ran');

        $secrets = [
            'Unplaceable Workspace', 'Unplaceable Client', 'Undated retainer',
            'unplaceable-workspace', 'unplaceable-client', 'UNPLACEABLE-1',
        ];

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $report);
        }
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->artisan('svc:billing:audit-unplaceable-invoices --format=yaml')->assertExitCode(2);
    }

    /**
     * The summary as JSON.
     *
     * @return array<string, float|int>
     */
    private function summary(): array
    {
        $this->assertSame(0, Artisan::call('svc:billing:audit-unplaceable-invoices', ['--format' => 'json']));

        /** @var array{summary: array<string, float|int>} $decoded */
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['summary'];
    }

    /** @param array<string, mixed> $attributes */
    private function invoice(array $attributes): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $this->agreement->id,
            'invoice_number' => 'UNPLACEABLE-1',
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $invoice->forceFill($attributes)->save();

        return $invoice;
    }
}
