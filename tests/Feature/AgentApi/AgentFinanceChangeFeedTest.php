<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\IssuesAgentOAuthTokens;
use Tests\TestCase;

/**
 * `finance.invoice_payments.list` as an incremental change feed (S2): naming
 * `updated_since`, `watermark` or `cursor` pages payments newest change first
 * inside a snapshot watermark; `status=any` returns every status. Without
 * them the listing is numbered pages, as before.
 */
final class AgentFinanceChangeFeedTest extends TestCase
{
    use IssuesAgentOAuthTokens;
    use RefreshDatabase;

    public function test_a_connection_reads_the_feed_through_the_real_oauth_flow(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $payment] = $this->payment('Feed flow');
        $tokens = $this->issueAgentTokens($owner, 'rest', [AgentApiScopes::FINANCE_READ]);
        $this->readAt('2026-10-01 12:02:00');

        $feed = $this->withToken($tokens['access_token'])->getJson($this->feedPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $payment->public_id)
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.watermark', '2026-10-01T12:00:00+00:00')
            ->assertJsonMissingPath('links');
        $this->assertMatchesSchema($feed);

        // Without a window: numbered pages, as before.
        $this->app['auth']->forgetGuards();
        $pages = $this->withToken($tokens['access_token'])->getJson($this->listPath($workspace))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonMissingPath('meta.watermark');
        $this->assertMatchesSchema($pages);
    }

    public function test_the_watermark_lags_the_request_so_a_late_commit_is_read_by_the_next_pass(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 11:00:00');
        [$owner, $workspace, $early] = $this->payment('Late commit');
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ]);

        // A pass at 12:00 reads up to two minutes before the request.
        $this->readAt('2026-10-01 12:00:00');
        $first = $this->getJson($this->feedPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z']))->assertOk();
        $this->assertSame([$early->public_id], array_column($first->json('data'), 'id'));
        $this->assertSame('2026-10-01T11:58:00+00:00', $first->json('meta.watermark'));

        // A write stamped 11:59:30, before that pass began, whose transaction
        // commits only after it read: invisible to the first pass.
        $late = $this->anotherPayment($workspace, 1000);
        $this->stamp($late, '2026-10-01 11:59:30');

        // The next pass starts from the first one's watermark and finds it.
        $this->readAt('2026-10-01 12:15:00');
        $next = $this->getJson($this->feedPath($workspace, ['updated_since' => $first->json('meta.watermark')]))->assertOk();
        $this->assertSame([$late->public_id], array_column($next->json('data'), 'id'));

        // A caller may not move a watermark into the lag either.
        $this->getJson($this->feedPath($workspace, ['watermark' => '2026-10-01T12:14:00Z']))->assertUnprocessable()->assertJsonValidationErrors('watermark');
        $this->getJson($this->feedPath($workspace, ['watermark' => '2026-10-01T12:13:00Z']))->assertOk();
    }

    public function test_a_pass_pages_newest_change_first_inside_one_watermark(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $first] = $this->payment('Paging');
        $second = $this->anotherPayment($workspace, 1000);
        $third = $this->anotherPayment($workspace, 1500);
        // Two share a second, so the id breaks the tie.
        $this->stamp($first, '2026-10-01 10:00:00');
        $this->stamp($second, '2026-10-01 11:00:00');
        $this->stamp($third, '2026-10-01 11:00:00');
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ]);
        $this->readAt('2026-10-01 12:02:00');

        $page = $this->getJson($this->feedPath($workspace, ['per_page' => 2, 'updated_since' => '2026-01-01T00:00:00Z']))->assertOk();
        $this->assertSame([$third->public_id, $second->public_id], array_column($page->json('data'), 'id'));
        $this->assertSame('2026-10-01T12:00:00+00:00', $page->json('meta.watermark'));
        $cursor = (string) $page->json('meta.next_cursor');
        $this->assertNotSame('', $cursor);

        // Changed after the pass's watermark: left for the next pass.
        $this->readAt('2026-10-01 12:02:30');
        $this->stamp($second, '2026-10-01 12:00:30');

        $next = $this->getJson($this->feedPath($workspace, ['per_page' => 2, 'updated_since' => '2026-01-01T00:00:00Z', 'cursor' => $cursor]))->assertOk();
        $this->assertSame([$first->public_id], array_column($next->json('data'), 'id'));
        $this->assertSame('2026-10-01T12:00:00+00:00', $next->json('meta.watermark'));
        $this->assertNull($next->json('meta.next_cursor'));
        $this->getJson($this->feedPath($workspace, ['per_page' => 2, 'updated_since' => '2026-01-01T00:00:00Z', 'cursor' => $cursor, 'watermark' => '2026-10-01T12:00:00Z']))->assertOk();

        // The next pass starts from the watermark, inclusively, and re-reads
        // what sits on it: deduplicated by (id, version).
        $this->stamp($first, '2026-10-01 12:00:00');
        $this->readAt('2026-10-01 12:04:30');
        $incremental = $this->getJson($this->feedPath($workspace, ['updated_since' => '2026-10-01T12:00:00Z']))->assertOk();
        $this->assertSame([$second->public_id, $first->public_id], array_column($incremental->json('data'), 'id'));
        $this->assertSame('2026-10-01T12:02:30+00:00', $incremental->json('meta.watermark'));
    }

    public function test_a_row_never_stamped_comes_last_in_a_full_pass_and_never_matches_updated_since(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $stamped] = $this->payment('Unstamped');
        $unstamped = $this->anotherPayment($workspace, 1000);
        $alsoUnstamped = $this->anotherPayment($workspace, 1200);
        $this->stamp($stamped, '2026-10-01 09:00:00');
        ClientInvoicePayment::query()->whereKey([$unstamped->id, $alsoUnstamped->id])->toBase()->update(['updated_at' => null]);
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ]);
        $this->readAt('2026-10-01 12:02:00');

        $seen = [];
        $query = ['per_page' => 1, 'watermark' => '2026-10-01T12:00:00Z'];
        do {
            $page = $this->getJson($this->feedPath($workspace, $query))->assertOk();
            $seen = [...$seen, ...array_column($page->json('data'), 'id')];
            $query['cursor'] = $page->json('meta.next_cursor');
        } while (is_string($query['cursor']));
        $this->assertSame([$stamped->public_id, $alsoUnstamped->public_id, $unstamped->public_id], $seen);
        $this->assertSame([$stamped->public_id], array_column($this->getJson($this->feedPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z']))->json('data'), 'id'));
    }

    public function test_a_cursor_is_refused_outside_its_query_workspace_and_watermark(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $payment] = $this->payment('Cursor binding');
        $this->anotherPayment($workspace, 1000);
        [, $otherWorkspace] = $this->payment('Cursor elsewhere', 5000, $owner);
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ]);
        $this->readAt('2026-10-01 12:02:00');
        $cursor = (string) $this->getJson($this->feedPath($workspace, ['per_page' => 1, 'watermark' => '2026-10-01T12:00:00Z']))->json('meta.next_cursor');

        $this->getJson($this->feedPath($workspace, ['per_page' => 1, 'cursor' => $cursor, 'invoice' => $payment->invoice()->value('public_id')]))->assertUnprocessable();
        $this->getJson($this->feedPath($workspace, ['per_page' => 1, 'cursor' => $cursor, 'updated_since' => '2026-01-01T00:00:00Z']))->assertUnprocessable();
        $this->getJson($this->feedPath($workspace, ['per_page' => 1, 'cursor' => $cursor, 'watermark' => '2026-10-01T11:00:00Z']))->assertUnprocessable();
        $this->getJson($this->feedPath($otherWorkspace, ['per_page' => 1, 'cursor' => $cursor]))->assertUnprocessable();
        $this->getJson($this->feedPath($workspace, ['cursor' => 'not-a-cursor']))->assertUnprocessable();
        $this->getJson($this->feedPath($workspace, ['watermark' => '2026-10-01T12:00:01Z']))->assertUnprocessable()->assertJsonValidationErrors('watermark');
        $this->getJson($this->feedPath($workspace, ['cursor' => $cursor, 'page' => 2]))->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson($this->feedPath($workspace, ['per_page' => 1, 'cursor' => $cursor]))->assertOk();
    }

    public function test_a_change_feed_reads_every_status_and_refuses_filters_a_payment_can_change_out_of(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $succeeded] = $this->payment('Statuses');
        $canceled = $this->anotherPayment($workspace, 1000);
        app(InvoiceLifecycleService::class)->setPaymentStatus($canceled, 'canceled', $workspace);
        $this->stamp($succeeded, '2026-09-01 00:00:00');
        $this->stamp($canceled, '2026-09-20 00:00:00');
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ]);
        $this->readAt('2026-10-01 12:02:00');
        $window = ['updated_since' => '2026-01-01T00:00:00Z'];

        $this->assertSame([$canceled->public_id, $succeeded->public_id], array_column($this->getJson($this->feedPath($workspace, $window))->json('data'), 'id'));
        $this->assertSame([$canceled->public_id], array_column($this->getJson($this->feedPath($workspace, ['updated_since' => '2026-09-20T00:00:00Z']))->json('data'), 'id'));
        // The invoice a payment belongs to never changes, so it may filter.
        $this->assertSame([$succeeded->public_id], array_column($this->getJson($this->feedPath($workspace, [...$window, 'invoice' => $succeeded->invoice()->value('public_id')]))->json('data'), 'id'));

        // Anything a payment can change out of is refused, naming it.
        $this->getJson($this->listPath($workspace, $window))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson($this->listPath($workspace, [...$window, 'status' => 'succeeded']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson($this->feedPath($workspace, [...$window, 'received_from' => '2026-01-01']))->assertUnprocessable()->assertJsonValidationErrors('received_from');
        $this->getJson($this->feedPath($workspace, [...$window, 'received_to' => '2026-12-31']))->assertUnprocessable()->assertJsonValidationErrors('received_to');

        // Numbered pages filter on all of them as before.
        $this->assertCount(2, $this->getJson($this->listPath($workspace, ['status' => 'any']))->assertJsonPath('meta.total', 2)->json('data'));
        $this->assertSame([$succeeded->public_id], array_column($this->getJson($this->listPath($workspace))->json('data'), 'id'));
        $this->getJson($this->listPath($workspace, ['status' => 'paid']))->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_status_any_never_returns_a_status_outside_the_contract(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $succeeded] = $this->payment('Unreadable status');
        $legacy = $this->anotherPayment($workspace, 1000);
        // A status nothing in SVC writes, as an old import might have left it.
        ClientInvoicePayment::query()->whereKey($legacy->id)->toBase()->update(['status' => 'paid']);
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ]);
        $this->readAt('2026-10-01 12:02:00');

        $feed = $this->getJson($this->feedPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z']))->assertOk();
        $this->assertSame([$succeeded->public_id], array_column($feed->json('data'), 'id'));
        $this->assertMatchesSchema($feed);
        $pages = $this->getJson($this->listPath($workspace, ['status' => 'any']))->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame([$succeeded->public_id], array_column($pages->json('data'), 'id'));
        $this->assertMatchesSchema($pages);
    }

    public function test_the_finance_token_reads_the_feed_too(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $payment] = $this->payment('Finance token feed');
        $token = $owner->createToken('finance', ['finance.read'], CarbonImmutable::now()->addHour())->plainTextToken;
        $this->readAt('2026-10-01 12:02:00');

        $this->withToken($token)->getJson($this->feedPath($workspace, ['updated_since' => '2026-01-01T00:00:00Z']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $payment->public_id)
            ->assertJsonPath('meta.watermark', '2026-10-01T12:00:00+00:00');
    }

    /** The clock a later read happens at. */
    private function readAt(string $time): void
    {
        CarbonImmutable::setTestNow($time);
    }

    /**
     * A change-feed request, which reads every status.
     *
     * @param  array<string, int|string|null>  $query
     */
    private function feedPath(Workspace $workspace, array $query): string
    {
        return $this->listPath($workspace, ['status' => 'any', ...$query]);
    }

    /** @param  TestResponse<Response>  $response */
    private function assertMatchesSchema(TestResponse $response): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($response->json(), AgentApiResponseSchemaCatalog::forOperation('finance.invoice_payments.list')));
    }

    /** @param  array<string, int|string|null>  $query */
    private function listPath(Workspace $workspace, array $query = []): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/invoice-payments".($query === [] ? '' : '?'.http_build_query($query));
    }

    private function stamp(ClientInvoicePayment $payment, string $at): void
    {
        ClientInvoicePayment::query()->whereKey($payment->id)->toBase()->update(['updated_at' => $at]);
    }

    private function anotherPayment(Workspace $workspace, int $amount): ClientInvoicePayment
    {
        return $this->paidInvoice($workspace, ClientCompany::query()->where('workspace_id', $workspace->id)->firstOrFail(), $amount);
    }

    /** @return array{0: User, 1: Workspace, 2: ClientInvoicePayment} */
    private function payment(string $name, int $amount = 5000, ?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5))]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name.' Client',
            'slug' => Str::slug($name).'-client-'.Str::lower(Str::random(5)),
        ]);

        return [$owner, $workspace, $this->paidInvoice($workspace, $company, $amount)];
    }

    private function paidInvoice(Workspace $workspace, ClientCompany $company, int $amount): ClientInvoicePayment
    {
        $invoices = app(InvoiceLifecycleService::class);
        $invoice = $invoices->createDraft($workspace, $company, [
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Synthetic finance service',
            'quantity' => '1',
            'unit_amount' => $amount,
            'tax_amount' => 0,
        ]]);
        $invoices->issue($invoice, $workspace);

        return $invoices->applyPayment($invoice, [
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'wire',
            'status' => 'succeeded',
            'idempotency_key' => 'finance-'.Str::random(16),
        ], $workspace);
    }
}
