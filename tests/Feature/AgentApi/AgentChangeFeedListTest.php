<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * `updated_since` and the change-feed cursor on invoices.list and clients.list
 * (S3/S4): naming either part of the window switches a listing from id order
 * to newest change first inside a snapshot watermark, as the finance payment
 * feed pages; without one, both page by id exactly as before.
 */
final class AgentChangeFeedListTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoices_list_pages_changes_since_a_time_inside_one_watermark(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $company] = $this->workspace('Invoice feed');
        $old = $this->invoice($workspace, $company);
        $first = $this->invoice($workspace, $company);
        $second = $this->invoice($workspace, $company);
        $this->stamp($old, '2026-08-01 00:00:00');
        $this->stamp($first, '2026-09-15 00:00:00');
        $this->stamp($second, '2026-09-20 00:00:00');
        $this->actingAsMcp($owner, [AgentApiScopes::BILLING_READ]);
        $path = "/api/v1/workspaces/{$workspace->public_id}/invoices";

        $page = $this->getJson($path.'?'.http_build_query(['updated_since' => '2026-09-01T00:00:00Z', 'limit' => 1]))->assertOk();
        $this->assertMatchesSchema('invoices.list', $page);
        $this->assertSame([$second->public_id], array_column($page->json('data'), 'id'));
        $this->assertSame('2026-10-01T12:00:00+00:00', $page->json('meta.watermark'));

        // Changed after the pass began: not on this pass's later pages.
        CarbonImmutable::setTestNow('2026-10-01 12:05:00');
        $this->stamp($old, '2026-10-01 12:05:00');
        $next = $this->getJson($path.'?'.http_build_query(['updated_since' => '2026-09-01T00:00:00Z', 'limit' => 1, 'cursor' => $page->json('meta.next_cursor')]))->assertOk();
        $this->assertSame([$first->public_id], array_column($next->json('data'), 'id'));
        $this->assertNull($next->json('meta.next_cursor'));

        $following = $this->getJson($path.'?'.http_build_query(['updated_since' => '2026-10-01T12:00:00Z']))->assertOk();
        $this->assertSame([$old->public_id], array_column($following->json('data'), 'id'));

        // Without a window: id order and no watermark, as before.
        $legacy = $this->getJson($path)->assertOk();
        $this->assertMatchesSchema('invoices.list', $legacy);
        $this->assertSame([$old->public_id, $first->public_id, $second->public_id], array_column($legacy->json('data'), 'id'));
        $this->assertArrayNotHasKey('watermark', (array) $legacy->json('meta'));

        // A cursor from one mode is refused in the other.
        $legacyCursor = (string) $this->getJson($path.'?limit=1')->json('meta.next_cursor');
        $this->getJson($path.'?'.http_build_query(['updated_since' => '2026-09-01T00:00:00Z', 'cursor' => $legacyCursor]))->assertUnprocessable();
        $this->getJson($path.'?'.http_build_query(['limit' => 1, 'cursor' => $page->json('meta.next_cursor')]))->assertUnprocessable();
        $this->getJson($path.'?'.http_build_query(['watermark' => '2026-10-01T13:00:00Z']))->assertUnprocessable()->assertJsonValidationErrors('watermark');
        $this->getJson($path.'?'.http_build_query(['updated_since' => 'not a time']))->assertUnprocessable()->assertJsonValidationErrors('updated_since');
    }

    public function test_invoices_list_keeps_its_filters_and_visibility_in_change_order(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $company] = $this->workspace('Invoice filters');
        $other = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other Client', 'slug' => 'other-'.Str::lower(Str::random(5))]);
        $mine = $this->invoice($workspace, $company);
        $theirs = $this->invoice($workspace, $other);
        $this->actingAsMcp($owner, [AgentApiScopes::BILLING_READ]);

        $filtered = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices?".http_build_query(['updated_since' => '2026-01-01T00:00:00Z', 'company_id' => $company->public_id]))->assertOk();
        $this->assertSame([$mine->public_id], array_column($filtered->json('data'), 'id'));
        $this->assertNotSame($mine->public_id, $theirs->public_id);

        $outsider = User::factory()->create();
        $this->actingAsMcp($outsider, [AgentApiScopes::BILLING_READ]);
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices?updated_since=2026-01-01T00:00:00Z")->assertNotFound();
    }

    public function test_clients_list_pages_changes_since_a_time_for_managers_only(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $company] = $this->workspace('Client feed');
        $archived = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Archived Client', 'slug' => 'archived-'.Str::lower(Str::random(5)), 'is_active' => false]);
        $untouched = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Untouched Client', 'slug' => 'untouched-'.Str::lower(Str::random(5))]);
        $this->stamp($company, '2026-09-10 00:00:00');
        $this->stamp($archived, '2026-09-12 00:00:00');
        $this->stamp($untouched, '2026-07-01 00:00:00');
        $this->actingAsMcp($owner, [AgentApiScopes::CLIENTS_READ]);
        $path = "/api/v1/workspaces/{$workspace->public_id}/clients";

        $page = $this->getJson($path.'?'.http_build_query(['updated_since' => '2026-09-01T00:00:00Z', 'limit' => 1]))->assertOk();
        $this->assertMatchesSchema('clients.list', $page);
        $this->assertSame([$archived->public_id], array_column($page->json('data'), 'id'));
        $this->assertSame('2026-10-01T12:00:00+00:00', $page->json('meta.watermark'));
        $next = $this->getJson($path.'?'.http_build_query(['updated_since' => '2026-09-01T00:00:00Z', 'limit' => 1, 'cursor' => $page->json('meta.next_cursor'), 'watermark' => '2026-10-01T12:00:00Z']))->assertOk();
        $this->assertSame([$company->public_id], array_column($next->json('data'), 'id'));
        $this->assertNull($next->json('meta.next_cursor'));

        $legacy = $this->getJson($path)->assertOk();
        $this->assertMatchesSchema('clients.list', $legacy);
        $this->assertSame([$company->public_id, $archived->public_id, $untouched->public_id], array_column($legacy->json('data'), 'id'));

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, [AgentApiScopes::CLIENTS_READ]);
        $this->getJson($path.'?updated_since=2026-01-01T00:00:00Z')->assertNotFound();
    }

    /** @param  TestResponse<Response>  $response */
    private function assertMatchesSchema(string $operation, TestResponse $response): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($response->json(), AgentApiResponseSchemaCatalog::forOperation($operation)), $operation);
    }

    private function stamp(Model $model, string $at): void
    {
        $model->newQuery()->whereKey($model->getKey())->toBase()->update(['updated_at' => $at]);
    }

    /** @return array{0: User, 1: Workspace, 2: ClientCompany} */
    private function workspace(string $name): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5))]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name.' Client',
            'slug' => Str::slug($name).'-client-'.Str::lower(Str::random(5)),
        ]);

        return [$owner, $workspace, $company];
    }

    private function invoice(Workspace $workspace, ClientCompany $company): ClientInvoice
    {
        return app(InvoiceLifecycleService::class)->createDraft($workspace, $company, [
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Synthetic feed service',
            'quantity' => '1',
            'unit_amount' => 1000,
            'tax_amount' => 0,
        ]]);
    }
}
