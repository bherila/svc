<?php

namespace Tests\Feature\Billing\Autopay;

use App\Mail\AutopayEnabledNoticeMail;
use App\Models\ClientAutopayConsent;
use App\Models\InvoiceAutopayAttempt;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsAutopay;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/** Autopay over REST, MCP and the website's session (#636): one operation each way. */
final class AutopayApiTest extends TestCase
{
    use BuildsAutopay;
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'clients:read', 'billing:read', 'billing:write'];

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2030-10-03 14:00:00');
        config(['app.url' => 'https://svc.example.test', 'agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->restoreAutopayStripe();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_a_manager_turns_autopay_on_changes_the_limit_and_turns_it_off(string $transport): void
    {
        [$workspace, $owner, $company] = $this->autopayFixture();
        $this->actingAsMcp($owner, self::SCOPES);

        $read = $this->success($transport, 'companies.autopay.get', $workspace, ['client_id' => $company->public_id]);
        $this->assertFalse($read['data']['enabled']);
        $this->assertSame('Visa ending 4242', $read['data']['method']['label']);
        $this->assertSame([], $read['data']['consents']);

        $on = $this->success($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], [
            'expected_version' => $read['data']['version'], 'enabled' => true, 'limit_amount' => 400_000,
            'attestation' => 'Signed services agreement of Aug 28, clause 7.', 'confirm' => true,
        ], 'synthetic-autopay-on');
        $this->assertTrue($on['data']['enabled']);
        $this->assertSame(400_000, $on['data']['limit_amount']);
        $this->assertSame(['enabled'], array_column($on['data']['consents'], 'action'));
        $this->assertSame(['provider', 'provider_attestation', 'Sam Example'], [$on['data']['consents'][0]['actor_role'], $on['data']['consents'][0]['method'], $on['data']['consents'][0]['actor_name']]);
        Mail::assertSent(AutopayEnabledNoticeMail::class, 1);

        // The same key replays: one record, one email.
        $this->success($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], [
            'expected_version' => $read['data']['version'], 'enabled' => true, 'limit_amount' => 400_000,
            'attestation' => 'Signed services agreement of Aug 28, clause 7.', 'confirm' => true,
        ], 'synthetic-autopay-on');
        $this->assertSame(1, ClientAutopayConsent::query()->count());
        Mail::assertSent(AutopayEnabledNoticeMail::class, 1);

        $changed = $this->success($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], [
            'expected_version' => $on['data']['version'], 'enabled' => true, 'limit_amount' => 600_000,
            'attestation' => 'Raised by email on Oct 3.', 'confirm' => true,
        ], 'synthetic-autopay-limit');
        $this->assertSame(['limit_changed', 'enabled'], array_column($changed['data']['consents'], 'action'));

        $off = $this->success($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], [
            'expected_version' => $changed['data']['version'], 'enabled' => false, 'confirm' => true,
        ], 'synthetic-autopay-off');
        $this->assertFalse($off['data']['enabled']);
        $this->assertNull($off['data']['limit_amount']);
        $this->assertSame(['disabled', 'limit_changed', 'enabled'], array_column($off['data']['consents'], 'action'));
    }

    #[DataProvider('transports')]
    public function test_turning_it_on_needs_the_limit_the_attestation_and_an_explicit_confirmation(string $transport): void
    {
        [$workspace, $owner, $company] = $this->autopayFixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $version = AgentApiVersion::for($company);
        $full = ['expected_version' => $version, 'enabled' => true, 'limit_amount' => 400_000, 'attestation' => 'Signed on Aug 28.', 'confirm' => true];

        foreach (['limit_amount', 'attestation'] as $missing) {
            $body = $full;
            unset($body[$missing]);
            $this->failure($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], $body, 'synthetic-missing-'.$missing, 422);
        }
        $this->failure($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], [...$full, 'confirm' => false], 'synthetic-unconfirmed', 422);
        $this->failure($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], ['expected_version' => $version, 'enabled' => false], 'synthetic-off-unconfirmed', 422);
        $this->failure($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], [...$full, 'limit_amount' => 0], 'synthetic-zero', 422);
        $this->failure($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], [...$full, 'attestation' => 'short'], 'synthetic-short', 422);
        $this->failure($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], ['expected_version' => $version, 'enabled' => false, 'confirm' => true, 'limit_amount' => 1], 'synthetic-off-limit', 422);
        $this->failure($transport, 'companies.autopay.set', $workspace, ['client_id' => $company->public_id], [...$full, 'expected_version' => str_repeat('0', 64)], 'synthetic-stale', 409);

        $this->assertNull($company->refresh()->autopay_enabled_at);
        $this->assertSame(0, ClientAutopayConsent::query()->count());
        Mail::assertNothingSent();
    }

    #[DataProvider('transports')]
    public function test_a_manager_reads_and_skips_an_invoice(string $transport): void
    {
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->autopayInvoice($company);
        $this->actingAsMcp($owner, self::SCOPES);

        $read = $this->success($transport, 'invoices.autopay.get', $workspace, ['invoice_id' => $invoice->public_id]);
        $this->assertSame(['will_charge', '2030-12-01', 375_000, true, null], [$read['data']['state'], $read['data']['will_charge_on'], $read['data']['amount'], $read['data']['can_skip'], $read['data']['attempt']]);

        $skipped = $this->success($transport, 'invoices.autopay.skip', $workspace, ['invoice_id' => $invoice->public_id], ['expected_version' => $read['data']['invoice_version'], 'confirm' => true], 'synthetic-skip');
        $this->assertSame(['skipped', false, 'Sam Example'], [$skipped['data']['state'], $skipped['data']['can_skip'], $skipped['data']['attempt']['skipped_by_name']]);
        $attempt = InvoiceAutopayAttempt::query()->sole();
        $this->assertSame([$owner->id, 'skipped'], [$attempt->skipped_by_user_id, $attempt->state->value]);

        // Skipping again is the same answer; an unconfirmed or stale request is refused.
        $this->success($transport, 'invoices.autopay.skip', $workspace, ['invoice_id' => $invoice->public_id], ['expected_version' => $read['data']['invoice_version'], 'confirm' => true], 'synthetic-skip');
        $this->failure($transport, 'invoices.autopay.skip', $workspace, ['invoice_id' => $invoice->public_id], ['expected_version' => $read['data']['invoice_version'], 'confirm' => false], 'synthetic-skip-unconfirmed', 422);
    }

    public function test_it_is_withheld_from_everyone_who_does_not_manage_the_workspace_and_from_other_workspaces(): void
    {
        [$workspace, $owner, $company, $contact] = $this->autopayFixture('one');
        [$other, $otherOwner, $otherCompany, $otherContact] = $this->autopayFixture('two');
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->autopayInvoice($company);
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $body = ['expected_version' => AgentApiVersion::for($company), 'enabled' => false, 'confirm' => true];

        $this->actingAsMcp($member, self::SCOPES);
        $this->rest('companies.autopay.get', $workspace, ['client_id' => $company->public_id])->assertNotFound();
        $this->rest('invoices.autopay.get', $workspace, ['invoice_id' => $invoice->public_id])->assertNotFound();
        $this->rest('companies.autopay.set', $workspace, ['client_id' => $company->public_id], $body, 'synthetic-member')->assertForbidden();
        $this->rest('invoices.autopay.skip', $workspace, ['invoice_id' => $invoice->public_id], ['expected_version' => AgentApiVersion::for($invoice), 'confirm' => true], 'synthetic-member-skip')->assertForbidden();

        // The other workspace's owner names this workspace's records through their own workspace.
        $this->actingAsMcp($otherOwner, self::SCOPES);
        $this->rest('companies.autopay.get', $other, ['client_id' => $company->public_id])->assertNotFound();
        $this->rest('invoices.autopay.get', $other, ['invoice_id' => $invoice->public_id])->assertNotFound();
        $this->rest('companies.autopay.set', $other, ['client_id' => $company->public_id], $body, 'synthetic-cross')->assertNotFound();
        $this->rest('invoices.autopay.skip', $other, ['invoice_id' => $invoice->public_id], ['expected_version' => AgentApiVersion::for($invoice), 'confirm' => true], 'synthetic-cross-skip')->assertNotFound();
        $this->rest('companies.autopay.get', $workspace, ['client_id' => $company->public_id])->assertNotFound();

        $this->assertNotNull($company->refresh()->autopay_enabled_at);
        $this->assertSame(0, InvoiceAutopayAttempt::query()->count());
        $this->assertSame($otherCompany->workspace_id, $other->id);
        $this->assertSame($otherContact->name, 'Dana Example');
        $this->assertSame($owner->name, 'Sam Example');
    }

    public function test_the_website_turns_it_off_through_the_same_operation(): void
    {
        config(['agent_api.writes_enabled' => false, 'agent_api.client_writes_enabled' => false]);
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);

        $this->actingAs($owner)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str_repeat('a', 40))
            ->withHeader('Idempotency-Key', 'synthetic-website-off')
            ->putJson("/api/v1/workspaces/{$workspace->public_id}/clients/{$company->public_id}/autopay", ['expected_version' => AgentApiVersion::for($company->refresh()), 'enabled' => false, 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertNull($company->refresh()->autopay_enabled_at);
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
        // A schema violation is a protocol error; a refusal by the operation is a tool error.
        $this->assertTrue(($response['result']['isError'] ?? false) || isset($response['error']), json_encode($response, JSON_THROW_ON_ERROR));
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
            'companies.autopay.get' => ['GET', "clients/{$ids['client_id']}/autopay"],
            'companies.autopay.set' => ['PUT', "clients/{$ids['client_id']}/autopay"],
            'invoices.autopay.get' => ['GET', "invoices/{$ids['invoice_id']}/autopay"],
            'invoices.autopay.skip' => ['POST', "invoices/{$ids['invoice_id']}/autopay/skip"],
            default => throw new \LogicException("Unknown synthetic operation {$operation}."),
        };

        return $this->json($method, "/api/v1/workspaces/{$workspace->public_id}/{$path}", $body, $key === null ? [] : ['Idempotency-Key' => $key]);
    }
}
