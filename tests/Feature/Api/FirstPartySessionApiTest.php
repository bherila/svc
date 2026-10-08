<?php

namespace Tests\Feature\Api;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\FirstPartySession;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The signed-in website calls `/api/v1` on its own session (#385).
 *
 * The web UI moves onto the API the OAuth clients and MCP already use, so the
 * same routes must admit a browser session - with the website's own
 * request-forgery protection, the user's real role, and none of the
 * agent-only cutovers - without letting a session and a bearer token mix.
 */
final class FirstPartySessionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        // Agent writes switched off, as an operator would in an emergency: the
        // website must keep working regardless.
        config(['agent_api.writes_enabled' => false, 'agent_api.invoice_writes_enabled' => false]);
        $this->owner = User::factory()->create(['email' => 'operator-'.Str::random(8).'@synthetic.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Session', 'slug' => 'synthetic-session-'.Str::random(8)]);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Synthetic Session Client',
            'slug' => 'synthetic-session-client-'.Str::random(8),
        ]);
    }

    public function test_a_signed_in_session_reads_and_writes_through_the_api_while_agent_writes_are_off(): void
    {
        $draft = $this->draft();

        $this->asSignedIn($this->owner)->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('data.workspaces.0.id', $this->workspace->public_id);

        $this->asSignedIn($this->owner)
            ->withHeader('Idempotency-Key', 'session-details')
            ->patchJson($this->detailsUrl($draft), [
                'expected_version' => AgentApiVersion::for($draft->fresh()),
                'due_date' => '2026-10-30',
            ])
            ->assertOk();

        $this->assertSame('2026-10-30', $draft->fresh()->due_date?->toDateString());
        $this->assertDatabaseHas('agent_mutation_audits', [
            'operation' => 'invoices.update_details',
            'outcome' => 'success',
            'oauth_client_id' => FirstPartySession::CLIENT_ID,
        ]);
    }

    public function test_the_session_holds_every_api_capability_but_the_mcp_transport(): void
    {
        $capabilities = $this->asSignedIn($this->owner)->getJson('/api/v1/context')
            ->assertOk()
            ->json('data.workspaces.0.capabilities');

        $this->assertContains('billing:write', $capabilities);
        $this->assertContains('billing:deliver', $capabilities);
        $this->assertNotContains('mcp:use', FirstPartySession::scopes());
        $this->assertNotContains('mcp:use', $capabilities);
    }

    public function test_the_mcp_endpoint_does_not_accept_a_session(): void
    {
        $this->asSignedIn($this->owner)->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'SVC test', 'version' => '1']],
        ], ['Mcp-Protocol-Version' => '2025-06-18'])->assertUnauthorized();
    }

    public function test_without_the_session_cookie_the_api_is_unauthenticated(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/context')->assertUnauthorized();
    }

    /** A bearer token is an OAuth request; a signed-in browser alongside it lends it nothing. */
    public function test_a_bearer_token_is_never_combined_with_the_session(): void
    {
        $this->asSignedIn($this->owner)
            ->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/context')
            ->assertUnauthorized();
    }

    public function test_the_users_real_role_still_applies(): void
    {
        $draft = $this->draft();
        $member = User::factory()->create(['email' => 'member-'.Str::random(8).'@synthetic.test']);
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->asSignedIn($member)
            ->withHeader('Idempotency-Key', 'member-details')
            ->patchJson($this->detailsUrl($draft), [
                'expected_version' => AgentApiVersion::for($draft->fresh()),
                'due_date' => '2026-10-30',
            ])
            ->assertForbidden();
        $this->assertSame('2026-10-15', $draft->fresh()->due_date?->toDateString());
    }

    /**
     * The framework skips request-forgery checks under PHPUnit, so this
     * enables them to show the session path really runs them: a cross-site
     * write without a token is refused, a same-origin one is admitted.
     */
    public function test_a_cross_site_write_is_refused(): void
    {
        $this->app->bind(PreventRequestForgery::class, static fn (Application $app): PreventRequestForgery => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $draft = $this->draft();
        $body = ['expected_version' => AgentApiVersion::for($draft->fresh()), 'due_date' => '2026-10-30'];

        $this->asSignedIn($this->owner)
            ->withHeaders(['Idempotency-Key' => 'forged', 'Sec-Fetch-Site' => 'cross-site'])
            ->patchJson($this->detailsUrl($draft), $body)
            ->assertStatus(419);
        $this->assertSame('2026-10-15', $draft->fresh()->due_date?->toDateString());

        $this->asSignedIn($this->owner)
            ->withHeaders(['Idempotency-Key' => 'same-origin', 'Sec-Fetch-Site' => 'same-origin'])
            ->patchJson($this->detailsUrl($draft), $body)
            ->assertOk();
    }

    /** @return $this */
    private function asSignedIn(User $user): static
    {
        // The cookie's value is irrelevant here: the test guard already holds
        // the user. What matters is that the request looks like a browser one.
        return $this->actingAs($user)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
    }

    private function draft(): ClientInvoice
    {
        return app(InvoiceLifecycleService::class)->createDraft($this->workspace, $this->company, [
            'invoice_number' => 'INV-SES-'.Str::upper(Str::random(6)),
            'currency' => 'USD',
            'issue_date' => '2026-09-15',
            'due_date' => '2026-10-15',
        ], [[
            'type' => 'fee',
            'description' => 'Synthetic service',
            'quantity' => '1',
            'unit_amount' => 12500,
            'tax_amount' => 0,
        ]]);
    }

    private function detailsUrl(ClientInvoice $invoice): string
    {
        return "/api/v1/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}/details";
    }
}
