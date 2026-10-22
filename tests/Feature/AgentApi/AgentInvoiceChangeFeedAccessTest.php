<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AgentInvoiceChangeFeedAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function portalRemovals(): iterable
    {
        foreach (['updated_since', 'watermark'] as $parameter) {
            yield $parameter.' hidden' => [$parameter, ['is_visible_to_client' => false]];
            yield $parameter.' voided' => [$parameter, ['status' => 'void']];
        }
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('portalRemovals')]
    public function test_portal_change_feed_is_refused_while_snapshots_follow_visibility(string $parameter, array $change): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [, $workspace, $company] = $this->workspace();
        $portal = User::factory()->create();
        $company->portalUsers()->attach($portal, ['role' => 'client']);
        $invoice = $this->invoice($workspace, $company);
        $this->actingAsMcp($portal, [AgentApiScopes::BILLING_READ]);
        $path = "/api/v1/workspaces/{$workspace->public_id}/invoices";
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.id', $invoice->public_id);

        $invoice->forceFill($change)->save();
        CarbonImmutable::setTestNow('2026-10-01 12:02:00');
        $response = $this->getJson($path.'?'.http_build_query([$parameter => '2026-10-01T12:00:00Z']))
            ->assertUnprocessable()->assertJsonValidationErrors('change_feed');
        $this->assertStringContainsString('owners and admins', (string) $response->json('errors.change_feed.0'));
        $this->assertStringNotContainsString($invoice->public_id, $response->getContent());
        $this->getJson($path)->assertOk()->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null]]);
    }

    public function test_workspace_members_without_manager_role_cannot_request_an_invoice_change_feed(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:02:00');
        [, $workspace] = $this->workspace();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, [AgentApiScopes::BILLING_READ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices?updated_since=2026-01-01T00:00:00Z")
            ->assertUnprocessable()->assertJsonValidationErrors('change_feed');
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices")->assertOk();
    }

    public function test_owner_and_admin_change_feeds_include_rows_removed_from_portal_snapshots(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $company] = $this->workspace();
        $hidden = $this->invoice($workspace, $company);
        $hidden->forceFill(['is_visible_to_client' => false])->save();
        $voided = $this->invoice($workspace, $company);
        $voided->forceFill(['status' => 'void'])->save();
        $admin = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
        CarbonImmutable::setTestNow('2026-10-01 12:02:00');
        $path = "/api/v1/workspaces/{$workspace->public_id}/invoices";

        foreach ([$owner, $admin] as $manager) {
            $this->actingAsMcp($manager, [AgentApiScopes::BILLING_READ]);
            foreach (['updated_since', 'watermark'] as $parameter) {
                $page = $this->getJson($path.'?'.http_build_query([$parameter => '2026-10-01T12:00:00Z', 'limit' => 1]))->assertOk();
                $this->assertSame($voided->public_id, $page->json('data.0.id'));
                $this->assertSame('2026-10-01T12:00:00+00:00', $page->json('meta.watermark'));
                $this->assertNotNull($page->json('meta.next_cursor'));
                $next = $this->getJson($path.'?'.http_build_query([$parameter => '2026-10-01T12:00:00Z', 'limit' => 1, 'cursor' => $page->json('meta.next_cursor')]))->assertOk();
                $this->assertSame($hidden->public_id, $next->json('data.0.id'));
                $this->assertNull($next->json('meta.next_cursor'));
            }
        }
    }

    public function test_foreign_workspace_change_feed_keeps_the_existing_not_found_response(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:02:00');
        [, $workspace] = $this->workspace();
        $this->actingAsMcp(User::factory()->create(), [AgentApiScopes::BILLING_READ]);
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices?updated_since=2026-01-01T00:00:00Z")->assertNotFound();
    }

    public function test_mcp_keeps_portal_snapshots_and_refuses_unsupported_change_feed_arguments(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [, $workspace, $company] = $this->workspace();
        $portal = User::factory()->create();
        $company->portalUsers()->attach($portal, ['role' => 'client']);
        $visible = $this->invoice($workspace, $company);
        $removed = $this->invoice($workspace, $company);
        $removed->forceFill(['is_visible_to_client' => false])->save();
        $this->actingAsMcp($portal, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ]);
        $initialization = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic invoice feed test', 'version' => '1']],
        ], ['Mcp-Protocol-Version' => '2025-06-18'])->assertOk();
        $session = $initialization->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);
        $snapshot = $this->mcpInvoices($session, ['workspace_id' => $workspace->public_id])->assertOk();
        $this->assertFalse($snapshot->json('result.isError'));
        $this->assertSame([$visible->public_id], array_column($snapshot->json('result.structuredContent.data'), 'id'));

        foreach (['updated_since', 'watermark'] as $parameter) {
            $response = $this->mcpInvoices($session, ['workspace_id' => $workspace->public_id, $parameter => '2026-10-01T12:00:00Z'])->assertOk();
            $response->assertJsonPath('error.code', -32602);
            $this->assertStringContainsString($parameter, $response->getContent());
            $this->assertStringNotContainsString($removed->public_id, $response->getContent());
            $this->assertStringNotContainsString($visible->public_id, $response->getContent());
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return TestResponse<Response>
     */
    private function mcpInvoices(string $session, array $arguments): TestResponse
    {
        return $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'invoices.list', 'arguments' => $arguments],
        ], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session]);
    }

    /** @return array{User, Workspace, ClientCompany} */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic invoice feed', 'slug' => 'synthetic-feed-'.Str::lower(Str::random(8))]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic feed client', 'slug' => 'synthetic-feed-client']);

        return [$owner, $workspace, $company];
    }

    private function invoice(Workspace $workspace, ClientCompany $company): ClientInvoice
    {
        $invoice = app(InvoiceLifecycleService::class)->createDraft($workspace, $company, [
            'invoice_number' => 'SYNTHETIC-'.Str::upper(Str::random(10)), 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic feed service', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $invoice->forceFill(['status' => 'issued', 'is_visible_to_client' => true])->save();

        return $invoice;
    }
}
