<?php

namespace Tests\Unit\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\AgentApi\Presenters\AgentProposalPresenter;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use App\Support\AgentApi\Presenters\AgreementReadPresenter;
use App\Support\ClientHome\InvoiceSummary;
use App\Support\ClientHome\TaskRow;
use App\Support\ClientHome\TimeEntryRow;
use App\Support\Engagement\AgreementTermsPayload;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PortalReadProjectionTest extends TestCase
{
    public function test_portal_invoice_fields_keep_numeric_browser_quantities_and_withhold_internal_metadata_without_queries(): void
    {
        $invoice = new ClientInvoice(['invoice_number' => 'SYN-PUBLIC', 'status' => 'issued', 'currency' => 'EUR',
            'subtotal_amount' => 1250, 'tax_amount' => 250, 'total_amount' => 1500, 'paid_amount' => 500,
            'balance_amount' => 1000, 'issue_date' => '2026-10-28', 'due_date' => '2026-11-28',
            'service_period_start' => '2026-10-01', 'service_period_end' => '2026-10-31', 'notes' => 'Synthetic private note']);
        $invoice->public_id = 'SYN-INVOICE';
        $line = new ClientInvoiceLine(['description' => 'Synthetic public work', 'quantity' => '1.125', 'hours' => '0.75',
            'line_date' => '2026-10-28', 'unit_amount' => 1000, 'total_amount' => 1125, 'tax_amount' => 50,
            'client_agreement_id' => 123, 'client_agreement_recurring_item_id' => 456]);
        $line->public_id = 'SYN-LINE';
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame([
            'id' => 'SYN-INVOICE', 'invoice_number' => 'SYN-PUBLIC', 'status' => 'issued', 'total_amount' => 1500,
            'paid_amount' => 500, 'balance_amount' => 1000, 'currency' => 'EUR', 'issue_date' => '2026-10-28',
            'due_date' => '2026-11-28', 'service_period_start' => '2026-10-01', 'service_period_end' => '2026-10-31',
            'subtotal_amount' => 1250, 'tax_amount' => 250,
        ], AgentInvoicePresenter::portalDetails($invoice));
        $this->assertSame(['id' => 'SYN-LINE', 'description' => 'Synthetic public work', 'unit_amount' => 1000,
            'total_amount' => 1125, 'quantity' => 1.125, 'hours' => 0.75, 'line_date' => '2026-10-28'], AgentInvoicePresenter::portalLine($line));
        $expectedHome = AgentInvoicePresenter::summary($invoice) + ['href' => '/synthetic/invoice'];
        $actualHome = InvoiceSummary::fromInvoice($invoice, '/synthetic/invoice')->toArray();
        ksort($expectedHome);
        ksort($actualHome);
        $this->assertSame($expectedHome, $actualHome);
        $invoice->service_period_start = null;
        $invoice->service_period_end = null;
        $line->line_date = null;
        $this->assertNull(AgentInvoicePresenter::portalDetails($invoice)['service_period_start']);
        $this->assertNull(AgentInvoicePresenter::portalDetails($invoice)['service_period_end']);
        $this->assertNull(AgentInvoicePresenter::portalLine($line)['line_date']);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_home_and_client_time_rows_share_facts_but_require_explicit_description_policy(): void
    {
        $entry = new ClientTimeEntry(['worked_on' => '2026-10-28', 'minutes' => 45, 'description' => 'Synthetic internal note',
            'client_visible_description' => 'Synthetic cleared description', 'status' => 'approved',
            'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 12345, 'subcontractor_cost_amount' => 789]);
        $entry->public_id = 'SYN-TIME';
        $task = new ClientTask(['title' => 'Synthetic safe task', 'status' => 'open', 'description' => 'Synthetic private task note']);
        $task->public_id = 'SYN-TASK';
        $proposal = new ClientProposal(['title' => 'Synthetic awaiting proposal', 'status' => 'sent', 'terms' => 'Synthetic private terms']);
        $proposal->public_id = 'SYN-PROPOSAL';
        DB::enableQueryLog();
        DB::flushQueryLog();

        $clientRow = ['id' => 'SYN-TIME', 'worked_on' => '2026-10-28', 'project' => 'Synthetic project',
            'description' => 'Synthetic cleared description', 'minutes' => 45];
        $this->assertSame($clientRow, AgentTimeEntryPresenter::clientRow($entry, 'Synthetic project'));
        $this->assertSame($clientRow, TimeEntryRow::fromEntry($entry, 'Synthetic project', useClientDescription: true)->toArray());
        $this->assertSame('Synthetic internal note', TimeEntryRow::fromEntry($entry, null, useClientDescription: false)->description);
        $entry->client_visible_description = null;
        $this->assertNull(AgentTimeEntryPresenter::clientRow($entry, null)['description']);
        $this->assertNull(TimeEntryRow::fromEntry($entry, null, useClientDescription: true)->description);
        $this->assertSame(['id' => 'SYN-TASK', 'title' => 'Synthetic safe task', 'project' => null, 'status' => 'open'], TaskRow::fromTask($task, null)->toArray());
        $this->assertSame(['id' => 'SYN-PROPOSAL', 'title' => 'Synthetic awaiting proposal', 'status' => 'sent'], AgentProposalPresenter::identity($proposal));
        $this->assertFalse($proposal->relationLoaded('items'));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_agreement_terms_share_calculations_and_keep_client_engine_fields_null(): void
    {
        $agreement = new ClientAgreement(['title' => 'Synthetic quarterly agreement', 'status' => 'active', 'currency' => 'EUR',
            'billing_cadence' => 'quarterly', 'starts_on' => '2026-01-01', 'hourly_rate_amount' => 10000,
            'retainer_minutes' => 60, 'retainer_amount' => 10000, 'period_retainer_minutes' => 240,
            'period_retainer_amount' => 35000, 'rollover_months' => 2, 'agreement_text' => 'Synthetic private terms',
            'square_series_key' => 'Synthetic private series']);
        $item = new ClientAgreementRecurringItem(['description' => 'Synthetic recurring item', 'cadence' => 'monthly',
            'quantity' => '1.125', 'amount' => 1200, 'currency' => 'EUR', 'effective_on' => '2026-10-28', 'is_active' => true]);
        $item->public_id = 'SYN-ITEM';
        DB::enableQueryLog();
        DB::flushQueryLog();
        $agreement->public_id = 'SYN-AGREEMENT';
        $this->assertSame(['id' => 'SYN-AGREEMENT', 'title' => 'Synthetic quarterly agreement',
            'status' => 'active', 'billing_cadence' => 'quarterly'], AgreementReadPresenter::summary($agreement));
        $operator = app(AgreementReadPresenter::class)->present($agreement, 'Synthetic project');
        $this->assertSame('quarterly', $operator['effective_billing_cadence']);
        $this->assertSame('prorate_hours', $operator['effective_first_cycle_proration']);
        $this->assertSame(80, $operator['retainer_minutes_per_month']);
        $portal = AgreementTermsPayload::for($agreement, 'Synthetic project');
        foreach (['effective_billing_cadence', 'effective_first_cycle_proration', 'retainer_minutes_per_month'] as $key) {
            $this->assertNull($portal[$key]);
            unset($operator[$key], $portal[$key]);
        }
        ksort($operator);
        ksort($portal);
        $this->assertSame($operator, $portal);
        $this->assertSame(240, $portal['retainer_minutes_per_period']);
        $this->assertSame(35000, $portal['retainer_amount_per_period']);
        $this->assertArrayNotHasKey('agreement_text', $portal);
        $this->assertArrayNotHasKey('square_series_key', $portal);
        $this->assertSame(['id' => 'SYN-ITEM', 'description' => 'Synthetic recurring item', 'cadence' => 'monthly',
            'quantity' => 1.125, 'amount' => 1200, 'currency' => 'EUR', 'effective_on' => '2026-10-28',
            'expires_on' => null, 'is_active' => true], AgreementReadPresenter::recurringItem($item));
        // Fractional monthly grants distinguish rounding from either directional truncation.
        $agreement->period_retainer_minutes = 241;
        $this->assertSame(80, app(AgreementReadPresenter::class)->present($agreement, null)['retainer_minutes_per_month']);
        $agreement->period_retainer_minutes = 242;
        $this->assertSame(81, app(AgreementReadPresenter::class)->present($agreement, null)['retainer_minutes_per_month']);
        $agreement->billing_cadence = 'one_time';
        $oneTime = app(AgreementReadPresenter::class)->present($agreement, null);
        $this->assertNull($oneTime['effective_billing_cadence']);
        $this->assertNull($oneTime['effective_first_cycle_proration']);
        $this->assertNull($oneTime['retainer_minutes_per_month']);
        $this->assertNull(AgreementTermsPayload::for($agreement, null)['retainer_minutes_per_period']);
        $this->assertNull(AgreementTermsPayload::for($agreement, null)['retainer_amount_per_period']);
        $item->effective_on = null;
        $this->assertNull(AgreementReadPresenter::recurringItem($item)['effective_on']);
        $item->quantity = null;
        $item->amount = null;
        $this->assertNull(AgreementReadPresenter::recurringItem($item)['quantity']);
        $this->assertNull(AgreementReadPresenter::recurringItem($item)['amount']);
        $this->assertSame([], DB::getQueryLog());
    }
}
