<?php

namespace Tests\Feature\Square;

use App\Models\AgentMutationAudit;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceSquareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * SVC's Square links over REST and MCP (#438): one operation each way, the
 * same receipts, refusals and history. Every Square identifier here is
 * synthetic.
 */
final class SquareLinkApiTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'clients:read', 'billing:read', 'billing:write'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 12:00:00');
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
    }

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_a_manager_links_reads_and_unlinks_an_invoice(string $transport): void
    {
        [$workspace, $owner, , $invoice] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);

        $linked = $this->success($transport, 'invoices.square_link', $workspace, ['invoice_id' => $invoice->public_id], [
            'expected_version' => AgentApiVersion::for($invoice),
            'square_invoice_id' => 'inv:0-fixture1',
            'square_invoice_number' => '000001-R-0002',
            'currency' => 'USD',
            'match_method' => 'suggested',
            'score' => 85,
            'reasons' => ['exact amount', 'period slot'],
        ], 'synthetic-link');

        $this->assertSame($invoice->public_id, $linked['data']['invoice_id']);
        $this->assertSame('inv:0-fixture1', $linked['data']['link']['square_invoice_id']);
        $this->assertSame('000001', $linked['data']['link']['series_key']);
        $this->assertSame('USD', $linked['data']['link']['currency']);
        $this->assertSame('suggested', $linked['data']['link']['match_method']);
        $this->assertSame(85, $linked['data']['link']['score']);
        $this->assertSame(['exact amount', 'period slot'], $linked['data']['link']['reasons']);
        $this->assertSame($owner->name, $linked['data']['link']['linked_by']);
        $this->assertTrue($linked['data']['square_collects']);
        $this->assertStringContainsString('000001-R-0002', (string) $linked['data']['collection_refusal']);
        $this->assertNotSame(AgentApiVersion::for($invoice), $linked['data']['invoice_version'], 'Linking advances the invoice version');

        $read = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}")->assertOk()->json('data.square');
        $this->assertSame($linked['data'], $read);
        $this->assertStringNotContainsString('squareup', json_encode($read, JSON_THROW_ON_ERROR), 'No Square URL is built from an id');

        $unlinked = $this->success($transport, 'invoices.square_unlink', $workspace, ['invoice_id' => $invoice->public_id], [
            'expected_version' => $linked['data']['invoice_version'],
            'confirm' => true,
        ], 'synthetic-unlink');
        $this->assertNull($unlinked['data']['link']);
        $this->assertFalse($unlinked['data']['square_collects']);
        $this->assertNull($unlinked['data']['collection_refusal']);

        $history = InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertNotNull($history->unlinked_at);
        $this->assertSame($owner->id, $history->unlinked_by_user_id);
        $this->assertNull($history->active_invoice_slot);
        $this->assertNull($history->active_square_invoice_slot);
        $this->assertSame(['invoice.square_linked', 'invoice.square_unlinked'], $this->activity($workspace));
    }

    public function test_rest_and_mcp_share_one_receipt_and_replay_without_linking_twice(): void
    {
        [$workspace, $owner, , $invoice] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $body = ['expected_version' => AgentApiVersion::for($invoice), 'square_invoice_id' => 'inv:0-fixture1', 'square_invoice_number' => '000001'];

        $first = $this->success('rest', 'invoices.square_link', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-shared');
        $again = $this->success('mcp', 'invoices.square_link', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-shared');

        $this->assertSame($first, $again);
        $this->assertNull($first['data']['link']['series_key'], 'A one-off number belongs to no series');
        $this->assertSame('manual', $first['data']['link']['match_method']);
        $this->assertSame(1, InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'invoices.square_link')->where('outcome', 'success')->count());
        $this->assertSame(1, AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'invoices.square_link')->where('outcome', 'replay')->count());
        $this->rest('invoices.square_link', $workspace, ['invoice_id' => $invoice->public_id], [...$body, 'square_invoice_number' => '000002'], 'synthetic-shared')->assertConflict();
    }

    public function test_each_side_has_one_active_link_and_re_pointing_is_explicit(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->fixture();
        $other = $this->invoice($company, 'SYN-2');
        $this->actingAsMcp($owner, self::SCOPES);
        $link = fn (ClientInvoice $target, array $body, string $key): TestResponse => $this->rest('invoices.square_link', $workspace, ['invoice_id' => $target->public_id], [
            'expected_version' => AgentApiVersion::for($target->refresh()), 'square_invoice_id' => 'inv:0-fixture1', 'square_invoice_number' => '000001-R-0001', ...$body,
        ], $key);

        $link($invoice, [], 'synthetic-first')->assertOk();
        // The Square invoice is taken, from any SVC invoice.
        $link($other, [], 'synthetic-elsewhere')->assertConflict()->assertJsonPath('message', 'Square invoice 000001-R-0001 is already linked to invoice SYN-1. Unlink it there first.');
        $link($other, ['replace' => true], 'synthetic-elsewhere-replace')->assertConflict();
        // The same pair again.
        $link($invoice, [], 'synthetic-same')->assertConflict();
        // This invoice is taken: re-pointing it needs replace.
        $link($invoice, ['square_invoice_id' => 'inv:0-fixture2', 'square_invoice_number' => '000002-R-0001'], 'synthetic-repoint')->assertConflict()
            ->assertJsonPath('message', 'Invoice SYN-1 is already linked to Square invoice 000001-R-0001. Pass replace: true to re-point it.');
        $replaced = $link($invoice, ['square_invoice_id' => 'inv:0-fixture2', 'square_invoice_number' => '000002-R-0001', 'replace' => true], 'synthetic-repoint-confirmed')->assertOk()->json('data');
        $this->assertSame('inv:0-fixture2', $replaced['link']['square_invoice_id']);
        // The freed Square invoice may be linked again, elsewhere.
        $link($other, [], 'synthetic-freed')->assertOk();

        $rows = InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertNotNull($rows[0]->unlinked_at, 'The replaced link is kept as history');
        $this->assertNull($rows[1]->unlinked_at);
        $this->assertNull($rows[2]->unlinked_at);
        $replacement = ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.square_linked')->orderByDesc('id')->skip(1)->firstOrFail();
        $this->assertSame('000001-R-0001', $replacement->payload['replaced_square_invoice_number']);
    }

    public function test_link_refusals(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $version = AgentApiVersion::for($invoice);
        $valid = ['expected_version' => $version, 'square_invoice_id' => 'inv:0-fixture1', 'square_invoice_number' => '000001-R-0001'];
        $path = ['invoice_id' => $invoice->public_id];

        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'expected_version' => str_repeat('a', 64)], 'synthetic-stale')->assertConflict();
        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'square_invoice_id' => 'inv:0 fixture'], 'synthetic-bad-id')->assertUnprocessable()->assertJsonValidationErrors('square_invoice_id');
        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'square_invoice_id' => '<script>'], 'synthetic-markup')->assertUnprocessable();
        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'square_invoice_number' => "000001\n0002"], 'synthetic-bad-number')->assertUnprocessable()->assertJsonValidationErrors('square_invoice_number');
        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'score' => 90], 'synthetic-manual-score')->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'reasons' => ['x']], 'synthetic-manual-reasons')->assertUnprocessable()->assertJsonValidationErrors('reasons');
        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'match_method' => 'suggested', 'score' => 101], 'synthetic-score-range')->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'replace' => 'yes'], 'synthetic-replace-type')->assertUnprocessable()->assertJsonValidationErrors('replace');
        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'public_url' => 'https://example.test'], 'synthetic-unknown')->assertUnprocessable()->assertJsonValidationErrors('public_url');
        $this->rest('invoices.square_link', $workspace, $path, [...$valid, 'currency' => 'EUR'], 'synthetic-currency')->assertUnprocessable()
            ->assertJsonPath('message', 'Square invoice 000001-R-0001 is in EUR and invoice SYN-1 is in USD; a link must be in one currency.');
        $void = $this->invoice($company, 'SYN-VOID', 'void');
        $this->rest('invoices.square_link', $workspace, ['invoice_id' => $void->public_id], [...$valid, 'expected_version' => AgentApiVersion::for($void)], 'synthetic-void')->assertUnprocessable();
        $this->rest('invoices.square_unlink', $workspace, $path, ['expected_version' => $version, 'confirm' => true], 'synthetic-not-linked')->assertConflict();
        $this->rest('invoices.square_unlink', $workspace, $path, ['expected_version' => $version, 'confirm' => false], 'synthetic-unconfirmed')->assertUnprocessable();

        $this->assertSame(0, InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame([], $this->activity($workspace));
    }

    public function test_only_managers_of_the_owning_workspace_reach_the_operations(): void
    {
        [$workspace, $owner, $company, $invoice, $agreement] = $this->fixture();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $body = ['expected_version' => AgentApiVersion::for($invoice), 'square_invoice_id' => 'inv:0-fixture1', 'square_invoice_number' => '000001'];

        $this->actingAsMcp($member, self::SCOPES);
        $this->rest('invoices.square_link', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-member')->assertForbidden();
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/clients/{$company->public_id}/square-customer")->assertForbidden();

        [$elsewhere, $stranger] = $this->fixture('synthetic-elsewhere');
        $this->actingAsMcp($stranger, self::SCOPES);
        $this->rest('invoices.square_link', $elsewhere, ['invoice_id' => $invoice->public_id], $body, 'synthetic-cross-tenant')->assertNotFound();
        $this->rest('agreements.square_series.set', $elsewhere, ['agreement_id' => $agreement->public_id], ['expected_version' => AgentApiVersion::for($agreement), 'series_key' => '000001', 'confirm' => true], 'synthetic-cross-agreement')->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$elsewhere->public_id}/clients/{$company->public_id}/square-customer")->assertNotFound();

        $this->actingAsMcp($owner, ['mcp:use', 'billing:read']);
        $this->rest('invoices.square_link', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-read-only')->assertForbidden();

        $this->assertSame(0, InvoiceSquareLink::query()->count());
        $this->assertNull($agreement->refresh()->square_series_key);
    }

    #[DataProvider('transports')]
    public function test_a_manager_confirms_and_clears_a_clients_square_customer(string $transport): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        $other = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Other Client', 'slug' => 'synthetic-other-client']);
        $this->actingAsMcp($owner, self::SCOPES);

        $state = $this->success($transport, 'companies.square_customer.get', $workspace, ['client_id' => $company->public_id]);
        $this->assertSame(['client_id' => $company->public_id, 'version' => AgentApiVersion::for($company), 'square_customer' => null], $state['data']);

        $set = $this->success($transport, 'companies.square_customer.set', $workspace, ['client_id' => $company->public_id], ['expected_version' => $state['data']['version'], 'square_customer_id' => 'CUSTFIXTUREA'], 'synthetic-set');
        $this->assertSame('CUSTFIXTUREA', $set['data']['square_customer']['square_customer_id']);
        $this->assertSame($owner->name, $set['data']['square_customer']['confirmed_by']);
        $client = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/clients/{$company->public_id}")->assertOk()->json('data');
        $this->assertSame($set['data']['square_customer'], $client['square_customer']);
        $this->assertSame($set['data']['version'], $client['version']);
        $listed = collect((array) $this->getJson("/api/v1/workspaces/{$workspace->public_id}/clients")->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($set['data']['square_customer'], $listed[$company->public_id]['square_customer']);
        $this->assertNull($listed[$other->public_id]['square_customer']);

        // One client per Square customer.
        $this->refused($this->send($transport, 'companies.square_customer.set', $workspace, ['client_id' => $other->public_id], ['expected_version' => AgentApiVersion::for($other), 'square_customer_id' => 'CUSTFIXTUREA'], 'synthetic-taken'), 409);
        // The same customer again changes nothing.
        $same = $this->success($transport, 'companies.square_customer.set', $workspace, ['client_id' => $company->public_id], ['expected_version' => $set['data']['version'], 'square_customer_id' => 'CUSTFIXTUREA'], 'synthetic-same');
        $this->assertSame($set['data'], $same['data']);
        // A different one replaces it, keeping the old mapping as history.
        $replaced = $this->success($transport, 'companies.square_customer.set', $workspace, ['client_id' => $company->public_id], ['expected_version' => $set['data']['version'], 'square_customer_id' => 'CUSTFIXTUREB'], 'synthetic-replace');
        $this->assertSame('CUSTFIXTUREB', $replaced['data']['square_customer']['square_customer_id']);
        $cleared = $this->success($transport, 'companies.square_customer.clear', $workspace, ['client_id' => $company->public_id], ['expected_version' => $replaced['data']['version'], 'confirm' => true], 'synthetic-clear');
        $this->assertNull($cleared['data']['square_customer']);
        // Now free, the first customer may be mapped to the other client.
        $this->success($transport, 'companies.square_customer.set', $workspace, ['client_id' => $other->public_id], ['expected_version' => AgentApiVersion::for($other->refresh()), 'square_customer_id' => 'CUSTFIXTUREA'], 'synthetic-freed');

        $this->assertSame(2, CompanySquareCustomer::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->whereNotNull('cleared_at')->count());
        $this->assertSame(0, CompanySquareCustomer::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->whereNull('cleared_at')->count());
        $this->refused($this->send($transport, 'companies.square_customer.set', $workspace, ['client_id' => $company->public_id], ['expected_version' => $cleared['data']['version'], 'square_customer_id' => 'cust fixture'], 'synthetic-bad'), 422);
        $this->assertSame(['company.square_customer_set', 'company.square_customer_set', 'company.square_customer_cleared', 'company.square_customer_set'], $this->activity($workspace));
    }

    #[DataProvider('transports')]
    public function test_a_manager_marks_and_clears_an_agreement_billed_in_square(string $transport): void
    {
        [$workspace, $owner, $company, , $agreement] = $this->fixture();
        $second = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Annual', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'annual', 'currency' => 'USD', 'hourly_rate_amount' => 10000]);
        $this->actingAsMcp($owner, self::SCOPES);

        $marked = $this->success($transport, 'agreements.square_series.set', $workspace, ['agreement_id' => $agreement->public_id], ['expected_version' => AgentApiVersion::for($agreement), 'series_key' => '000123', 'confirm' => true], 'synthetic-mark');
        $this->assertSame('000123', $marked['data']['square_series_key']);
        $this->assertSame('000123', $this->getJson("/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}")->assertOk()->json('data.square_series_key'));
        $this->assertNull(collect((array) $this->getJson("/api/v1/workspaces/{$workspace->public_id}/agreements")->assertOk()->json('data'))->firstWhere('id', $second->public_id)['square_series_key']);

        $this->refused($this->send($transport, 'agreements.square_series.set', $workspace, ['agreement_id' => $second->public_id], ['expected_version' => AgentApiVersion::for($second), 'series_key' => '000123', 'confirm' => true], 'synthetic-taken'), 409);
        $this->rest('agreements.square_series.set', $workspace, ['agreement_id' => $second->public_id], ['expected_version' => AgentApiVersion::for($second), 'series_key' => '12345', 'confirm' => true], 'synthetic-short')->assertUnprocessable();
        $this->rest('agreements.square_series.set', $workspace, ['agreement_id' => $second->public_id], ['expected_version' => AgentApiVersion::for($second), 'series_key' => '000124'], 'synthetic-unconfirmed')->assertUnprocessable();

        $cleared = $this->success($transport, 'agreements.square_series.clear', $workspace, ['agreement_id' => $agreement->public_id], ['expected_version' => $marked['data']['version'], 'confirm' => true], 'synthetic-clear');
        $this->assertNull($cleared['data']['square_series_key']);
        $this->rest('agreements.square_series.clear', $workspace, ['agreement_id' => $agreement->public_id], ['expected_version' => $cleared['data']['version'], 'confirm' => true], 'synthetic-clear-again')->assertConflict();
        $this->assertSame(['agreement.square_series_set', 'agreement.square_series_cleared'], $this->activity($workspace));
        $this->assertNull($second->refresh()->square_series_key);
    }

    /** The marker is an operator fact: no other agreement write touches it, and the model never serializes it. */
    public function test_the_agreement_marker_is_written_only_by_its_operation(): void
    {
        [, , , , $agreement] = $this->fixture();
        $agreement->update(['square_series_key' => '000999', 'title' => 'Synthetic Renamed']);

        $this->assertNull($agreement->refresh()->square_series_key);
        $agreement->forceFill(['square_series_key' => '000999'])->save();
        $this->assertArrayNotHasKey('square_series_key', $agreement->toArray());
    }

    /** @param TestResponse<Response>|array<string, mixed> $response */
    private function refused(TestResponse|array $response, int $status): void
    {
        if ($response instanceof TestResponse) {
            $response->assertStatus($status);

            return;
        }
        $this->assertTrue(isset($response['error']) || ($response['result']['isError'] ?? false), json_encode($response, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function activity(Workspace $workspace): array
    {
        return array_values(ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where(fn ($query) => $query->where('action', 'like', '%square%'))->orderBy('id')->pluck('action')->map(static fn (mixed $action): string => (string) $action)->all());
    }

    /**
     * @param  array<string, string>  $ids
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function success(string $transport, string $operation, Workspace $workspace, array $ids, array $body = [], ?string $key = null): array
    {
        $response = $this->send($transport, $operation, $workspace, $ids, $body, $key);
        if ($response instanceof TestResponse) {
            $result = $response->assertOk()->json();
        } else {
            $this->assertFalse($response['result']['isError'] ?? true, json_encode($response, JSON_THROW_ON_ERROR));
            $result = $response['result']['structuredContent'];
        }
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($result, AgentApiResponseSchemaCatalog::forOperation($operation)), json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    /**
     * @param  array<string, string>  $ids
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>|array<string, mixed>
     */
    private function send(string $transport, string $operation, Workspace $workspace, array $ids, array $body = [], ?string $key = null): TestResponse|array
    {
        return $transport === 'mcp'
            ? $this->callTool($this->initialize(), $operation, ['workspace_id' => $workspace->public_id, ...$ids, ...$body, ...($key === null ? [] : ['idempotency_key' => $key])])
            : $this->rest($operation, $workspace, $ids, $body, $key);
    }

    /**
     * @param  array<string, string>  $ids
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function rest(string $operation, Workspace $workspace, array $ids, array $body = [], ?string $key = null): TestResponse
    {
        [$method, $path] = match ($operation) {
            'invoices.square_link' => ['POST', "invoices/{$ids['invoice_id']}/square-link"],
            'invoices.square_unlink' => ['DELETE', "invoices/{$ids['invoice_id']}/square-link"],
            'companies.square_customer.get' => ['GET', "clients/{$ids['client_id']}/square-customer"],
            'companies.square_customer.set' => ['PUT', "clients/{$ids['client_id']}/square-customer"],
            'companies.square_customer.clear' => ['DELETE', "clients/{$ids['client_id']}/square-customer"],
            'agreements.square_series.set' => ['PUT', "agreements/{$ids['agreement_id']}/square-series"],
            'agreements.square_series.clear' => ['DELETE', "agreements/{$ids['agreement_id']}/square-series"],
            default => throw new \LogicException("Unknown synthetic operation {$operation}."),
        };

        return $this->json($method, "/api/v1/workspaces/{$workspace->public_id}/{$path}", $body, $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    private function invoice(ClientCompany $company, string $number, string $status = 'issued'): ClientInvoice
    {
        return ClientInvoice::query()->create(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'invoice_number' => $number, 'invoice_kind' => 'ad_hoc', 'status' => $status, 'currency' => 'USD', 'subtotal_amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000, 'paid_amount' => 0, 'balance_amount' => 5000]);
    }

    /** @return array{Workspace, User, ClientCompany, ClientInvoice, ClientAgreement} */
    private function fixture(string $slug = 'synthetic-square-links'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Square Links', 'slug' => $slug.'-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Square Client', 'slug' => 'synthetic-square-client']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Monthly', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000]);

        return [$workspace, $owner, $company, $this->invoice($company, 'SYN-1'), $agreement];
    }
}
