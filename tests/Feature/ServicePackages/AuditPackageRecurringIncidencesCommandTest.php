<?php

namespace Tests\Feature\ServicePackages;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AuditPackageRecurringIncidencesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_format_it_cannot_produce(): void
    {
        $this->artisan('svc:packages:audit-recurring-incidences', ['--format' => 'yaml'])
            ->expectsOutputToContain('The --format option must be text or json.')
            ->assertExitCode(2);
    }

    public function test_it_reports_clean_when_no_agreements_are_affected(): void
    {
        // Monthly agreement started mid-month (not affected).
        $workspace = Workspace::query()->create(['name' => 'W1', 'slug' => 'w1', 'default_currency' => 'USD']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'C1', 'slug' => 'c1']);
        $agr = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Monthly Ag',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-15',
            'billing_cadence' => 'monthly',
        ]);
        ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $workspace->id,
            'client_agreement_id' => $agr->id,
            'description' => 'Hosting',
            'amount' => 5000,
            'cadence' => 'monthly',
            'anchor_day' => null,
            'anchor_month' => null,
            'is_active' => true,
        ]);

        $this->artisan('svc:packages:audit-recurring-incidences')
            ->expectsOutputToContain('No existing agreements are affected by duplicate first-cycle recurring charges.')
            ->assertExitCode(0);
    }

    public function test_the_json_contract_and_counts_for_an_affected_agreement(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Quarterly Workspace', 'slug' => 'quarterly-ws', 'default_currency' => 'USD']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Quarterly Corp', 'slug' => 'quarterly-corp']);

        $agr = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Quarterly Support',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-09',
            'billing_cadence' => 'quarterly',
        ]);

        $item = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $workspace->id,
            'client_agreement_id' => $agr->id,
            'description' => 'Quarterly Retainer Support',
            'amount' => 12000,
            'cadence' => 'quarterly',
            'anchor_day' => null,
            'anchor_month' => null,
            'is_active' => true,
        ]);

        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agr->id,
            'invoice_number' => 'INV-001',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 24000,
            'tax_amount' => 0,
            'total_amount' => 24000,
            'paid_amount' => 0,
            'balance_amount' => 24000,
            'cycle_start' => '2026-01-09',
            'cycle_end' => '2026-04-08',
        ]);

        // Two lines for the same recurring item in the first cycle
        ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'recurring_item',
            'description' => 'Quarterly Retainer Support',
            'quantity' => 1,
            'unit_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
            'sort_order' => 1,
            'line_date' => '2026-01-09',
            'client_agreement_id' => $agr->id,
            'client_agreement_recurring_item_id' => $item->id,
        ]);

        ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'recurring_item',
            'description' => 'Quarterly Retainer Support',
            'quantity' => 1,
            'unit_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
            'sort_order' => 2,
            'line_date' => '2026-04-01',
            'client_agreement_id' => $agr->id,
            'client_agreement_recurring_item_id' => $item->id,
        ]);

        Artisan::call('svc:packages:audit-recurring-incidences', ['--format' => 'json']);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'agreements_checked',
            'agreements_affected',
            'charged_invoices_affected',
            'draft_invoices_affected',
            'unbilled_agreements',
            'total_overcharge_cents',
        ], array_keys($payload['summary']));

        $this->assertSame([
            'agreements_checked' => 1,
            'agreements_affected' => 1,
            'charged_invoices_affected' => 1,
            'draft_invoices_affected' => 0,
            'unbilled_agreements' => 0,
            'total_overcharge_cents' => 12000,
        ], $payload['summary']);

        $this->assertCount(1, $payload['agreements']);
        $record = $payload['agreements'][0];
        $this->assertSame('charged_double', $record['disposition']);
        $this->assertSame('quarterly', $record['cadence']);
        $this->assertSame('2026-01-09', $record['starts_on']);
        $this->assertSame('Quarterly Corp', $record['company_name']);
        $this->assertCount(1, $record['affected_invoices']);
        $inv = $record['affected_invoices'][0];
        $this->assertSame('INV-001', $inv['invoice_number']);
        $this->assertSame('issued', $inv['status']);
        $this->assertSame(2, $inv['duplicate_lines_count']);
        $this->assertSame(12000, $inv['overcharge_cents']);
    }

    public function test_it_filters_by_workspace(): void
    {
        $w1 = Workspace::query()->create(['name' => 'W1', 'slug' => 'w1', 'default_currency' => 'USD']);
        $c1 = ClientCompany::query()->create(['workspace_id' => $w1->id, 'name' => 'C1', 'slug' => 'c1']);
        $agr1 = ClientAgreement::query()->create([
            'workspace_id' => $w1->id,
            'client_company_id' => $c1->id,
            'title' => 'Quarterly 1',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-09',
            'billing_cadence' => 'quarterly',
        ]);
        ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $w1->id,
            'client_agreement_id' => $agr1->id,
            'description' => 'Support',
            'amount' => 10000,
            'cadence' => 'quarterly',
            'anchor_day' => null,
            'anchor_month' => null,
            'is_active' => true,
        ]);

        $w2 = Workspace::query()->create(['name' => 'W2', 'slug' => 'w2', 'default_currency' => 'USD']);
        $c2 = ClientCompany::query()->create(['workspace_id' => $w2->id, 'name' => 'C2', 'slug' => 'c2']);
        $agr2 = ClientAgreement::query()->create([
            'workspace_id' => $w2->id,
            'client_company_id' => $c2->id,
            'title' => 'Quarterly 2',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-15',
            'billing_cadence' => 'quarterly',
        ]);
        ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $w2->id,
            'client_agreement_id' => $agr2->id,
            'description' => 'Support',
            'amount' => 15000,
            'cadence' => 'quarterly',
            'anchor_day' => null,
            'anchor_month' => null,
            'is_active' => true,
        ]);

        // Filter by W1
        Artisan::call('svc:packages:audit-recurring-incidences', ['--workspace' => $w1->public_id, '--format' => 'json']);
        $payload1 = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload1['summary']['agreements_checked']);
        $this->assertSame($agr1->public_id, $payload1['agreements'][0]['agreement_id']);

        // Unknown workspace exits with code 2
        $this->artisan('svc:packages:audit-recurring-incidences', ['--workspace' => 'ws_nonexistent'])
            ->expectsOutputToContain('Workspace ws_nonexistent not found.')
            ->assertExitCode(2);
    }
}
