<?php

namespace Tests\Feature\Finance;

use App\Models\AccountingInvoiceEntry;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceCorrection;
use App\Models\ClientInvoicePayment;
use App\Models\ClientInvoicePaymentRefund;
use App\Models\ClientProject;
use App\Models\ProcessorMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AccountingReadsTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ClientCompany $client;

    private ClientProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-01-05 12:00:00 UTC'));
        $this->owner = User::factory()->create(['email' => 'accountant@example.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Accounting', 'slug' => 'synthetic-accounting']);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->client = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic Client', 'slug' => 'synthetic-client']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->client->id, 'name' => 'Synthetic Project']);
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::FINANCE_READ]);
    }

    public function test_invoice_lines_expose_tax_and_corrections_append_new_reversing_records_after_sync(): void
    {
        $invoice = $this->invoice();
        $this->travelTo(Carbon::parse('2026-01-05 12:10:00 UTC'));
        $before = $this->getJson($this->url('invoice-lines'))->assertOk()->json();
        $original = $before['data'][0];
        $this->assertSame(10000, $original['net_amount']);
        $this->assertSame(1000, $original['tax_amount']);
        $this->assertEquals(10, $original['tax_rate']);
        $this->assertTrue($original['taxable']);
        $this->assertSame('Adjustment', $original['line_type_label']);
        $this->assertSame($this->client->public_id, $original['client_id']);
        $this->assertSame($this->project->public_id, $original['project_id']);
        $this->assertSame('2026-01-01', $original['service_period_start']);
        $line = $invoice->lines->sole();
        app(InvoiceCorrectionService::class)->correct($invoice, $this->workspace, 1, false, null, 'Synthetic correction', [[
            'id' => $line->public_id, 'description' => 'Corrected synthetic work', 'quantity' => '2.0000', 'unit_amount' => 6000, 'tax_amount' => 1200,
        ]]);
        $this->travelTo(Carbon::parse('2026-01-05 12:20:00 UTC'));
        $after = $this->getJson($this->url('invoice-lines').'?'.http_build_query(['updated_since' => $before['meta']['updated_since']]))->assertOk()->json('data');
        $this->assertCount(2, $after);
        $byKind = collect($after)->keyBy('kind');
        $this->assertSame(-10000, $byKind['correction_reversal']['net_amount']);
        $this->assertSame(-1000, $byKind['correction_reversal']['tax_amount']);
        $this->assertSame($original['id'], $byKind['correction_reversal']['reverses_id']);
        $this->assertSame(12000, $byKind['correction']['net_amount']);
        $this->assertSame($original, $this->getJson($this->url('invoice-lines'))->json('data.2'));
        $this->assertSame(3, AccountingInvoiceEntry::query()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_cash_income_and_yearly_csv_json_reconcile_with_existing_finance_reads_and_mcp(): void
    {
        $invoice = $this->invoice();
        $this->travelTo(Carbon::parse('2026-01-08 12:00:00 UTC'));
        $payment = ClientInvoicePayment::query()->create(['workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id,
            'status' => 'succeeded', 'amount' => 11000, 'refunded_amount' => 1000, 'currency' => 'USD', 'method' => 'bank_transfer', 'received_on' => '2026-01-08']);
        $payment->forceFill(['provider_fee_amount' => 150])->save();
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00 UTC'));
        $first = $this->refund($payment, 'refund', 4000);
        $this->refund($payment, 'refund', 1000);
        $this->travelTo(Carbon::parse('2026-03-01 12:00:00 UTC'));
        $this->refund($payment, 'reversal', 4000, $first->id);
        $this->travelTo(Carbon::parse('2026-03-01 12:10:00 UTC'));
        $rows = $this->getJson($this->url('cash-income'))->assertOk()->json('data');
        $this->assertCount(4, $rows);
        $this->assertSame(9850, array_sum(array_column($rows, 'net_amount')));
        $existing = $this->getJson('/api/v1/workspaces/'.$this->workspace->public_id.'/invoice-payments?status=any')->assertOk()->json('data.0');
        $this->assertSame($existing['net_amount'] - $existing['provider_fee_amount'], array_sum(array_column($rows, 'net_amount')));
        $this->assertSame(0, array_sum(array_column($rows, 'unattributed_refund_amount')));
        $json = $this->getJson($this->url('income').'?year=2026')->assertOk()->json();
        $months = $json['data']['months'];
        $this->assertCount(12, $months);
        $this->assertSame(10000, $months[0]['invoiced_net']);
        $this->assertSame(1000, $months[0]['invoiced_tax']);
        $this->assertSame(11000, $months[0]['cash_received']);
        $this->assertSame(150, $months[0]['fees']);
        $this->assertSame(5000, $months[1]['refunds']);
        $this->assertSame(4000, $months[2]['refund_reversals']);
        $csv = $this->get($this->url('income').'?year=2026&format=csv')->assertOk()->streamedContent();
        $parsed = array_map(fn (string $row): array => str_getcsv($row, escape: ''), explode("\n", trim($csv)));
        $this->assertSame(array_keys($months[0]), array_shift($parsed));
        foreach ($months as $index => $month) {
            $this->assertSame(array_map(strval(...), array_values($month)), $parsed[$index]);
        }
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::FINANCE_READ]);
        $session = $this->initialize();
        $mcp = $this->callTool($session, 'finance.income.yearly', ['workspace_id' => $this->workspace->public_id, 'year' => 2026]);
        $this->assertFalse($mcp['result']['isError'], json_encode($mcp));
        $this->assertSame($json, $mcp['result']['structuredContent']);
    }

    public function test_processor_income_reconciles_by_effective_date_and_cannot_cross_workspaces(): void
    {
        $invoice = $this->invoice();
        $this->travelTo(Carbon::parse('2026-01-08 12:00:00 UTC'));
        $processor = ClientInvoicePayment::query()->create(['workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id,
            'status' => 'succeeded', 'amount' => 9000, 'refunded_amount' => 500, 'currency' => 'USD', 'method' => 'card',
            'provider' => 'stripe', 'received_on' => '2026-01-08']);
        $processor->forceFill(['provider_fee_amount' => 100])->save();
        $manual = ClientInvoicePayment::query()->create(['workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id,
            'status' => 'succeeded', 'amount' => 2000, 'refunded_amount' => 200, 'currency' => 'USD', 'method' => 'bank_transfer', 'received_on' => '2026-01-08']);
        $this->travelTo(Carbon::parse('2026-01-09 12:00:00 UTC'));
        $this->refund($manual, 'refund', 200);
        $movement = ProcessorMovement::query()->forceCreate(['workspace_id' => $this->workspace->id,
            'client_invoice_payment_id' => $processor->id, 'provider' => 'stripe', 'account_key' => 'synthetic-account',
            'provider_entry_identifier' => 'synthetic-refund-entry', 'kind' => 'refund', 'principal_minor' => -500,
            'fee_minor' => 10, 'net_minor' => -510, 'currency' => 'USD', 'provider_occurred_at' => now(),
            'occurred_on' => '2026-01-09', 'occurred_timezone' => 'UTC', 'date_basis' => 'stripe_balance_created']);
        $this->travelTo(Carbon::parse('2026-01-09 12:10:00 UTC'));
        $result = $this->getJson($this->url('cash-income'))->assertOk()->json();
        $this->assertCount(4, $result['data']);
        $this->assertSame(10190, array_sum(array_column($result['data'], 'net_amount')));
        $this->assertSame(0, array_sum(array_column($result['data'], 'unattributed_refund_amount')));
        $existing = $this->getJson('/api/v1/workspaces/'.$this->workspace->public_id.'/processor-movements')->assertOk()->json('data.0');
        $cashMovement = collect($result['data'])->firstWhere('source_id', $movement->public_id);
        $this->assertSame($existing['principal_minor'], $cashMovement['amount']);
        $this->assertSame($existing['fee_minor'], $cashMovement['fee_amount']);
        $this->assertSame($existing['net_minor'], $cashMovement['net_amount']);
        $day = $this->getJson($this->url('cash-income').'?from=2026-01-09&to=2026-01-09')->assertOk()->json('data');
        $this->assertCount(2, $day);
        $this->assertSame(-710, array_sum(array_column($day, 'net_amount')));
        $other = Workspace::query()->create(['name' => 'Other Cash Workspace', 'slug' => 'other-cash-workspace']);
        $other->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->getJson('/api/v1/workspaces/'.$other->public_id.'/accounting/cash-income')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/workspaces/'.$other->public_id.'/accounting/income?year=2026')->assertOk()
            ->assertJsonPath('data.months.0.cash_received', 0)->assertJsonPath('data.months.0.refunds', 0)->assertJsonPath('data.months.0.fees', 0);
        $old = collect($result['data'])->firstWhere('source_id', $manual->public_id);
        $manual->forceFill(['received_on' => '2026-01-07'])->save();
        $this->travelTo(Carbon::parse('2026-01-09 12:20:00 UTC'));
        $changed = $this->getJson($this->url('cash-income').'?'.http_build_query(['updated_since' => $result['meta']['updated_since']]))->assertOk()->json('data');
        $this->assertCount(1, $changed);
        $this->assertSame($old['id'], $changed[0]['id']);
        $this->assertNotSame($old['version'], $changed[0]['version']);
        $this->assertSame('2026-01-07', $changed[0]['effective_on']);
    }

    public function test_pagination_ties_and_cursors_are_scoped_to_operation_and_workspace(): void
    {
        $this->invoice();
        $this->invoice();
        $this->travelTo(Carbon::parse('2026-01-05 12:10:00 UTC'));
        $first = $this->getJson($this->url('invoice-lines').'?limit=1')->assertOk()->json();
        $this->assertCount(1, $first['data']);
        $this->assertIsString($first['meta']['next_cursor']);
        $second = $this->getJson($this->url('invoice-lines').'?'.http_build_query(['limit' => 1, 'cursor' => $first['meta']['next_cursor']]))->assertOk()->json();
        $this->assertNotSame($first['data'][0]['id'], $second['data'][0]['id']);
        $this->assertNull($second['meta']['next_cursor']);
        $this->getJson($this->url('cash-income').'?'.http_build_query(['updated_since' => $first['meta']['updated_since']]))->assertStatus(422);
        $this->getJson($this->url('invoice-lines').'?limit=101')->assertStatus(422);
        $this->getJson($this->url('invoice-lines').'?updated_since=invalid')->assertStatus(422);
        $other = Workspace::query()->create(['name' => 'Other Synthetic', 'slug' => 'other-synthetic']);
        $other->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        foreach (['invoice-lines', 'cash-income'] as $path) {
            $this->getJson('/api/v1/workspaces/'.$other->public_id.'/accounting/'.$path)->assertOk()->assertJsonCount(0, 'data');
        }
        $this->getJson('/api/v1/workspaces/'.$other->public_id.'/accounting/invoice-lines?'.http_build_query(['cursor' => $first['meta']['next_cursor']]))->assertStatus(422);
        $this->getJson('/api/v1/workspaces/'.$other->public_id.'/accounting/income?year=2026')->assertOk()->assertJsonPath('data.months.0.invoiced_net', 0);
    }

    public function test_finance_scope_without_manager_rights_is_refused_for_every_read(): void
    {
        $this->invoice();
        $viewer = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'member']);
        $this->actingAsMcp($viewer, [AgentApiScopes::MCP_USE, AgentApiScopes::FINANCE_READ]);
        foreach (['invoice-lines', 'cash-income', 'income?year=2026'] as $path) {
            $this->getJson($this->url($path))->assertForbidden();
        }
        $session = $this->initialize();
        $this->assertNotContains('finance.invoice_lines.list', $this->toolNames($session));
        $this->assertNotContains('finance.cash_income.list', $this->toolNames($session));
        $this->assertNotContains('finance.income.yearly', $this->toolNames($session));
    }

    public function test_voids_reverse_issued_lines_but_void_drafts_never_create_income(): void
    {
        $invoice = $this->invoice();
        $this->travelTo(Carbon::parse('2026-01-05 12:10:00 UTC'));
        app(InvoiceLifecycleService::class)->void($invoice, $this->workspace, 'Synthetic void');
        $this->travelTo(Carbon::parse('2026-01-05 12:20:00 UTC'));
        $rows = $this->getJson($this->url('invoice-lines'))->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame(0, array_sum(array_column($rows, 'net_amount')));
        $this->assertSame(0, array_sum(array_column($rows, 'tax_amount')));
        $this->assertSame($rows[1]['id'], $rows[0]['reverses_id']);
        $year = $this->getJson($this->url('income').'?year=2026')->assertOk()->json('data.months.0');
        $this->assertSame(0, $year['invoiced_net']);
        $draft = app(InvoiceLifecycleService::class)->createDraft($this->workspace, $this->client,
            ['invoice_number' => 'SYN-VOID-DRAFT', 'currency' => 'USD'],
            [['type' => 'adjustment', 'description' => 'Unissued synthetic draft', 'quantity' => '1', 'unit_amount' => 99, 'tax_amount' => 0]]);
        app(InvoiceLifecycleService::class)->void($draft, $this->workspace, 'Never issued');
        $this->assertSame(2, AccountingInvoiceEntry::query()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_a_historical_corrected_invoice_imports_original_and_reversing_records_once(): void
    {
        $invoice = ClientInvoice::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->client->id,
            'invoice_number' => 'SYN-HISTORY', 'status' => 'issued', 'document_revision' => 2,
            'issue_date' => '2026-01-05', 'issued_at' => now(), 'currency' => 'USD', 'subtotal_amount' => 1200, 'tax_amount' => 120, 'total_amount' => 1320, 'balance_amount' => 1320]);
        $line = $invoice->lines()->create(['workspace_id' => $this->workspace->id, 'type' => 'adjustment', 'description' => 'After', 'quantity' => '1', 'unit_amount' => 1200, 'tax_amount' => 120, 'total_amount' => 1320, 'sort_order' => 0]);
        ClientInvoiceCorrection::query()->create(['workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id,
            'from_revision' => 1, 'to_revision' => 2, 'reason' => 'Synthetic prior correction', 'line_diffs' => [[
                'line' => $line->public_id, 'before' => ['description' => 'Before', 'quantity' => '1.0000', 'unit_amount' => 1000, 'tax_amount' => 100],
                'after' => ['description' => 'After', 'quantity' => '1.0000', 'unit_amount' => 1200, 'tax_amount' => 120],
            ]]]);
        $this->travelTo(Carbon::parse('2026-01-05 12:10:00 UTC'));
        $first = $this->getJson($this->url('invoice-lines'))->assertOk()->json('data');
        $second = $this->getJson($this->url('invoice-lines'))->assertOk()->json('data');
        $this->assertSame($first, $second);
        $this->assertCount(3, $first);
        $this->assertSame([1200, -1000, 1000], array_column($first, 'net_amount'));
        $this->assertSame($first[2]['id'], $first[1]['reverses_id']);
    }

    private function invoice(): ClientInvoice
    {
        $lifecycle = app(InvoiceLifecycleService::class);
        $draft = $lifecycle->createDraft($this->workspace, $this->client,
            ['invoice_number' => 'SYN-'.Str::random(8), 'issue_date' => '2026-01-05', 'currency' => 'USD', 'service_period_start' => '2026-01-01', 'service_period_end' => '2026-01-31'],
            [['type' => 'adjustment', 'description' => 'Synthetic work', 'quantity' => '2', 'unit_amount' => 5000, 'tax_amount' => 1000, 'client_project_id' => $this->project->id]]);

        return $lifecycle->issue($draft, $this->workspace)->load('lines');
    }

    private function refund(ClientInvoicePayment $payment, string $kind, int $amount, ?int $reverses = null): ClientInvoicePaymentRefund
    {
        return ClientInvoicePaymentRefund::query()->create(['workspace_id' => $this->workspace->id,
            'client_invoice_payment_id' => $payment->id, 'kind' => $kind, 'amount' => $amount,
            'reason' => 'Synthetic refund evidence', 'reverses_refund_id' => $reverses]);
    }

    private function url(string $path): string
    {
        return '/api/v1/workspaces/'.$this->workspace->public_id.'/accounting/'.$path;
    }
}
