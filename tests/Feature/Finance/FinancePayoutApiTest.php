<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorPayout;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Services\Finance\ProcessorSettlementRecorder;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\Finance\PaymentSettlement;
use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutStatus;
use App\Support\Finance\PayoutType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * What the finance system reads about processor settlement (S7): each
 * payment's processor identifier, charge time, fee, payout and arrival date on
 * `finance.invoice_payments.list`, and the payouts themselves on
 * `finance.payouts.list` - numbered pages or a change feed with the same
 * semantics as the payment feed, from every door.
 */
final class FinancePayoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_payment_listing_carries_each_payments_settlement(): void
    {
        [$owner, $workspace, $company] = $this->tenant('Settled');
        $stripe = $this->payment($workspace, $company, 'stripe', 'pi_synthetic_listed');
        $manual = $this->payment($workspace, $company, null, null);
        app(ProcessorSettlementRecorder::class)->recordPaymentSettlement($workspace, 'stripe', ['pi_synthetic_listed'],
            PaymentSettlement::payout('po_synthetic_listed', '2026-10-21', 1000, CarbonImmutable::parse('2026-10-18 09:30:15', 'UTC'), 59, 'USD'));
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ]);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoice-payments?status=any&per_page=10")->assertOk();
        $this->assertMatchesSchema($response, FinanceReconciliationOperations::LIST);
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame([
            'provider_payment_identifier' => 'pi_synthetic_listed',
            'provider_charged_at' => '2026-10-18T09:30:15+00:00',
            'provider_fee_amount' => 59,
            'provider_payout_identifier' => 'po_synthetic_listed',
            'provider_payout_arrival_date' => '2026-10-21',
        ], array_intersect_key($rows[$stripe->public_id], array_flip(['provider_payment_identifier', 'provider_charged_at', 'provider_fee_amount', 'provider_payout_identifier', 'provider_payout_arrival_date'])));
        foreach (['provider_payment_identifier', 'provider_charged_at', 'provider_fee_amount', 'provider_payout_identifier', 'provider_payout_arrival_date'] as $field) {
            $this->assertNull($rows[$manual->public_id][$field], $field);
        }
        // Settling it moved the payment, so the feed delivers the change.
        $this->assertSame($stripe->lock_version + 1, $stripe->fresh()?->lock_version);
    }

    public function test_payouts_are_listed_newest_arrival_first_and_filtered(): void
    {
        [$owner, $workspace] = $this->tenant('Listing');
        [, $other] = $this->tenant('Other listing');
        $early = $this->payout($workspace, 'po_synthetic_early', 900, '2026-10-20');
        $late = $this->payout($workspace, 'po_synthetic_late', -300, '2026-10-22', 'square', PayoutStatus::Failed);
        $pending = $this->payout($workspace, 'po_synthetic_pending', 100, null, status: PayoutStatus::InTransit);
        $this->payout($other, 'po_synthetic_foreign', 5000, '2026-10-23');
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ]);

        $page = $this->getJson($this->payoutsPath($workspace))->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.amount', -300)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.provider', 'square')
            ->assertJsonPath('data.1.arrival_date', '2026-10-20')
            ->assertJsonPath('data.1.provider_payout_identifier', 'po_synthetic_early')
            ->assertJsonPath('data.1.type', 'automatic');
        $this->assertMatchesSchema($page, FinanceReconciliationOperations::PAYOUTS);
        $this->assertSame([$late->public_id, $early->public_id, $pending->public_id], array_column($page->json('data'), 'id'));

        $this->assertSame([$early->public_id, $pending->public_id], array_column($this->getJson($this->payoutsPath($workspace, ['provider' => 'stripe']))->assertOk()->json('data'), 'id'));
        $this->assertSame([$late->public_id], array_column($this->getJson($this->payoutsPath($workspace, ['status' => 'failed']))->assertOk()->json('data'), 'id'));
        $this->assertSame([$early->public_id], array_column($this->getJson($this->payoutsPath($workspace, ['arrival_from' => '2026-10-20', 'arrival_to' => '2026-10-21']))->assertOk()->json('data'), 'id'));
        $this->getJson($this->payoutsPath($workspace, ['per_page' => 1, 'page' => 2]))->assertOk()->assertJsonPath('data.0.id', $early->public_id);

        foreach ([['provider' => 'paypal'], ['status' => 'paid_out'], ['arrival_from' => '2026-10-21', 'arrival_to' => '2026-10-20'], ['per_page' => 101]] as $invalid) {
            $this->getJson($this->payoutsPath($workspace, $invalid))->assertUnprocessable();
        }
    }

    public function test_the_payout_feed_delivers_each_change_and_refuses_filters_a_payout_changes_out_of(): void
    {
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        [$owner, $workspace] = $this->tenant('Feed');
        $payout = $this->payout($workspace, 'po_synthetic_feed', 941, '2026-10-21', status: PayoutStatus::Paid, observedAt: 1000);
        $this->payout($workspace, 'po_synthetic_square_feed', 500, '2026-10-21', 'square');
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ]);
        CarbonImmutable::setTestNow('2026-10-20 12:05:00');

        $first = $this->getJson($this->payoutsPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z']))->assertOk()
            ->assertJsonPath('meta.watermark', '2026-10-20T12:03:00+00:00')
            ->assertJsonMissingPath('links');
        $this->assertMatchesSchema($first, FinanceReconciliationOperations::PAYOUTS);
        $read = collect($first->json('data'))->firstWhere('id', $payout->public_id);
        $this->assertIsArray($read);

        // The payout fails: the next pass delivers it with a new version.
        CarbonImmutable::setTestNow('2026-10-20 12:10:00');
        $this->payout($workspace, 'po_synthetic_feed', 941, '2026-10-21', status: PayoutStatus::Failed, observedAt: 2000);
        CarbonImmutable::setTestNow('2026-10-20 12:15:00');
        $next = $this->getJson($this->payoutsPath($workspace, ['updated_since' => $first->json('meta.watermark')]))->assertOk();
        $this->assertSame([$payout->public_id], array_column($next->json('data'), 'id'));
        $this->assertSame('failed', $next->json('data.0.status'));
        $this->assertNotSame($read['version'], $next->json('data.0.version'));

        // Only the provider, which a payout never changes, filters a feed.
        $this->getJson($this->payoutsPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z', 'provider' => 'square']))->assertOk()->assertJsonCount(1, 'data');
        foreach (['status' => 'paid', 'arrival_from' => '2026-10-01', 'arrival_to' => '2026-10-31', 'page' => 1] as $filter => $value) {
            $this->getJson($this->payoutsPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z', $filter => $value]))->assertUnprocessable()->assertJsonValidationErrors($filter);
        }

        // Followed by cursor, one at a time.
        $paged = $this->getJson($this->payoutsPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z', 'per_page' => 1]))->assertOk();
        $cursor = (string) $paged->json('meta.next_cursor');
        $this->assertNotSame('', $cursor);
        $rest = $this->getJson($this->payoutsPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z', 'per_page' => 1, 'cursor' => $cursor]))->assertOk()->assertJsonPath('meta.next_cursor', null);
        $this->assertCount(2, array_unique([$paged->json('data.0.id'), $rest->json('data.0.id')]));
        // A cursor of the payment feed is not one of this feed.
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoice-payments?status=any&updated_since=2026-01-01T00:00:00Z&cursor=".rawurlencode($cursor))->assertUnprocessable();
    }

    public function test_payouts_are_read_by_the_same_callers_as_payments(): void
    {
        [$owner, $workspace] = $this->tenant('Doors');
        $this->payout($workspace, 'po_synthetic_doors', 941, '2026-10-21');
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $outsider = User::factory()->create();

        $this->actingAsMcp($outsider, [AgentApiScopes::FINANCE_READ]);
        $this->getJson($this->payoutsPath($workspace))->assertNotFound();
        $this->actingAsMcp($member, [AgentApiScopes::FINANCE_READ]);
        $this->getJson($this->payoutsPath($workspace))->assertForbidden();
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_RECONCILE]);
        $this->getJson($this->payoutsPath($workspace))->assertForbidden();
        $this->app['auth']->forgetGuards();

        // The finance token: any member may read, with finance.read only.
        $read = $member->createToken('finance read', ['finance.read'], now()->addHour())->plainTextToken;
        $this->withToken($read)->getJson($this->payoutsPath($workspace))->assertOk()->assertJsonPath('data.0.provider_payout_identifier', 'po_synthetic_doors')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->app['auth']->forgetGuards();
        $reconcile = $owner->createToken('finance reconcile', ['finance.reconcile'], now()->addHour())->plainTextToken;
        $this->withToken($reconcile)->getJson($this->payoutsPath($workspace))->assertForbidden();
    }

    public function test_the_mcp_tool_and_the_console_list_the_same_payouts(): void
    {
        [$owner, $workspace] = $this->tenant('Twins');
        $payout = $this->payout($workspace, 'po_synthetic_twins', 941, '2026-10-21');
        config(['app.url' => 'https://svc.example.test']);

        $this->actingAsMcp($owner, ['mcp:use', AgentApiScopes::FINANCE_READ]);
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic payout test', 'version' => '1']]], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = (string) $init->headers->get('Mcp-Session-Id');
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => FinanceReconciliationOperations::PAYOUTS, 'arguments' => ['workspace_id' => $workspace->public_id, 'provider' => 'stripe', 'per_page' => 5],
        ]], $headers)->assertOk()->json('result');
        $this->assertFalse($result['isError'] ?? false, (string) json_encode($result));
        $this->assertSame($payout->public_id, $result['structuredContent']['data'][0]['id']);
        $this->assertSame("https://svc.example.test/api/v1/workspaces/{$workspace->public_id}/payouts", $result['structuredContent']['meta']['path']);

        Artisan::call('svc:finance:payouts', ['--workspace' => $workspace->public_id, '--user' => $owner->public_id, '--format' => 'json']);
        $console = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($payout->public_id, $console['data'][0]['id']);
        $this->assertSame(941, $console['data'][0]['amount']);
        $this->assertSame(1, $console['meta']['total']);

        $this->assertSame(2, Artisan::call('svc:finance:payouts', ['--workspace' => $workspace->public_id, '--user' => $owner->public_id, '--status' => 'nope']));
        $this->assertSame(0, Artisan::call('svc:finance:payouts', ['--workspace' => $workspace->public_id, '--user' => $owner->public_id]));
        $this->assertStringContainsString('1 payouts', Artisan::output());
    }

    /** @return array{0: User, 1: Workspace, 2: ClientCompany} */
    private function tenant(string $name): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5))]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => $name.' Client', 'slug' => Str::slug($name).'-client-'.Str::lower(Str::random(5))]);

        return [$owner, $workspace, $company];
    }

    private function payment(Workspace $workspace, ClientCompany $company, ?string $provider, ?string $identifier): ClientInvoicePayment
    {
        $invoices = app(InvoiceLifecycleService::class);
        $invoice = $invoices->createDraft($workspace, $company, ['invoice_number' => 'INV-'.Str::upper(Str::random(10)), 'currency' => 'USD'],
            [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $invoices->issue($invoice, $workspace);

        return $invoices->applyPayment($invoice, array_filter([
            'amount' => 1000, 'currency' => 'USD', 'method' => $provider ?? 'wire', 'status' => 'succeeded',
            'provider' => $provider, 'provider_payment_identifier' => $identifier,
        ], static fn (mixed $value): bool => $value !== null), $workspace);
    }

    private function payout(Workspace $workspace, string $id, int $amount, ?string $arrival, string $provider = 'stripe', PayoutStatus $status = PayoutStatus::Paid, int $observedAt = 1000): ProcessorPayout
    {
        app(ProcessorSettlementRecorder::class)->recordPayout($workspace, new PayoutFacts($provider, $id, $amount, 'USD', $arrival, PayoutType::Automatic, $status, $observedAt));

        return ProcessorPayout::query()->where('workspace_id', $workspace->id)->where('provider_payout_identifier', $id)->sole();
    }

    /** @param array<string, int|string> $query */
    private function payoutsPath(Workspace $workspace, array $query = []): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/payouts".($query === [] ? '' : '?'.http_build_query($query));
    }

    /** @param TestResponse<Response> $response */
    private function assertMatchesSchema(TestResponse $response, string $operation): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($response->json(), AgentApiResponseSchemaCatalog::forOperation($operation)));
    }
}
