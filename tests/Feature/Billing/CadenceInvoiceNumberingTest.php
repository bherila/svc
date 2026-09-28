<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cadence invoice is numbered `PREFIX-YYYYMM-NNN` by the month it sells.
 *
 * docs/client-management/cadence-billing.md#invoice-period has always stated
 * the rule; the generator took the workspace's `SVC-NNNNN` counter instead, so
 * the first invoice generated for an imported client broke its series.
 */
final class CadenceInvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Numbering', 'slug' => 'numbering']);
        $this->company = $this->company('Atlas Imaging', 'atlas-imaging');
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Imaging',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_a_monthly_invoice_is_numbered_by_the_month_it_sells(): void
    {
        $agreement = $this->agreement($this->company, 'monthly');
        $this->entry('2026-09-15', 90);

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement,
        );

        // September work, October retainer: issued in October.
        $this->assertSame('ATLA-202610-001', $invoice->invoice_number);
    }

    public function test_a_quarterly_invoice_is_numbered_by_the_first_month_of_the_quarter_it_sells(): void
    {
        $agreement = $this->agreement($this->company, 'quarterly');

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), $agreement,
        );

        $this->assertSame('ATLA-202604-001', $invoice->invoice_number);
    }

    public function test_the_series_continues_the_prefix_the_client_already_has(): void
    {
        $agreement = $this->agreement($this->company, 'monthly');
        // An imported history under a prefix the current name would not produce.
        $this->invoice($this->company, 'ATIM-202609-001', '2026-08-01', '2026-08-31', 'paid');

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement,
        );

        $this->assertSame('ATIM-202610-001', $invoice->invoice_number);
    }

    /**
     * The unique index is `(workspace_id, invoice_number)`, so the sequence is
     * the workspace's - and only this workspace's.
     */
    public function test_the_sequence_is_counted_across_the_workspace_and_no_further(): void
    {
        $sibling = $this->company('Atlas Logistics', 'atlas-logistics');
        $this->invoice($sibling, 'ATLA-202610-001', '2026-09-01', '2026-09-30', 'draft', kind: InvoiceKind::AdHoc);

        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $foreign = ClientCompany::query()->create([
            'workspace_id' => $elsewhere->id, 'name' => 'Atlas Foreign', 'slug' => 'atlas-foreign',
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $elsewhere->id, 'client_company_id' => $foreign->id,
            'invoice_number' => 'ATLA-202610-007', 'currency' => 'USD', 'status' => 'draft',
            'invoice_kind' => InvoiceKind::AdHoc->value,
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        $agreement = $this->agreement($this->company, 'monthly');
        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement,
        );

        $this->assertSame('ATLA-202610-002', $invoice->invoice_number);
    }

    /**
     * A name with nothing to abbreviate still gets a prefix - and the next
     * invoice reads it back and continues the same series.
     */
    public function test_a_name_with_no_latin_letters_still_opens_a_series_that_continues(): void
    {
        $company = $this->company('★ ★ ★', 'stars');
        $agreement = $this->agreement($company, 'monthly');
        $prefix = strtoupper(substr(str_replace('-', '', (string) $company->public_id), 0, 4));
        $service = app(ClientInvoicingService::class);

        $september = $service->generateInvoice($company, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), $agreement);
        $september->forceFill(['status' => 'issued'])->save();
        // Renamed since: the series, not the name, decides.
        $company->forceFill(['name' => 'Stellar Works'])->save();
        $october = $service->generateInvoice($company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement);

        $this->assertSame($prefix.'-202609-001', $september->invoice_number);
        $this->assertSame($prefix.'-202610-001', $october->invoice_number);
    }

    public function test_regenerating_the_draft_keeps_its_number(): void
    {
        $agreement = $this->agreement($this->company, 'monthly');
        $this->entry('2026-09-15', 90);
        $service = app(ClientInvoicingService::class);

        $first = $service->generateInvoice($this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement);
        $this->entry('2026-09-16', 30);
        $second = $service->generateInvoice($this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('ATLA-202610-001', $second->invoice_number);
    }

    private function company(string $name, string $slug): ClientCompany
    {
        return ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => $name, 'slug' => $slug]);
    }

    private function agreement(ClientCompany $company, string $cadence): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 375000,
            'hourly_rate_amount' => 37500,
            'billing_cadence' => $cadence,
            'rollover_months' => 1,
        ]);
    }

    private function invoice(
        ClientCompany $company,
        string $number,
        string $start,
        string $end,
        string $status,
        InvoiceKind $kind = InvoiceKind::CadencePeriod,
    ): ClientInvoice {
        return ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'currency' => 'USD',
            'status' => $status,
            'invoice_kind' => $kind->value,
            'service_period_start' => $start,
            'service_period_end' => $end,
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'hours_billed_at_rate' => 0,
        ]);
    }

    private function entry(string $workedOn, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'billing_rate_amount' => 37500,
            'currency' => 'USD',
        ]);
    }
}
