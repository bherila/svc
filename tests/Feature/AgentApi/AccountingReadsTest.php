<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceCorrection;
use App\Models\ClientInvoiceLine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mcp\Capability\Discovery\SchemaValidator;
use Tests\Concerns\BuildsAccountingFixtures;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * The accounting reads for bookkeeping and tax software over REST and MCP
 * (docs/ux-overhaul.md section 13): invoice lines with tax broken out, the
 * cash-basis income ledger and the yearly export. One synthetic fixture,
 * read at a fixed instant, so every entry and total is exact.
 */
final class AccountingReadsTest extends TestCase
{
    use BuildsAccountingFixtures;
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'finance:read'];

    private const string READ_AT = '2030-03-15 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
    }

    private function readAt(string $at = self::READ_AT): void
    {
        $this->travelTo($at);
    }

    /** @param  array<string, mixed>  $query */
    private function url(Workspace $workspace, string $segment, array $query = []): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/'.$segment.($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * Every entry of a listing, page by page, following cursors.
     *
     * @param  array<string, mixed>  $query
     * @return array{list<array<string, mixed>>, int, string} the entries, the number of pages and the pass's watermark
     */
    private function walk(Workspace $workspace, string $segment, array $query = []): array
    {
        $entries = [];
        $pages = 0;
        $cursor = null;
        do {
            $page = $this->getJson($this->url($workspace, $segment, $query + ($cursor === null ? [] : ['cursor' => $cursor])))->assertOk();
            $this->assertSchema($segment, $page->json());
            $entries = [...$entries, ...$page->json('data')];
            $cursor = $page->json('meta.next_cursor');
            $watermark = $page->json('meta.watermark');
            $pages++;
        } while ($cursor !== null && $pages < 50);

        return [$entries, $pages, $watermark];
    }

    /** @param  array<string, mixed>  $body */
    private function assertSchema(string $segment, array $body): void
    {
        $operation = ['invoice-lines' => 'finance.invoice_lines.list', 'cash-income' => 'finance.cash_income.list', 'income-export' => 'finance.income_export.get'][$segment];
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema(json_decode((string) json_encode($body)), AgentApiResponseSchemaCatalog::forOperation($operation)), $operation);
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function summary(array $entries): array
    {
        return array_map(static fn (array $entry): string => $entry['entry_type'].':'.$entry['line_type'].':'.$entry['net_amount'].':'.$entry['tax_amount'], $entries);
    }

    public function test_issued_lines_carry_their_tax_broken_out_and_voids_arrive_as_reversing_entries(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        $response = $this->getJson($this->url($mine['workspace'], 'invoice-lines'))->assertOk();
        $this->assertSchema('invoice-lines', $response->json());
        $entries = $response->json('data');

        // Newest first: the void, Beta's line, then Alpha's four lines, which share an instant and fall back to their ids. The draft is no entry.
        $this->assertSame([
            'void:adjustment:-40000:-3200', 'invoice_line:adjustment:40000:3200',
            'invoice_line:credit:-500:0', 'invoice_line:expense:1200:0', 'invoice_line:adjustment:3000:0', 'invoice_line:adjustment:10000:800',
        ], $this->summary($entries));
        $this->assertSame('2030-03-15T11:58:00+00:00', $response->json('meta.watermark'));
        $this->assertNull($response->json('meta.next_cursor'));

        [$void, $betaLine, $credit, $expense, $setup, $hosting] = $entries;
        $this->assertSame([
            'entry_type_label' => 'Invoice line', 'recorded_at' => '2030-02-01T15:00:00+00:00', 'effective_on' => '2030-02-01', 'reverses_entry_id' => null, 'correction_id' => null,
            'invoice_id' => $mine['invoice']->public_id, 'invoice_number' => 'SYN-A-1-mine', 'currency' => 'USD', 'client_id' => $mine['alpha']->public_id, 'project_id' => $mine['project']->public_id,
            'line_type' => 'adjustment', 'line_type_label' => 'Adjustment', 'description' => 'Hosting', 'net_amount' => 10000, 'tax_amount' => 800, 'total_amount' => 10800,
            'tax_rate' => '0.0800', 'taxable' => true, 'service_period_start' => null, 'service_period_end' => null,
        ], array_diff_key($hosting, ['id' => 0, 'entry_type' => 0, 'line_id' => 0]));
        $this->assertSame($hosting['line_id'], $hosting['id']);
        $this->assertNull($setup['project_id']);
        $this->assertSame([null, false], [$setup['tax_rate'], $setup['taxable']]);
        $this->assertSame(['expense', 'Expense'], [$expense['line_type'], $expense['line_type_label']]);
        $this->assertSame(['credit', 'Credit', -500, -500], [$credit['line_type'], $credit['line_type_label'], $credit['net_amount'], $credit['total_amount']]);

        // The void names the issued line it reverses and carries its amounts negated; the line itself is untouched.
        $this->assertSame($betaLine['id'], $void['reverses_entry_id']);
        $this->assertSame(['void', 'Void', '2030-02-20T18:00:00+00:00', '2030-02-20', -43200], [$void['entry_type'], $void['entry_type_label'], $void['recorded_at'], $void['effective_on'], $void['total_amount']]);
        $this->assertSame([$mine['voided']->public_id, $mine['beta']->public_id], [$void['invoice_id'], $void['client_id']]);
        $this->assertSame([40000, 3200, 43200], [$betaLine['net_amount'], $betaLine['tax_amount'], $betaLine['total_amount']]);
        $this->assertSame('void', $mine['voided']->status);
    }

    public function test_a_correction_made_after_a_sync_arrives_on_the_next_sync_as_a_new_reversing_record(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->travelTo('2030-03-14 10:00:00');
        $invoice = $this->accountingInvoice($mine['workspace'], $mine['alpha'], 'SYN-A-2', '2030-03-14', [['adjustment', 'Consulting', '1', 20000, 1600, null]], []);
        $line = $invoice->lines()->sole();

        // The first sync reads the line as issued.
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        [$first, , $watermark] = $this->walk($mine['workspace'], 'invoice-lines', ['client_id' => $mine['alpha']->public_id]);
        $this->assertSame(5, count($first));
        $issued = $first[0];
        $this->assertSame([$line->public_id, 'invoice_line', 20000, 1600, 21600, 'Consulting'], [$issued['id'], $issued['entry_type'], $issued['net_amount'], $issued['tax_amount'], $issued['total_amount'], $issued['description']]);

        // The invoice is then corrected in place, as the website and invoices.correct do.
        $this->travelTo('2030-03-16 09:00:00');
        $this->actingAsMcp($mine['owner'], [AgentApiScopes::BILLING_DELIVER]);
        $this->withHeader('Idempotency-Key', 'synthetic-correct-1')->postJson($this->url($mine['workspace'], 'invoices/'.$invoice->public_id.'/correct'), [
            'expected_version' => AgentApiVersion::for($invoice->fresh()), 'reason' => 'Synthetic rate correction.', 'confirm' => true,
            'lines' => [['id' => $line->public_id, 'description' => 'Consulting (revised)', 'quantity' => '1', 'unit_amount' => 24000, 'tax_amount' => 1920]],
        ])->assertOk();
        $correction = ClientInvoiceCorrection::query()->where('workspace_id', $mine['workspace']->id)->sole();
        $this->assertSame(25920, $line->fresh()->total_amount, 'The issued line itself was edited in place');

        // The next sync, from the first sync's watermark, carries exactly the correction as a new entry that names the line.
        $this->readAt('2030-03-16 12:00:00');
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        [$next] = $this->walk($mine['workspace'], 'invoice-lines', ['client_id' => $mine['alpha']->public_id, 'updated_since' => $watermark]);
        $this->assertCount(1, $next);
        $entry = $next[0];
        $this->assertEquals([
            'entry_type' => 'correction', 'entry_type_label' => 'Correction', 'reverses_entry_id' => $line->public_id, 'correction_id' => $correction->public_id,
            'recorded_at' => '2030-03-16T09:00:00+00:00', 'effective_on' => '2030-03-16', 'line_id' => $line->public_id, 'description' => 'Consulting (revised)',
            'net_amount' => 4000, 'tax_amount' => 320, 'total_amount' => 4320, 'tax_rate' => '0.0800', 'taxable' => true,
        ], array_intersect_key($entry, array_flip(['entry_type', 'entry_type_label', 'reverses_entry_id', 'correction_id', 'recorded_at', 'effective_on', 'line_id', 'description', 'net_amount', 'tax_amount', 'total_amount', 'tax_rate', 'taxable'])));
        $this->assertNotSame($line->public_id, $entry['id']);

        // The issued entry still reads as issued, and the line's entries add up to what the line now says.
        [$all] = $this->walk($mine['workspace'], 'invoice-lines', ['invoice_id' => $invoice->public_id]);
        $this->assertSame(['correction', 'invoice_line'], array_column($all, 'entry_type'));
        $this->assertSame([20000, 1600, 21600], [$all[1]['net_amount'], $all[1]['tax_amount'], $all[1]['total_amount']]);
        $this->assertSame($line->fresh()->total_amount, array_sum(array_column($all, 'total_amount')));
        $this->assertSame($line->fresh()->tax_amount, array_sum(array_column($all, 'tax_amount')));

        // The same sync over MCP.
        $mcp = $this->callTool($this->initialize(), 'finance.invoice_lines.list', ['workspace_id' => $mine['workspace']->public_id, 'client_id' => $mine['alpha']->public_id, 'updated_since' => $watermark]);
        $this->assertFalse($mcp['result']['isError'] ?? false, (string) json_encode($mcp));
        $this->assertSame([$entry['id']], array_column($mcp['result']['structuredContent']['data'], 'id'));
    }

    public function test_a_wording_only_correction_is_no_entry(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->travelTo('2030-03-14 10:00:00');
        $invoice = $this->accountingInvoice($mine['workspace'], $mine['alpha'], 'SYN-A-3', '2030-03-14', [['adjustment', 'Consulting', '1', 20000, 1600, null]], []);
        $line = $invoice->lines()->sole();
        $this->travelTo('2030-03-16 09:00:00');
        $this->actingAsMcp($mine['owner'], [AgentApiScopes::BILLING_DELIVER]);
        $this->withHeader('Idempotency-Key', 'synthetic-correct-2')->postJson($this->url($mine['workspace'], 'invoices/'.$invoice->public_id.'/correct'), [
            'expected_version' => AgentApiVersion::for($invoice->fresh()), 'reason' => 'Synthetic wording.', 'confirm' => true,
            'lines' => [['id' => $line->public_id, 'description' => 'Consulting services', 'quantity' => '1', 'unit_amount' => 20000, 'tax_amount' => 1600]],
        ])->assertOk();

        $this->readAt('2030-03-16 12:00:00');
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        [$entries] = $this->walk($mine['workspace'], 'invoice-lines', ['invoice_id' => $invoice->public_id]);

        $this->assertSame(['invoice_line'], array_column($entries, 'entry_type'));
        // As issued: the wording before the correction, the money unchanged.
        $this->assertSame(['Consulting', 21600], [$entries[0]['description'], $entries[0]['total_amount']]);
    }

    public function test_pages_follow_cursors_through_entries_that_share_an_instant_without_a_gap_or_a_repeat(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        [$whole] = $this->walk($mine['workspace'], 'invoice-lines');

        foreach ([1, 2, 4] as $perPage) {
            [$paged, $pages] = $this->walk($mine['workspace'], 'invoice-lines', ['per_page' => $perPage]);
            $this->assertSame(array_column($whole, 'id'), array_column($paged, 'id'), 'per_page='.$perPage);
            $this->assertSame((int) ceil(6 / $perPage), $pages, 'per_page='.$perPage);
        }
        $first = $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['per_page' => 2]))->assertOk();
        $this->assertCount(2, $first->json('data'));
        $this->assertIsString($first->json('meta.next_cursor'));
        // A page that ends exactly on the last entry has no cursor.
        $this->assertNull($this->getJson($this->url($mine['workspace'], 'invoice-lines', ['per_page' => 6]))->assertOk()->json('meta.next_cursor'));
        $this->assertCount(6, $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['per_page' => 100]))->assertOk()->json('data'));
    }

    public function test_updated_since_is_inclusive_and_the_filters_narrow_the_ledger(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        $since = fn (string $at, array $more = []): array => array_column($this->getJson($this->url($mine['workspace'], 'invoice-lines', ['updated_since' => $at] + $more))->assertOk()->json('data'), 'entry_type');
        $this->assertSame(['void', 'invoice_line'], $since('2030-02-10T15:00:00Z'));
        $this->assertSame(['void'], $since('2030-02-10T15:00:01Z'));
        $this->assertSame(['void'], $since('2030-02-20T18:00:00Z'));
        $this->assertSame([], $since('2030-02-20T18:00:01Z'));
        // Entries never change, so the immutable filters may follow updated_since.
        $this->assertSame(['void'], $since('2030-01-01T00:00:00Z', ['entry_type' => 'void']));
        $this->assertSame(['void', 'invoice_line'], $since('2030-01-01T00:00:00Z', ['client_id' => $mine['beta']->public_id]));
        $this->assertSame(['void', 'invoice_line'], $since('2030-01-01T00:00:00Z', ['invoice_id' => $mine['voided']->public_id]));

        $effective = fn (array $query): array => array_column($this->getJson($this->url($mine['workspace'], 'invoice-lines', $query))->assertOk()->json('data'), 'effective_on');
        $this->assertSame(['2030-02-20', '2030-02-10'], $effective(['from' => '2030-02-02']));
        $this->assertSame(['2030-02-01', '2030-02-01', '2030-02-01', '2030-02-01'], $effective(['to' => '2030-02-09']));
        $this->assertSame(['2030-02-10'], $effective(['from' => '2030-02-10', 'to' => '2030-02-10', 'entry_type' => 'invoice_line']));
        $this->assertSame(['2030-02-20'], $effective(['from' => '2030-02-20', 'to' => '2030-02-20']));
        $this->assertSame([], $effective(['from' => '2030-02-21']));
    }

    public function test_the_window_of_a_pass_is_fixed_by_its_watermark(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        // An entry newer than the watermark is left for the next pass.
        $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['watermark' => '2030-02-10T15:00:00Z']))->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('meta.watermark', '2030-02-10T15:00:00+00:00');
        $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['watermark' => '2030-03-15T11:58:01Z']))->assertUnprocessable()->assertJsonValidationErrors('watermark');

        // A cursor carries its watermark: sending another with it is refused.
        $cursor = $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['per_page' => 2]))->assertOk()->json('meta.next_cursor');
        $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['cursor' => $cursor, 'watermark' => '2030-02-10T15:00:00Z']))->assertUnprocessable();
        $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['cursor' => $cursor, 'watermark' => '2030-03-15T11:58:00Z']))->assertOk();
    }

    public function test_cursors_are_bound_to_their_workspace_and_query(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $theirs = $this->accountingWorkspace('theirs', 7);
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        $cursor = $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['per_page' => 2]))->assertOk()->json('meta.next_cursor');

        $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['cursor' => $cursor, 'per_page' => 2]))->assertOk();
        $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['cursor' => $cursor, 'entry_type' => 'void']))->assertUnprocessable();
        $this->getJson($this->url($mine['workspace'], 'cash-income', ['cursor' => $cursor]))->assertUnprocessable();
        $this->getJson($this->url($mine['workspace'], 'invoice-lines', ['cursor' => 'not-a-cursor']))->assertUnprocessable();
        $this->actingAsMcp($theirs['owner'], self::SCOPES);
        $this->getJson($this->url($theirs['workspace'], 'invoice-lines', ['cursor' => $cursor]))->assertUnprocessable();
    }

    public function test_invalid_parameters_are_refused_naming_them(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        foreach ([
            ['invoice-lines', ['per_page' => 101], 'per_page'], ['invoice-lines', ['per_page' => 0], 'per_page'], ['invoice-lines', ['page' => 2], 'page'],
            ['invoice-lines', ['entry_type' => 'payment'], 'entry_type'], ['cash-income', ['entry_type' => 'void'], 'entry_type'],
            ['invoice-lines', ['from' => '2030-02-30'], 'from'], ['invoice-lines', ['from' => '2030-02-02', 'to' => '2030-02-01'], 'to'],
            ['cash-income', ['from' => '2030-02-01', 'updated_since' => '2030-01-01T00:00:00Z'], 'from'],
            ['cash-income', ['to' => '2030-02-01', 'watermark' => '2030-01-01T00:00:00Z'], 'to'],
            ['income-export', [], 'year'], ['income-export', ['year' => 1999], 'year'], ['income-export', ['year' => 2030, 'month' => 2], 'month'],
        ] as [$segment, $query, $field]) {
            $this->getJson($this->url($mine['workspace'], $segment, $query))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_cash_income_is_a_ledger_of_payments_and_the_signed_entries_that_follow_them(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        $response = $this->getJson($this->url($mine['workspace'], 'cash-income'))->assertOk();
        $this->assertSchema('cash-income', $response->json());
        $entries = $response->json('data');

        // Newest first by when each was recorded; the two movements and the Stripe payment share an instant and fall back to their source.
        $this->assertSame(['fee', 'refund', 'payment', 'refund_reversal', 'payment', 'refund'], array_column($entries, 'entry_type'));
        $byId = array_column($entries, null, 'id');

        $manual = $byId[$mine['manual']->public_id];
        $this->assertSame([
            'version' => AgentApiVersion::for($mine['manual']->fresh()), 'entry_type' => 'payment', 'entry_type_label' => 'Payment', 'recorded_at' => '2030-03-03T15:00:00+00:00', 'effective_on' => '2030-02-04',
            'amount_minor' => 10000, 'fee_minor' => null, 'net_minor' => 10000, 'currency' => 'USD', 'payment_id' => $mine['manual']->public_id, 'reverses_entry_id' => null,
            'invoice_id' => $mine['invoice']->public_id, 'invoice_number' => 'SYN-A-1-mine', 'client_id' => $mine['alpha']->public_id, 'provider' => null, 'method' => 'bank_transfer',
            'status_label' => 'Succeeded', 'refunded_amount_minor' => 2000, 'needs_review' => false,
        ], array_diff_key($manual, ['id' => 0]));

        $stripe = $byId[$mine['stripe']->public_id];
        $this->assertSame([20000, 610, 19390, 'stripe', 5000, '2030-02-05'], [$stripe['amount_minor'], $stripe['fee_minor'], $stripe['net_minor'], $stripe['provider'], $stripe['refunded_amount_minor'], $stripe['effective_on']]);

        // A refund recorded by hand is money out on the day it was recorded in the workspace calendar; its reversal names it.
        $refund = $byId[$mine['refund']->public_id];
        $reversal = $byId[$mine['reversal']->public_id];
        $this->assertSame(['refund', -2500, '2030-03-01', '2030-03-02T02:30:00+00:00', $mine['manual']->public_id, null], [$refund['entry_type'], $refund['amount_minor'], $refund['effective_on'], $refund['recorded_at'], $refund['payment_id'], $refund['reverses_entry_id']]);
        $this->assertSame(['refund_reversal', 'Refund reversal', 500, '2030-03-03', $refund['id']], [$reversal['entry_type'], $reversal['entry_type_label'], $reversal['amount_minor'], $reversal['effective_on'], $reversal['reverses_entry_id']]);

        // The processor's own refund and fee, dated by their occurrence.
        [$processorRefund, $processorFee] = $mine['movements'];
        $this->assertSame(['refund', -5000, 0, -5000, '2030-03-04', 'stripe'], [$byId[$processorRefund->public_id]['entry_type'], $byId[$processorRefund->public_id]['amount_minor'], $byId[$processorRefund->public_id]['fee_minor'], $byId[$processorRefund->public_id]['net_minor'], $byId[$processorRefund->public_id]['effective_on'], $byId[$processorRefund->public_id]['provider']]);
        $this->assertSame(['fee', 'Fee', 0, 300, -300], [$byId[$processorFee->public_id]['entry_type'], $byId[$processorFee->public_id]['entry_type_label'], $byId[$processorFee->public_id]['amount_minor'], $byId[$processorFee->public_id]['fee_minor'], $byId[$processorFee->public_id]['net_minor']]);
    }

    public function test_cash_filters_narrow_by_type_date_client_and_invoice(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        $types = fn (array $query): array => array_column($this->walk($mine['workspace'], 'cash-income', $query)[0], 'entry_type');

        $this->assertSame(['payment', 'payment'], $types(['entry_type' => 'payment']));
        $this->assertSame(['refund', 'refund'], $types(['entry_type' => 'refund']));
        $this->assertSame(['refund_reversal'], $types(['entry_type' => 'refund_reversal']));
        $this->assertSame(['fee'], $types(['entry_type' => 'fee']));
        $this->assertSame([], $types(['entry_type' => 'dispute_debit']));
        // Dates are effective dates: the hand refund of March 1 (21:30 in New York on the 1st) is inside March 1.
        $this->assertSame(['payment', 'payment'], $types(['from' => '2030-02-04', 'to' => '2030-02-05']));
        $this->assertSame(['payment'], $types(['from' => '2030-02-05', 'to' => '2030-02-05']));
        $this->assertSame(['refund'], $types(['from' => '2030-03-01', 'to' => '2030-03-01']));
        $this->assertSame(['fee', 'refund'], $types(['from' => '2030-03-04']));
        $this->assertSame(['refund'], $types(['from' => '2030-03-01', 'to' => '2030-03-02']));
        $this->assertCount(6, $this->walk($mine['workspace'], 'cash-income', ['client_id' => $mine['alpha']->public_id])[0]);
        $this->assertSame([], $this->walk($mine['workspace'], 'cash-income', ['client_id' => $mine['beta']->public_id])[0]);
        $this->assertCount(6, $this->walk($mine['workspace'], 'cash-income', ['invoice_id' => $mine['invoice']->public_id])[0]);
        $this->assertSame([], $this->walk($mine['workspace'], 'cash-income', ['invoice_id' => $mine['voided']->public_id])[0]);
        // Entries of types and clients that never change may follow updated_since.
        $this->assertCount(6, $this->walk($mine['workspace'], 'cash-income', ['updated_since' => '2030-01-01T00:00:00Z', 'client_id' => $mine['alpha']->public_id])[0]);
        $this->assertSame(['fee'], $types(['updated_since' => '2030-03-04T15:00:00Z', 'entry_type' => 'fee']));
    }

    public function test_cash_pages_follow_cursors_without_a_gap_or_a_repeat(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        [$whole] = $this->walk($mine['workspace'], 'cash-income');

        $this->assertCount(6, $whole);
        foreach ([1, 2, 5] as $perPage) {
            [$paged, $pages] = $this->walk($mine['workspace'], 'cash-income', ['per_page' => $perPage]);
            $this->assertSame(array_column($whole, 'id'), array_column($paged, 'id'), 'per_page='.$perPage);
            $this->assertSame((int) ceil(6 / $perPage), $pages);
        }
    }

    public function test_the_cash_ledger_reconciles_with_the_existing_finance_reads(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        [$cash] = $this->walk($mine['workspace'], 'cash-income');

        // What finance.invoice_payments.list says each payment is worth net of refunds is the ledger's payments less its refunds.
        $payments = $this->getJson($this->url($mine['workspace'], 'invoice-payments', ['status' => 'any']))->assertOk()->json('data');
        $received = array_sum(array_column(array_filter($cash, static fn (array $entry): bool => in_array($entry['entry_type'], ['payment', 'refund', 'refund_reversal'], true)), 'amount_minor'));
        $this->assertSame(array_sum(array_column($payments, 'net_amount')), $received);
        $this->assertSame(23000, $received);
        // Each payment on its own.
        foreach ($payments as $payment) {
            $own = array_filter($cash, static fn (array $entry): bool => $entry['payment_id'] === $payment['id'] && in_array($entry['entry_type'], ['payment', 'refund', 'refund_reversal'], true));
            $this->assertSame($payment['net_amount'], array_sum(array_column($own, 'amount_minor')), $payment['id']);
        }

        // The fees are the processor's fee on each payment and the fees its movements report.
        $movements = $this->getJson($this->url($mine['workspace'], 'processor-movements'))->assertOk()->json('data');
        $this->assertSame(
            array_sum(array_column($payments, 'provider_fee_amount')) + array_sum(array_column($movements, 'fee_minor')),
            array_sum(array_column($cash, 'fee_minor')),
        );
        // And every movement of the existing read is one entry of the ledger, with the same signed money.
        $byId = array_column($cash, null, 'id');
        foreach ($movements as $movement) {
            $this->assertSame([$movement['principal_minor'], $movement['fee_minor'], $movement['net_minor'], $movement['kind']], [$byId[$movement['id']]['amount_minor'], $byId[$movement['id']]['fee_minor'], $byId[$movement['id']]['net_minor'], $byId[$movement['id']]['entry_type']]);
        }
    }

    public function test_the_yearly_export_is_the_sum_of_the_two_ledgers_and_csv_and_json_agree(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        $response = $this->getJson($this->url($mine['workspace'], 'income-export', ['year' => 2030]))->assertOk();
        $this->assertSchema('income-export', $response->json());
        $data = $response->json('data');
        $this->assertSame([2030, 'USD'], [$data['year'], $data['currency']]);
        $this->assertSame(['2030-01', '2030-02', '2030-03', '2030-04', '2030-05', '2030-06', '2030-07', '2030-08', '2030-09', '2030-10', '2030-11', '2030-12'], array_column($data['months'], 'month'));

        // February: Alpha (net 137.00, tax 8.00) and Beta's invoice, voided in the same month; 300.00 received less 6.10 of fees.
        $this->assertSame([
            'month' => '2030-02', 'invoiced_net_minor' => 13700, 'invoiced_tax_minor' => 800, 'invoiced_total_minor' => 14500, 'expenses_passed_through_minor' => 1200,
            'cash_received_minor' => 30000, 'refunds_minor' => 0, 'disputes_minor' => 0, 'fees_minor' => 610, 'net_cash_minor' => 29390,
        ], $data['months'][1]);
        // March: the hand refund (net of its reversal) and the processor's refund, and its extra fee.
        $this->assertSame([
            'month' => '2030-03', 'invoiced_net_minor' => 0, 'invoiced_tax_minor' => 0, 'invoiced_total_minor' => 0, 'expenses_passed_through_minor' => 0,
            'cash_received_minor' => 0, 'refunds_minor' => -7000, 'disputes_minor' => 0, 'fees_minor' => 300, 'net_cash_minor' => -7300,
        ], $data['months'][2]);
        $this->assertSame([
            'invoiced_net_minor' => 13700, 'invoiced_tax_minor' => 800, 'invoiced_total_minor' => 14500, 'expenses_passed_through_minor' => 1200,
            'cash_received_minor' => 30000, 'refunds_minor' => -7000, 'disputes_minor' => 0, 'fees_minor' => 910, 'net_cash_minor' => 22090,
        ], $data['totals']);
        $this->assertSame(['2030-03-15', 'America/New_York', 1], [$data['assumptions']['as_of'], $data['assumptions']['timezone'], $data['assumptions']['method_version']]);

        // The year is the lists summed.
        [$lines] = $this->walk($mine['workspace'], 'invoice-lines', ['from' => '2030-01-01', 'to' => '2030-12-31']);
        [$cash] = $this->walk($mine['workspace'], 'cash-income', ['from' => '2030-01-01', 'to' => '2030-12-31']);
        $this->assertSame([$data['totals']['invoiced_net_minor'], $data['totals']['invoiced_tax_minor']], [array_sum(array_column($lines, 'net_amount')), array_sum(array_column($lines, 'tax_amount'))]);
        $this->assertSame($data['totals']['cash_received_minor'] + $data['totals']['refunds_minor'], array_sum(array_column($cash, 'amount_minor')));
        $this->assertSame($data['totals']['fees_minor'], array_sum(array_column($cash, 'fee_minor')));

        // The CSV carries exactly the JSON's figures, streamed as a download.
        $csv = $this->get($this->url($mine['workspace'], 'income-export/csv', ['year' => 2030]))->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('income-2030.csv', (string) $csv->headers->get('Content-Disposition'));
        $rows = array_map(static fn (string $line): array => explode(',', $line), explode("\r\n", rtrim($csv->streamedContent(), "\r\n")));
        $this->assertCount(14, $rows);
        $columns = array_keys($data['totals']);
        $this->assertSame(['month', ...$columns], $rows[0]);
        foreach ($data['months'] as $index => $month) {
            $this->assertSame([$month['month'], ...array_map(static fn (string $column): string => (string) $month[$column], $columns)], $rows[$index + 1]);
        }
        $this->assertSame(['total', ...array_map(static fn (string $column): string => (string) $data['totals'][$column], $columns)], $rows[13]);

        // MCP returns the same JSON.
        $mcp = $this->callTool($this->initialize(), 'finance.income_export.get', ['workspace_id' => $mine['workspace']->public_id, 'year' => 2030]);
        $this->assertFalse($mcp['result']['isError'] ?? false, (string) json_encode($mcp));
        $this->assertSame($response->json(), $mcp['result']['structuredContent']);

        // A year with nothing in it is twelve empty months.
        $empty = $this->getJson($this->url($mine['workspace'], 'income-export', ['year' => 2031]))->assertOk()->json('data');
        $this->assertSame(array_fill_keys($columns, 0), $empty['totals']);
    }

    public function test_rest_and_mcp_return_the_same_entries(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        $session = $this->initialize();

        foreach ([
            ['finance.invoice_lines.list', 'invoice-lines', ['per_page' => 3, 'client_id' => $mine['alpha']->public_id]],
            ['finance.cash_income.list', 'cash-income', ['per_page' => 4, 'entry_type' => 'refund']],
            ['finance.cash_income.list', 'cash-income', ['per_page' => 2]],
        ] as [$tool, $segment, $arguments]) {
            $rest = $this->getJson($this->url($mine['workspace'], $segment, $arguments))->assertOk();
            $mcp = $this->callTool($session, $tool, ['workspace_id' => $mine['workspace']->public_id, ...$arguments]);
            $this->assertFalse($mcp['result']['isError'] ?? false, (string) json_encode($mcp));
            // The cursor is encrypted afresh each time; everything else is the same page.
            $this->assertSame($rest->json('meta.next_cursor') === null, $mcp['result']['structuredContent']['meta']['next_cursor'] === null);
            $this->assertSame(
                array_replace_recursive($rest->json(), ['meta' => ['next_cursor' => null]]),
                array_replace_recursive($mcp['result']['structuredContent'], ['meta' => ['next_cursor' => null]]),
                $tool,
            );
        }
    }

    public function test_one_workspace_never_reads_anothers_books(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $theirs = $this->accountingWorkspace('theirs', 7);
        $this->readAt();
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        foreach (['invoice-lines', 'cash-income'] as $segment) {
            [$entries] = $this->walk($mine['workspace'], $segment);
            $this->assertNotSame([], $entries);
            $foreign = [$theirs['invoice']->public_id, $theirs['voided']->public_id, $theirs['alpha']->public_id, $theirs['beta']->public_id, $theirs['manual']->public_id, $theirs['stripe']->public_id];
            foreach ($entries as $entry) {
                $this->assertSame([], array_intersect($foreign, [$entry['invoice_id'], $entry['client_id'], $entry['payment_id'] ?? '', $entry['id']]), $segment);
            }
            // Another workspace's ids narrow nothing into view: a client is not found, and an invoice matches no entry.
            $this->getJson($this->url($mine['workspace'], $segment, ['client_id' => $theirs['alpha']->public_id]))->assertNotFound();
            $this->assertSame([], $this->walk($mine['workspace'], $segment, ['invoice_id' => $theirs['invoice']->public_id])[0]);
            // And the workspace itself is not found, to the owner of another.
            $this->getJson($this->url($theirs['workspace'], $segment))->assertNotFound();
        }
        $this->getJson($this->url($theirs['workspace'], 'income-export', ['year' => 2030]))->assertNotFound();
        $this->getJson($this->url($theirs['workspace'], 'income-export/csv', ['year' => 2030]))->assertNotFound();
        $mcp = $this->callTool($this->initialize(), 'finance.invoice_lines.list', ['workspace_id' => $theirs['workspace']->public_id]);
        $this->assertTrue($mcp['result']['isError'] ?? false);

        // Each workspace's yearly figures are its own: the other's amounts are seven times these.
        $mineYear = $this->getJson($this->url($mine['workspace'], 'income-export', ['year' => 2030]))->assertOk()->json('data.totals');
        $this->actingAsMcp($theirs['owner'], self::SCOPES);
        $theirYear = $this->getJson($this->url($theirs['workspace'], 'income-export', ['year' => 2030]))->assertOk()->json('data.totals');
        $this->assertSame(13700, $mineYear['invoiced_net_minor']);
        $this->assertSame(13700 * 7, $theirYear['invoiced_net_minor']);
        $this->assertSame(22090 * 7, $theirYear['net_cash_minor']);
        // A line belongs to the workspace of its invoice: the other tenant's lines are none of ours.
        $this->assertSame(0, ClientInvoiceLine::query()->where('workspace_id', $mine['workspace']->id)->whereIn('client_invoice_id', ClientInvoice::query()->where('workspace_id', $theirs['workspace']->id)->pluck('id'))->count());
    }

    public function test_only_a_workspace_owner_or_admin_with_finance_read_may_read(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->readAt();
        $segments = ['invoice-lines', 'cash-income', 'income-export?year=2030', 'income-export/csv?year=2030'];

        // A member who manages nothing is refused, whatever the scope.
        $member = User::factory()->create();
        $mine['workspace']->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, self::SCOPES);
        foreach ($segments as $segment) {
            $this->getJson('/api/v1/workspaces/'.$mine['workspace']->public_id.'/'.$segment)->assertForbidden();
        }
        $mcp = $this->callTool($this->initialize(), 'finance.invoice_lines.list', ['workspace_id' => $mine['workspace']->public_id]);
        // The tool is not offered to someone who manages nothing.
        $this->assertTrue(isset($mcp['error']) || ($mcp['result']['isError'] ?? false), (string) json_encode($mcp));

        // So is a member holding the Sanctum finance token, which lets any member read the payment feeds.
        Auth::forgetGuards();
        $token = $member->createToken('synthetic-finance', ['finance.read'], now()->addHour())->plainTextToken;
        foreach ($segments as $segment) {
            $this->withToken($token)->getJson('/api/v1/workspaces/'.$mine['workspace']->public_id.'/'.$segment)->assertForbidden();
        }
        $this->withToken($token)->getJson('/api/v1/workspaces/'.$mine['workspace']->public_id.'/invoice-payments')->assertOk();

        // The owner, without finance:read, is refused too.
        $this->flushHeaders();
        Auth::forgetGuards();
        $this->actingAsMcp($mine['owner'], ['mcp:use', 'identity:read', 'payments:read', 'billing:read', 'revenue:read']);
        foreach ($segments as $segment) {
            $this->getJson('/api/v1/workspaces/'.$mine['workspace']->public_id.'/'.$segment)->assertForbidden();
        }

        // An admin may read.
        $admin = User::factory()->create();
        $mine['workspace']->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
        Auth::forgetGuards();
        $this->actingAsMcp($admin, self::SCOPES);
        foreach ($segments as $segment) {
            $this->getJson('/api/v1/workspaces/'.$mine['workspace']->public_id.'/'.$segment)->assertOk();
        }
    }

    public function test_the_tools_belong_to_the_finance_toolset_and_appear_when_it_is_enabled(): void
    {
        $mine = $this->accountingWorkspace('mine');
        $this->actingAsMcp($mine['owner'], self::SCOPES);
        $tools = ['finance.invoice_lines.list', 'finance.cash_income.list', 'finance.income_export.get'];

        $session = $this->initializeProgressive();
        foreach ($tools as $tool) {
            $this->assertNotContains($tool, $this->toolNames($session));
        }
        $listed = $this->messages($this->mcp(['jsonrpc' => '2.0', 'id' => 100, 'method' => 'tools/call', 'params' => ['name' => 'toolsets.list', 'arguments' => (object) []]], $session));
        $finance = collect($listed[0]['result']['structuredContent']['data']['toolsets'])->firstWhere('name', 'finance');
        $this->assertNotNull($finance);

        $enabled = $this->messages($this->mcp(['jsonrpc' => '2.0', 'id' => 100, 'method' => 'tools/call', 'params' => ['name' => 'toolsets.enable', 'arguments' => ['toolsets' => ['finance']]]], $session));
        $this->assertFalse($enabled[array_key_last($enabled)]['result']['isError'] ?? false);
        foreach ($tools as $tool) {
            $this->assertContains($tool, $this->toolNames($session));
        }
        // The CSV download is REST only.
        $this->assertNotContains('finance.income_export.csv', $this->toolNames($this->initialize()));
    }
}
