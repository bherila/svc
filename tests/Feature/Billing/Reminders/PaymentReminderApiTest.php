<?php

namespace Tests\Feature\Billing\Reminders;

use App\Mail\InvoicePaymentReminderMail;
use App\Models\AgentMutationAudit;
use App\Models\InvoiceReminderLog;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Reminders\PaymentReminderRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsPaymentReminders;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * Payment reminders over REST, MCP and the website's session (#438): one
 * operation each way, the same receipts, refusals and log.
 */
final class PaymentReminderApiTest extends TestCase
{
    use BuildsPaymentReminders;
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'clients:read', 'billing:read', 'billing:write'];

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2030-03-15 14:00:00');
        config(['app.url' => 'https://svc.example.test', 'agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        Mail::fake();
    }

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_a_manager_reads_sends_and_replays_a_reminder(string $transport): void
    {
        [$workspace, $owner, , $invoice] = $this->reminderFixture();
        $this->actingAsMcp($owner, self::SCOPES);

        $before = $this->success($transport, 'invoices.reminders.list', $workspace, ['invoice_id' => $invoice->public_id]);
        $this->assertTrue($before['data']['manual']['available']);
        $this->assertSame('none', $before['data']['manual']['pay_link']);
        $this->assertSame(['billing@reminders.example.test'], $before['data']['manual']['recipients']);
        $this->assertFalse($before['data']['cadence']['enabled']);
        $this->assertSame('cadence_off', $before['data']['cadence']['hold']['code']);
        $this->assertSame(['due_minus_3', 'due', 'due_plus_7', 'due_plus_14'], array_column($before['data']['cadence']['schedule'], 'step'));
        $this->assertSame([], $before['data']['reminders']);

        $body = ['expected_version' => $before['data']['invoice_version'], 'confirm' => true];
        $sent = $this->success($transport, 'invoices.send_reminder', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-remind');
        $this->assertSame('sent', $sent['data']['status']);
        $this->assertSame('manual', $sent['data']['origin']);
        $this->assertSame('agent', $sent['data']['triggered_by']);
        $this->assertSame($owner->name, $sent['data']['triggered_by_name']);
        $this->assertSame(12_345, $sent['data']['amount_due']);

        $again = $this->success($transport, 'invoices.send_reminder', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-remind');
        $this->assertSame($sent, $again, 'A replay returns the same reminder');
        Mail::assertSentCount(1);
        $this->assertSame(1, InvoiceReminderLog::query()->count());
        $this->assertSame(1, AgentMutationAudit::query()->where('operation', 'invoices.send_reminder')->where('outcome', 'replay')->count());

        $after = $this->success($transport, 'invoices.reminders.list', $workspace, ['invoice_id' => $invoice->public_id]);
        $this->assertSame([$sent['data']], $after['data']['reminders']);
        $this->assertSame($before['data']['invoice_version'], $after['data']['invoice_version'], 'Reminding changes nothing on the invoice');
    }

    #[DataProvider('transports')]
    public function test_the_cadence_is_read_and_switched_per_client(string $transport): void
    {
        [$workspace, $owner, $company] = $this->reminderFixture();
        $this->actingAsMcp($owner, self::SCOPES);

        $off = $this->success($transport, 'companies.payment_reminders.get', $workspace, ['client_id' => $company->public_id]);
        $this->assertFalse($off['data']['enabled']);
        $this->assertNull($off['data']['enabled_at']);
        $this->assertSame([-3, 0, 7, 14], array_column($off['data']['steps'], 'offset_days'));

        $on = $this->success($transport, 'companies.payment_reminders.set', $workspace, ['client_id' => $company->public_id], ['expected_version' => $off['data']['version'], 'enabled' => true], 'synthetic-cadence-on');
        $this->assertTrue($on['data']['enabled']);
        $this->assertSame('2030-03-15T14:00:00Z', $on['data']['enabled_at']);
        $this->assertNotNull($company->refresh()->payment_reminders_enabled_at);

        // The old version is refused, and nothing changes.
        $this->failure($transport, 'companies.payment_reminders.set', $workspace, ['client_id' => $company->public_id], ['expected_version' => $off['data']['version'], 'enabled' => false], 'synthetic-cadence-stale', 409);
        $this->assertNotNull($company->refresh()->payment_reminders_enabled_at);
    }

    public function test_refusals_are_reported_with_their_status_and_nothing_is_sent(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->reminderFixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $path = ['invoice_id' => $invoice->public_id];
        $version = AgentApiVersion::for($invoice);

        $this->rest('invoices.send_reminder', $workspace, $path, ['expected_version' => $version], 'synthetic-unconfirmed')->assertUnprocessable();
        $this->rest('invoices.send_reminder', $workspace, $path, ['expected_version' => $version, 'confirm' => 'yes'], 'synthetic-truthy')->assertUnprocessable();
        $this->rest('invoices.send_reminder', $workspace, $path, ['expected_version' => $version, 'confirm' => true, 'recipients' => ['x@example.test']], 'synthetic-unknown')->assertUnprocessable()->assertJsonValidationErrors('recipients');
        $this->rest('invoices.send_reminder', $workspace, $path, ['expected_version' => str_repeat('0', 64), 'confirm' => true], 'synthetic-stale')->assertConflict();
        $this->rest('invoices.send_reminder', $workspace, $path, ['expected_version' => $version, 'confirm' => true])->assertUnprocessable();

        // An invoice Square collects with no Square page is refused, not sent with SVC's own link.
        $billed = $this->squareBilledInvoice($company);
        $this->rest('invoices.send_reminder', $workspace, ['invoice_id' => $billed->public_id], ['expected_version' => AgentApiVersion::for($billed), 'confirm' => true], 'synthetic-square')
            ->assertUnprocessable()->assertJsonPath('message', PaymentReminderRefusal::SquareNotLinked->message());
        $list = $this->rest('invoices.reminders.list', $workspace, ['invoice_id' => $billed->public_id])->assertOk();
        $this->assertFalse($list->json('data.manual.available'));
        $this->assertSame('square_not_linked', $list->json('data.manual.refusal.code'));

        // A reminder in flight answers 409, retryable.
        InvoiceReminderLog::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'origin' => 'manual', 'channel' => 'email', 'pay_link' => 'none',
            'recipients' => ['billing@reminders.example.test'], 'subject' => 'Synthetic', 'currency' => 'USD', 'amount_due' => 1, 'status' => 'sending',
            'triggered_by' => 'website', 'queued_at' => now(), 'claimed_at' => now(),
        ]);
        $this->rest('invoices.send_reminder', $workspace, $path, ['expected_version' => $version, 'confirm' => true], 'synthetic-busy')->assertConflict();
        Mail::assertNothingSent();
    }

    public function test_only_managers_of_the_invoices_own_workspace_reach_reminders(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->reminderFixture('synthetic-reminders-a');
        [$other, $otherOwner] = $this->reminderFixture('synthetic-reminders-b');
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $body = ['expected_version' => AgentApiVersion::for($invoice), 'confirm' => true];

        $this->actingAsMcp($member, self::SCOPES);
        $this->rest('invoices.reminders.list', $workspace, ['invoice_id' => $invoice->public_id])->assertNotFound();
        $this->rest('companies.payment_reminders.get', $workspace, ['client_id' => $company->public_id])->assertNotFound();
        $this->rest('invoices.send_reminder', $workspace, ['invoice_id' => $invoice->public_id], $body, 'synthetic-member')->assertForbidden();
        $this->rest('companies.payment_reminders.set', $workspace, ['client_id' => $company->public_id], ['expected_version' => AgentApiVersion::for($company), 'enabled' => true], 'synthetic-member-cadence')->assertForbidden();

        // Another workspace's manager names this workspace's records through their own.
        $this->actingAsMcp($otherOwner, self::SCOPES);
        $this->rest('invoices.reminders.list', $other, ['invoice_id' => $invoice->public_id])->assertNotFound();
        $this->rest('invoices.send_reminder', $other, ['invoice_id' => $invoice->public_id], $body, 'synthetic-cross')->assertNotFound();
        $this->rest('companies.payment_reminders.set', $other, ['client_id' => $company->public_id], ['expected_version' => AgentApiVersion::for($company), 'enabled' => true], 'synthetic-cross-cadence')->assertNotFound();
        // And cannot reach this workspace at all.
        $this->rest('invoices.reminders.list', $workspace, ['invoice_id' => $invoice->public_id])->assertNotFound();

        $this->assertSame(0, InvoiceReminderLog::query()->count());
        $this->assertNull($company->refresh()->payment_reminders_enabled_at);
        Mail::assertNothingSent();
    }

    public function test_the_website_sends_through_the_same_operation_and_is_recorded_as_the_website(): void
    {
        config(['agent_api.writes_enabled' => false, 'agent_api.invoice_writes_enabled' => false]);
        [$workspace, $owner, , $invoice] = $this->reminderFixture();

        $response = $this->actingAs($owner)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40))
            ->withHeader('Idempotency-Key', 'synthetic-website')
            ->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/reminders", ['expected_version' => AgentApiVersion::for($invoice), 'confirm' => true])
            ->assertOk();

        $this->assertSame('website', $response->json('data.triggered_by'));
        $this->assertNull(InvoiceReminderLog::query()->sole()->oauth_client_id);
        Mail::assertSent(InvoicePaymentReminderMail::class);
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
     */
    private function failure(string $transport, string $operation, Workspace $workspace, array $ids, array $body, string $key, int $status): void
    {
        $response = $this->send($transport, $operation, $workspace, $ids, $body, $key);
        if ($response instanceof TestResponse) {
            $response->assertStatus($status);

            return;
        }
        $this->assertTrue($response['result']['isError'] ?? false, json_encode($response, JSON_THROW_ON_ERROR));
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
            'invoices.reminders.list' => ['GET', "invoices/{$ids['invoice_id']}/reminders"],
            'invoices.send_reminder' => ['POST', "invoices/{$ids['invoice_id']}/reminders"],
            'companies.payment_reminders.get' => ['GET', "clients/{$ids['client_id']}/payment-reminders"],
            'companies.payment_reminders.set' => ['PUT', "clients/{$ids['client_id']}/payment-reminders"],
            default => throw new \LogicException("Unknown synthetic operation {$operation}."),
        };

        return $this->json($method, "/api/v1/workspaces/{$workspace->public_id}/{$path}", $body, $key === null ? [] : ['Idempotency-Key' => $key]);
    }
}
