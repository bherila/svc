<?php

namespace Tests\Feature\Square;

use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceSquareLink;
use App\Models\SquareSyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\SquareInvoiceReconciliation;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Mcp\Capability\Discovery\SchemaValidator;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\FakesSquarePaymentSync;
use Tests\TestCase;

/**
 * Square operability (#458): the status probe, and reconciling one linked
 * invoice now through the one service the command, REST and MCP share.
 */
final class SquareOperabilityTest extends TestCase
{
    use CallsMcp;
    use FakesSquarePaymentSync;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'billing:read', 'billing:write'];

    public function test_one_linked_invoice_is_reconciled_now_over_rest_and_mcp_without_writing_to_square(): void
    {
        [$workspace, $owner, , $invoice] = $this->paymentFixture();
        config(['agent_api.writes_enabled' => true]);
        $this->actingAsMcp($owner, self::SCOPES);

        $rest = $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-reconcile-1'])->assertOk()->json();

        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($rest, AgentApiResponseSchemaCatalog::forOperation('invoices.square_reconcile')));
        $this->assertSame([$invoice->public_id, 'inv_fixture_1'], [$rest['data']['invoice_id'], $rest['data']['link']['square_invoice_id']]);
        $this->assertSame(['paid', 0], [$invoice->fresh()->status, $invoice->fresh()->balance_amount]);
        $this->assertSame('pay_fixture_1', $invoice->payments()->firstOrFail()->provider_payment_identifier);

        // Again, by MCP and by a repeated key: one payment, the same answer.
        $mcp = $this->callTool($this->initialize(), 'invoices.square_reconcile', ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoice->public_id, 'idempotency_key' => 'synthetic-reconcile-2']);
        $this->assertFalse($mcp['result']['isError'] ?? false, (string) json_encode($mcp));
        $this->assertSame($rest['data']['link'], $mcp['result']['structuredContent']['data']['link']);
        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-reconcile-1'])->assertOk();
        $this->assertSame(1, $invoice->payments()->count());
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_reconciling_is_refused_for_unlinked_foreign_and_unmanaged_invoices(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->paymentFixture();
        config(['agent_api.writes_enabled' => true]);
        $unlinked = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-002', 'invoice_kind' => 'ad_hoc', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 100, 'balance_amount' => 100]);
        $this->actingAsMcp($owner, self::SCOPES);

        $this->postJson($this->url($workspace, $unlinked), [], ['Idempotency-Key' => 'synthetic-unlinked'])->assertUnprocessable();
        $this->postJson($this->url($workspace, $invoice), ['square_invoice_id' => 'inv_fixture_1'], ['Idempotency-Key' => 'synthetic-body'])->assertUnprocessable();
        $other = Workspace::query()->create(['name' => 'Synthetic reconcile other', 'slug' => 'synthetic-reconcile-other']);
        $other->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        // Square serves one workspace: another one's id never reaches this invoice.
        $this->postJson('/api/v1/workspaces/'.$other->public_id.'/invoices/'.$invoice->public_id.'/square-reconcile', [], ['Idempotency-Key' => 'synthetic-foreign'])->assertNotFound();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, self::SCOPES);
        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-member'])->assertForbidden();
        $this->assertSame(0, $invoice->payments()->count());
        $this->assertSame(0, Http::recorded()->count());
    }

    public function test_the_receipt_and_audit_record_the_outcome_and_reads_are_budgeted(): void
    {
        [$workspace, $owner, , $invoice] = $this->paymentFixture();
        config(['agent_api.writes_enabled' => true]);
        $this->actingAsMcp($owner, self::SCOPES);
        $audits = static fn (string $outcome): int => AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'invoices.square_reconcile')->where('outcome', $outcome)->count();

        $this->squareInvoiceFailureStatus = 503;
        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-outage'])->assertStatus(503);
        $this->assertSame([0, 1], [$audits('success'), $audits('failed')]);
        $this->assertSame(0, AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('operation', 'invoices.square_reconcile')->count());

        // The same key, once Square answers, reconciles: the failure left no receipt.
        $this->squareInvoiceFailureStatus = null;
        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-outage'])->assertOk();
        $this->assertSame([1, 1], [$audits('success'), $audits('failed')]);
        $this->assertSame('paid', $invoice->fresh()->status);

        // Replaying that completed key while Square is down is a failure, not a successful replay.
        $this->squareInvoiceFailureStatus = 503;
        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-outage'])->assertStatus(503);
        $this->assertSame([1, 2, 0], [$audits('success'), $audits('failed'), $audits('replay')]);
        $this->squareInvoiceFailureStatus = null;
        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-outage'])->assertOk();
        $this->assertSame(1, $audits('replay'));

        for ($attempt = 5; $attempt <= SquareInvoiceReconciliation::RECONCILES_PER_WINDOW; $attempt++) {
            $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-budget-'.$attempt])->assertOk();
        }
        $before = Http::recorded()->count();
        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-over-budget'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame($before, Http::recorded()->count(), 'Over budget, Square is not read.');
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_a_replayed_key_applies_what_square_says_now(): void
    {
        [$workspace, $owner, , $invoice] = $this->paymentFixture();
        config(['agent_api.writes_enabled' => true]);
        $this->actingAsMcp($owner, self::SCOPES);
        $this->paymentInvoice['status'] = 'UNPAID';
        $this->squarePayments['pay_fixture_1']['status'] = 'PENDING';

        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-early'])->assertOk();
        $this->assertSame(0, $invoice->payments()->count());

        $this->paymentInvoice['status'] = 'PAID';
        $this->squarePayments['pay_fixture_1']['status'] = 'COMPLETED';
        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-early'])->assertOk();
        $this->assertSame(['paid', 1], [$invoice->fresh()->status, $invoice->payments()->count()]);
    }

    public function test_a_square_invoice_re_linked_before_square_is_read_records_nothing_anywhere(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->paymentFixture();
        config(['agent_api.writes_enabled' => true]);
        $other = $this->otherInvoice($workspace, $company);
        // Another manager re-points the Square invoice after the request has
        // found its link, before Square is read: the budget check sits between.
        $relink = fn () => $this->relink($workspace, $owner, $invoice, $other);
        $this->app->instance(RateLimiter::class, new class(app('cache')->store(), $relink) extends RateLimiter
        {
            public function __construct(Repository $cache, private \Closure $relink)
            {
                parent::__construct($cache);
            }

            public function attempt($key, $maxAttempts, \Closure $callback, $decaySeconds = 60)
            {
                ($this->relink)();

                return parent::attempt($key, $maxAttempts, $callback, $decaySeconds);
            }
        });
        $this->actingAsMcp($owner, self::SCOPES);

        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-relinked'])->assertUnprocessable()
            ->assertJsonPath('message', 'The invoice\'s Square link changed while it was being reconciled. Read the invoice and try again.');

        $this->assertSame([0, 0], [$invoice->payments()->count(), $other->payments()->count()]);
    }

    public function test_a_square_invoice_re_linked_while_square_is_read_records_nothing_anywhere(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->paymentFixture();
        config(['agent_api.writes_enabled' => true]);
        $other = $this->otherInvoice($workspace, $company);
        $relinked = false;
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($workspace, $owner, $invoice, $other, &$relinked) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
            if ($path === '/v2/invoices/inv_fixture_1') {
                if (! $relinked) {
                    $relinked = true;
                    $this->relink($workspace, $owner, $invoice, $other);
                }

                return Http::response(['invoice' => $this->paymentInvoice]);
            }
            if ($path === '/v2/orders/order_fixture_1') {
                return Http::response(['order' => $this->paymentOrder]);
            }

            return Http::response(['payment' => $this->squarePayments[substr($path, strlen('/v2/payments/'))] ?? null]);
        });
        $this->actingAsMcp($owner, self::SCOPES);

        $this->postJson($this->url($workspace, $invoice), [], ['Idempotency-Key' => 'synthetic-relinked'])->assertUnprocessable();

        $this->assertTrue($relinked);
        $this->assertSame([0, 0], [$invoice->payments()->count(), $other->payments()->count()]);
    }

    public function test_the_command_reconciles_one_invoice_by_its_public_id(): void
    {
        [$workspace, , $company, $invoice] = $this->paymentFixture();
        $unlinked = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-002', 'invoice_kind' => 'ad_hoc', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 100, 'balance_amount' => 100]);

        $this->artisan('svc:square:reconcile-payments', ['--invoice' => 'not-an-id'])->assertExitCode(2);
        $this->artisan('svc:square:reconcile-payments', ['--invoice' => null])->assertExitCode(2);
        $this->assertSame(0, Http::recorded()->count(), 'A bare --invoice never starts the sweep.');
        $this->artisan('svc:square:reconcile-payments', ['--invoice' => '00000000-0000-4000-8000-000000000999'])->assertFailed();
        $this->artisan('svc:square:reconcile-payments', ['--invoice' => $unlinked->public_id])->expectsOutputToContain('not linked to a Square invoice')->assertFailed();
        $this->assertSame(0, Http::recorded()->count());
        $this->artisan('svc:square:reconcile-payments', ['--invoice' => $invoice->public_id])->expectsOutputToContain('SYNTHETIC-001 reconciled with Square: paid, balance 0 USD')->assertSuccessful();
        $this->assertSame(1, $invoice->payments()->count());
        $this->squareInvoiceFailureStatus = 503;
        $this->artisan('svc:square:reconcile-payments', ['--invoice' => $invoice->public_id])->assertFailed();
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');

        // Asked for one invoice with Square unconfigured, it did nothing, and says so.
        config(['services.square.access_token' => null]);
        $this->artisan('svc:square:reconcile-payments', ['--invoice' => $invoice->public_id])->expectsOutputToContain('Square is not configured, so the invoice was not reconciled.')->assertFailed();
        $this->artisan('svc:square:reconcile-payments')->assertSuccessful();
    }

    public function test_status_reports_configuration_and_the_last_sync_without_a_credential(): void
    {
        [$workspace] = $this->paymentFixture();

        // Configured but never synced: overdue.
        $this->assertSame(1, Artisan::call('svc:square:status', ['--format' => 'json']));
        $output = Artisan::output();
        $this->assertStringNotContainsString(self::SQUARE_TOKEN, $output);
        $this->assertStringNotContainsString('fixture-signature-key', $output);
        $this->assertSame(['provider' => 'square', 'configured' => true, 'environment' => 'sandbox', 'square_version' => '2026-09-16', 'workspace_present' => true, 'webhook_signature_key_present' => true, 'last_synced_at' => null, 'last_run' => null, 'last_run_abandoned' => false, 'sync_overdue' => true], json_decode($output, true, flags: JSON_THROW_ON_ERROR));

        $run = SquareSyncRun::query()->create(['workspace_id' => $workspace->id, 'trigger' => SquareSyncRun::TRIGGER_SCHEDULE, 'status' => SquareSyncRun::COMPLETED, 'api_version' => '2026-09-16', 'started_at' => '2030-02-02 11:50:00', 'finished_at' => '2030-02-02 11:51:00', 'invoices_seen' => 3, 'invoices_changed' => 1]);
        $this->assertSame(0, Artisan::call('svc:square:status', ['--format' => 'json']));
        $status = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2030-02-02T11:51:00Z', $status['last_synced_at']);
        $this->assertSame([$run->public_id, 'completed', 'schedule', 3, 1, null], [$status['last_run']['id'], $status['last_run']['status'], $status['last_run']['trigger'], $status['last_run']['invoices_seen'], $status['last_run']['invoices_changed'], $status['last_run']['error_code']]);
        $this->assertSame([false, false], [$status['last_run_abandoned'], $status['sync_overdue']]);

        // A run left running past the abandonment threshold: the scheduler stopped.
        $stuck = SquareSyncRun::query()->create(['workspace_id' => $workspace->id, 'trigger' => SquareSyncRun::TRIGGER_SCHEDULE, 'status' => SquareSyncRun::RUNNING, 'api_version' => '2026-09-16', 'started_at' => '2030-02-02 11:59:00']);
        $this->assertSame(0, Artisan::call('svc:square:status', ['--format' => 'json']), 'Running, and recent.');
        $this->travel(31)->minutes();
        $this->assertSame(1, Artisan::call('svc:square:status', ['--format' => 'json']));
        $this->assertSame([true, false], array_values(array_intersect_key(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), array_flip(['last_run_abandoned', 'sync_overdue']))));
        $this->artisan('svc:square:status')->expectsOutputToContain('running (schedule), abandoned')->assertFailed();
        $stuck->delete();
        $this->travel(30)->minutes();
        $this->assertSame(1, Artisan::call('svc:square:status', ['--format' => 'json']), 'No completed sync for over an hour.');
        $this->assertSame([false, true], array_values(array_intersect_key(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), array_flip(['last_run_abandoned', 'sync_overdue']))));
        $this->travelBack();
        Date::setTestNow('2030-02-02 12:00:00');

        SquareSyncRun::query()->create(['workspace_id' => $workspace->id, 'trigger' => SquareSyncRun::TRIGGER_SCHEDULE, 'status' => SquareSyncRun::FAILED, 'api_version' => '2026-09-16', 'error_code' => 'unauthorized']);
        $this->artisan('svc:square:status')->expectsOutputToContain('failed (schedule), unauthorized')->assertFailed();
        $this->assertSame(1, Artisan::call('svc:square:status', ['--format' => 'json']));
        $this->assertSame('failed', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['last_run']['status']);
        $this->assertSame(0, Http::recorded()->count(), 'The probe never calls Square.');
    }

    public function test_status_exits_one_when_square_is_not_configured_or_its_workspace_is_gone(): void
    {
        $this->connectSquare(null);
        $this->assertSame(1, Artisan::call('svc:square:status', ['--format' => 'json']));
        $this->assertSame(['provider' => 'square', 'configured' => false, 'environment' => null, 'square_version' => null, 'workspace_present' => false, 'webhook_signature_key_present' => false, 'last_synced_at' => null, 'last_run' => null, 'last_run_abandoned' => false, 'sync_overdue' => false], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));

        config(['services.square.workspace' => '00000000-0000-4000-8000-000000000999']);
        $this->assertSame(1, Artisan::call('svc:square:status', ['--format' => 'json']));
        $this->assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['workspace_present']);
        $this->artisan('svc:square:status', ['--format' => 'yaml'])->assertExitCode(2);
    }

    private function otherInvoice(Workspace $workspace, ClientCompany $company): ClientInvoice
    {
        return ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-OTHER', 'invoice_kind' => 'ad_hoc', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 12_345, 'balance_amount' => 12_345, 'is_visible_to_client' => true]);
    }

    private function relink(Workspace $workspace, User $owner, ClientInvoice $from, ClientInvoice $to): void
    {
        if (InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->where('active_invoice_slot', $to->id)->exists()) {
            return;
        }
        app(SquareLinkService::class)->unlink($workspace, $from->refresh(), $owner, AgentApiVersion::for($from));
        app(SquareLinkService::class)->link($workspace, $to->refresh(), $owner, AgentApiVersion::for($to), new SquareInvoiceReference('inv_fixture_1', '000001-R-0001', 'USD', SquareMatchMethod::Manual), false);
    }

    private function url(Workspace $workspace, ClientInvoice $invoice): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/invoices/'.$invoice->public_id.'/square-reconcile';
    }
}
