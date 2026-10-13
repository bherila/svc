<?php

namespace Tests\Feature\Square;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceSquareLink;
use App\Models\SquareInvoiceSnapshot;
use App\Models\SquareSyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\Mirror\SquareMirrorSync;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mcp\Capability\Discovery\SchemaValidator;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\FakesSquare;
use Tests\Concerns\ServesSyntheticSquareAccount;
use Tests\TestCase;

final class SquareCandidateImportApiTest extends TestCase
{
    use CallsMcp;
    use FakesSquare;
    use RefreshDatabase;
    use ServesSyntheticSquareAccount;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic candidate workspace', 'slug' => 'synthetic-candidates']);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->connectSquare($this->workspace);
        $first = self::squareInvoice('inv_fixture_1');
        $next = self::squareInvoice('inv_fixture_2', '000001-R-0002');
        $next['payment_requests'][0]['due_date'] = '2026-11-15';
        $this->serveSquareAccount(invoices: [$first, $next]);
        $sync = app(SquareMirrorSync::class);
        $run = $sync->perform($this->workspace, $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE));
        $this->assertSame('completed', $run->status);
        $this->actingAsMcp($this->owner, ['mcp:use', 'identity:read', 'billing:read', 'billing:write']);
    }

    public function test_candidates_require_a_confirmed_mapping_and_rest_and_mcp_read_the_same_ranked_mirror(): void
    {
        $company = $this->company();
        $invoice = $this->invoice($company);
        $url = $this->candidatesUrl($invoice);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        app(SquareLinkService::class)->setCustomer($this->workspace, $company, $this->owner, AgentApiVersion::for($company), 'cust_fixture_a');
        $calls = count(Http::recorded());

        $rest = $this->getJson($url)->assertOk()->json();
        $this->assertSame(['inv_fixture_1', 'inv_fixture_2'], array_column($rest['data'], 'square_invoice_id'));
        $this->assertSame([90, 50], array_column($rest['data'], 'score'));
        $this->assertTrue($rest['data'][0]['suggested']);
        $this->assertFalse($rest['meta']['ambiguous']);
        $this->assertSame(0, InvoiceSquareLink::query()->count(), 'Suggestions never write a link.');
        $session = $this->initialize();
        $this->assertContains('invoices.square_candidates', $this->toolNames($session));
        $mcp = $this->callTool($session, 'invoices.square_candidates', ['workspace_id' => $this->workspace->public_id, 'invoice_id' => $invoice->public_id]);
        $this->assertFalse($mcp['result']['isError'] ?? false, json_encode($mcp));
        $this->assertSame($rest, $mcp['result']['structuredContent']);
        $this->assertSchema('invoices.square_candidates', $rest);
        $this->assertCount($calls, Http::recorded());
    }

    public function test_candidates_exclude_canceled_other_currency_and_linked_elsewhere_and_validate_queries(): void
    {
        $company = $this->company();
        $invoice = $this->invoice($company);
        app(SquareLinkService::class)->setCustomer($this->workspace, $company, $this->owner, AgentApiVersion::for($company), 'cust_fixture_a');
        $snapshot = SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->where('square_invoice_id', 'inv_fixture_1')->firstOrFail();
        $snapshot->forceFill(['status' => 'CANCELED'])->save();
        $next = SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->where('square_invoice_id', 'inv_fixture_2')->firstOrFail();
        $next->forceFill(['currency' => 'EUR'])->save();

        $empty = $this->getJson($this->candidatesUrl($invoice))->assertOk();
        $empty->assertJsonCount(0, 'data')->assertJsonPath('meta.excluded.canceled', 1)->assertJsonPath('meta.excluded.other_currency', 1);
        $this->getJson($this->candidatesUrl($invoice).'?include_canceled=true')->assertOk()->assertJsonPath('data.0.square_invoice_id', 'inv_fixture_1');
        $snapshot->forceFill(['status' => 'UNPAID'])->save();
        $other = $this->invoice($company, 'SYNTHETIC-OTHER');
        app(SquareLinkService::class)->link($this->workspace, $other, $this->owner, AgentApiVersion::for($other), new SquareInvoiceReference('inv_fixture_1', '000001-R-0001', 'USD', SquareMatchMethod::Manual), false);
        $this->getJson($this->candidatesUrl($invoice))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.excluded.linked_elsewhere.0.linked_to_invoice_id', $other->public_id);
        foreach (['limit=26', 'limit=0', 'include_canceled=maybe', 'include_canceled[]=true'] as $query) {
            $this->getJson($this->candidatesUrl($invoice).'?'.$query)->assertStatus(422);
        }
    }

    public function test_closed_history_does_not_change_the_currency_of_current_receivables(): void
    {
        $source = SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->where('square_invoice_id', 'inv_fixture_1')->firstOrFail();
        $history = $source->replicate();
        $history->forceFill(['square_invoice_id' => 'inv_fixture_closed_history', 'status' => 'PAID', 'currency' => 'EUR', 'total_amount' => 100, 'paid_amount' => 100])->save();
        $facts = $this->getJson($this->squareUrl('customers/unlinked'))->assertOk()->json('data.0');
        $this->assertSame([24_690, 'USD', 2], [$facts['open_amount'], $facts['currency'], $facts['open_invoice_count']]);
        $history->forceFill(['status' => 'UNPAID', 'paid_amount' => 0])->save();
        $mixed = $this->getJson($this->squareUrl('customers/unlinked'))->assertOk()->json('data.0');
        $this->assertNull($mixed['open_amount']);
        $this->assertNull($mixed['currency']);
    }

    public function test_unlinked_customers_have_balances_and_disappear_after_an_explicit_idempotent_import(): void
    {
        $calls = count(Http::recorded());
        $url = $this->squareUrl('customers/unlinked');
        $listed = $this->getJson($url)->assertOk()->json();
        $this->assertSame(['cust_fixture_a'], array_column($listed['data'], 'customer_id'));
        $this->assertSame([2, 24_690, ['000001']], [$listed['data'][0]['open_invoice_count'], $listed['data'][0]['open_amount'], $listed['data'][0]['series_keys']]);
        $this->assertSchema('square.customers.unlinked', $listed);
        $session = $this->initialize();
        $mcp = $this->callTool($session, 'square.customers.unlinked', ['workspace_id' => $this->workspace->public_id]);
        $this->assertSame($listed, $mcp['result']['structuredContent']);
        $this->getJson($url.'?with_open_balance=false')->assertOk()->assertJsonCount(0, 'data');

        $body = ['confirm' => true, 'company_name' => 'Synthetic imported client', 'billing_email' => 'billing@imported.example.test', 'series_key' => '000001'];
        $first = $this->postJson($this->squareUrl('customers/cust_fixture_a/import'), $body, ['Idempotency-Key' => 'synthetic-import'])->assertOk()->json();
        $retry = $this->postJson($this->squareUrl('customers/cust_fixture_a/import'), $body, ['Idempotency-Key' => 'synthetic-import'])->assertOk()->json();
        $this->assertSame($first, $retry);
        $this->assertSchema('square.import_customer', $first);
        $company = ClientCompany::query()->where('workspace_id', $this->workspace->id)->where('public_id', $first['data']['company_id'])->firstOrFail();
        $agreement = ClientAgreement::query()->where('workspace_id', $this->workspace->id)->where('public_id', $first['data']['agreement_id'])->firstOrFail();
        $this->assertSame(['draft', 'monthly', 'USD', 12_345, '000001'], [$agreement->status, $agreement->billing_cadence, $agreement->currency, $agreement->retainer_amount, $agreement->square_series_key]);
        $this->assertSame($company->id, $agreement->client_company_id);
        $this->assertSame('billing@imported.example.test', $company->billing_email);
        $this->assertSame(1, CompanySquareCustomer::query()->where('workspace_id', $this->workspace->id)->where('square_customer_id', 'cust_fixture_a')->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('workspace_id', $this->workspace->id)->where('action', 'company.imported_from_square')->count());
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->squareUrl('customers/cust_fixture_a/import'), $body, ['Idempotency-Key' => 'synthetic-duplicate'])->assertConflict();
        $this->assertSame(1, ClientCompany::query()->where('workspace_id', $this->workspace->id)->count());
        $this->assertCount($calls, Http::recorded(), 'All operations use the mirror and never call Square.');
    }

    public function test_import_over_mcp_requires_confirmation_and_can_create_a_client_without_an_agreement(): void
    {
        $this->postJson($this->squareUrl('customers/cust_fixture_a/import'), ['confirm' => false], ['Idempotency-Key' => 'synthetic-unconfirmed'])->assertStatus(422);
        $session = $this->initialize();
        $result = $this->callTool($session, 'square.import_customer', ['workspace_id' => $this->workspace->public_id, 'customer_id' => 'cust_fixture_a', 'idempotency_key' => 'synthetic-mcp-import', 'confirm' => true]);
        $this->assertFalse($result['result']['isError'] ?? false, json_encode($result));
        $this->assertNull($result['result']['structuredContent']['data']['agreement_id']);
        $this->assertSame(0, ClientAgreement::query()->count());
        $this->assertSame(1, ClientCompany::query()->count());
    }

    public function test_import_refuses_a_series_of_another_customer_and_rolls_back_all_records(): void
    {
        $this->postJson($this->squareUrl('customers/cust_fixture_b/import'), ['confirm' => true, 'series_key' => '000001'], ['Idempotency-Key' => 'synthetic-wrong-series'])->assertStatus(422);
        $this->postJson($this->squareUrl('customers/cust_fixture_missing/import'), ['confirm' => true], ['Idempotency-Key' => 'synthetic-missing'])->assertNotFound();
        $this->assertSame(0, ClientCompany::query()->count());
        $this->assertSame(0, CompanySquareCustomer::query()->count());
        $this->assertSame(0, ClientAgreement::query()->count());
    }

    public function test_the_client_write_cutover_withholds_import_but_keeps_mirror_reads_available(): void
    {
        config(['agent_api.client_writes_enabled' => false]);

        $this->postJson($this->squareUrl('customers/cust_fixture_a/import'), ['confirm' => true], ['Idempotency-Key' => 'synthetic-clients-off'])->assertNotFound();
        $this->getJson($this->squareUrl('customers/unlinked'))->assertOk();
        $this->assertSame(0, ClientCompany::query()->count());
    }

    public function test_operations_cannot_read_or_write_another_workspace_and_require_manager_scopes_and_configuration(): void
    {
        $company = $this->company();
        $invoice = $this->invoice($company);
        $other = Workspace::query()->create(['name' => 'Synthetic isolated workspace', 'slug' => 'synthetic-isolated-candidates']);
        $other->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Synthetic isolated client', 'slug' => 'synthetic-isolated-client']);
        $foreignInvoice = $this->invoice($foreignCompany, 'SYNTHETIC-FOREIGN');
        $this->getJson($this->candidatesUrl($foreignInvoice))->assertNotFound();
        $this->getJson($this->squareUrl('customers/unlinked', $other))->assertNotFound();
        $this->postJson($this->squareUrl('customers/cust_fixture_a/import', $other), ['confirm' => true], ['Idempotency-Key' => 'synthetic-other-workspace'])->assertNotFound();
        $this->assertSame(1, ClientCompany::query()->where('workspace_id', $other->id)->count());

        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['billing:read', 'billing:write']);
        $this->getJson($this->candidatesUrl($invoice))->assertForbidden();
        $this->getJson($this->squareUrl('customers/unlinked'))->assertForbidden();
        $this->postJson($this->squareUrl('customers/cust_fixture_a/import'), ['confirm' => true], ['Idempotency-Key' => 'synthetic-member-import'])->assertForbidden();
        $this->actingAsMcp($this->owner, ['billing:read']);
        $this->postJson($this->squareUrl('customers/cust_fixture_a/import'), ['confirm' => true], ['Idempotency-Key' => 'synthetic-read-only'])->assertForbidden();
        $this->actingAsMcp($this->owner, ['billing:read', 'billing:write']);
        config(['services.square.access_token' => null]);
        $this->getJson($this->candidatesUrl($invoice))->assertNotFound();
        $this->getJson($this->squareUrl('customers/unlinked'))->assertNotFound();
        $this->postJson($this->squareUrl('customers/cust_fixture_a/import'), ['confirm' => true], ['Idempotency-Key' => 'synthetic-off'])->assertNotFound();
        $this->assertSame(0, CompanySquareCustomer::query()->count());
    }

    private function company(): ClientCompany
    {
        return ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic candidate client', 'slug' => 'synthetic-candidate-client']);
    }

    private function invoice(ClientCompany $company, string $number = 'SYNTHETIC-1'): ClientInvoice
    {
        return ClientInvoice::query()->create(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'invoice_number' => $number, 'invoice_kind' => 'ad_hoc', 'status' => 'issued', 'currency' => 'USD', 'due_date' => '2026-10-15', 'cycle_start' => '2026-10-01', 'cycle_end' => '2026-10-31', 'total_amount' => 12_345, 'subtotal_amount' => 12_345, 'paid_amount' => 0, 'balance_amount' => 12_345, 'tax_amount' => 0]);
    }

    private function squareUrl(string $suffix, ?Workspace $workspace = null): string
    {
        return '/api/v1/workspaces/'.($workspace ?? $this->workspace)->public_id.'/square/'.$suffix;
    }

    private function candidatesUrl(ClientInvoice $invoice): string
    {
        return '/api/v1/workspaces/'.$this->workspace->public_id.'/invoices/'.$invoice->public_id.'/square-candidates';
    }

    private function assertSchema(string $operation, array $response): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($response, AgentApiResponseSchemaCatalog::forOperation($operation)));
    }
}
