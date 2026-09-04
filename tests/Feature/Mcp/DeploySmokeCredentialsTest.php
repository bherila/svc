<?php

namespace Tests\Feature\Mcp;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\TestCase;

/**
 * The credentials the post-deploy smoke runs on.
 *
 * The behaviour worth pinning is not that a token comes back - it is the two
 * properties that make minting one against production acceptable at all: the
 * principal can reach nothing, and every token dies at the end of the run.
 */
class DeploySmokeCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'deploy-smoke@svc.invalid';

    /** @return array<string, mixed> */
    private function issue(): array
    {
        Artisan::call('svc:mcp:deploy-smoke-credentials');

        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function principal(): AgentPrincipal
    {
        return AgentPrincipal::query()->where('email', self::EMAIL)->firstOrFail();
    }

    public function test_it_issues_two_credentials_for_a_principal_that_can_reach_nothing(): void
    {
        $issued = $this->issue();

        $this->assertNotSame('', $issued['authorized_token']);
        $this->assertNotSame('', $issued['wrong_scope_token']);
        $this->assertNotSame($issued['authorized_token'], $issued['wrong_scope_token']);

        $principal = $this->principal();

        $this->assertSame(0, DB::table('workspace_memberships')->where('user_id', $principal->id)->count());
        $this->assertSame(0, DB::table('client_company_memberships')->where('user_id', $principal->id)->count());
        $this->assertSame(0, DB::table('client_project_memberships')->where('user_id', $principal->id)->count());
        $tokens = Passport::token()->newQuery()
            ->where('user_id', $principal->id)
            ->where('revoked', false)
            ->get();
        $this->assertCount(2, $tokens);
        foreach ($tokens as $token) {
            $this->assertSame(OAuthResourceIndicator::resource(), $token->resource_uri);
        }
        foreach (['authorized_token', 'wrong_scope_token'] as $key) {
            $claims = OAuthResourceIndicator::tokenClaims($issued[$key]);
            $this->assertSame(config('bherila-auth.oauth_server.issuer'), $claims['iss'] ?? null);
            $this->assertSame(OAuthResourceIndicator::resource(), $claims['resource'] ?? null);
            $this->assertContains(OAuthResourceIndicator::resource(), $claims['aud'] ?? []);
        }
    }

    /**
     * The two credentials differ by exactly the scope under test.
     *
     * If both carried `identity:read` the refusal assertion in the smoke would
     * pass by never being exercised, which is the shape of failure this
     * repository keeps finding: a check that is green because it did not run.
     */
    public function test_the_second_credential_holds_a_different_operation_scope(): void
    {
        $this->issue();

        $scopes = Passport::token()->newQuery()
            ->where('user_id', $this->principal()->id)
            ->where('revoked', false)
            ->pluck('scopes', 'name')
            ->map(static fn ($scopes): array => is_array($scopes) ? $scopes : (array) json_decode((string) $scopes, true))
            ->all();

        $authorized = $scopes['Deployment MCP smoke authorized'] ?? [];
        $wrongScope = $scopes['Deployment MCP smoke wrong-scope'] ?? [];

        $this->assertContains(AgentApiScopes::IDENTITY_READ, $authorized);
        $this->assertNotContains(AgentApiScopes::IDENTITY_READ, $wrongScope);

        // Not scopeless: a connection authorized for nothing cannot complete the
        // MCP handshake today (#197), so the refusal has to be proved by a
        // credential that can connect and still be told no.
        $this->assertContains(AgentApiScopes::PROJECTS_READ, $wrongScope);
        $this->assertContains(AgentApiScopes::MCP_USE, $wrongScope);
    }

    public function test_revoking_ends_every_token_the_principal_holds(): void
    {
        $this->issue();
        $principal = $this->principal();

        $this->assertSame(2, Passport::token()->newQuery()->where('user_id', $principal->id)->where('revoked', false)->count());

        Artisan::call('svc:mcp:deploy-smoke-credentials', ['--revoke' => true]);

        $this->assertSame(['revoked' => 2], json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(0, Passport::token()->newQuery()->where('user_id', $principal->id)->where('revoked', false)->count());
    }

    /**
     * A failed revocation must not accumulate live credentials one deploy at a
     * time, so issuing ends whatever the last run left behind.
     */
    public function test_issuing_again_ends_the_previous_run_s_credentials(): void
    {
        $first = $this->issue();
        $this->issue();

        $live = Passport::token()->newQuery()
            ->where('user_id', $this->principal()->id)
            ->where('revoked', false)
            ->count();

        $this->assertSame(2, $live, 'Only the newest pair may remain live.');
        $this->assertNotSame('', $first['authorized_token']);
    }

    /**
     * The safety property, proved by breaking it.
     *
     * Without this the guard would be a comment. A principal that has somehow
     * been given access must stop the smoke rather than quietly hand it a
     * credential that can read someone's records.
     */
    public function test_it_refuses_to_issue_once_the_principal_can_reach_a_workspace(): void
    {
        $this->issue();
        $principal = $this->principal();

        $workspace = Workspace::create(['name' => 'Real work', 'slug' => 'real-work']);
        DB::table('workspace_memberships')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $principal->id,
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/holds 1 in workspace_memberships/');

        Artisan::call('svc:mcp:deploy-smoke-credentials');
    }

    /**
     * The company branch, which is the one a portal user arrives through.
     *
     * A client-portal member belongs to a company without belonging to the
     * workspace, and the schema allows exactly that - so this refusal has to
     * hold on its own rather than being reached through the workspace check.
     *
     * **The project branch is deliberately not tested here, and cannot be.**
     * `client_project_memberships` carries a composite foreign key on
     * `(workspace_id, user_id)` into `workspace_memberships`, so a project
     * membership cannot exist without a workspace membership - the workspace
     * check above would always fire first. The guard still counts project
     * memberships, because a guard that depends on a foreign key elsewhere
     * staying exactly as it is today is the kind that stops looking without
     * anyone noticing. Asserting it here would mean asserting the schema, not
     * the guard.
     */
    public function test_it_refuses_once_the_principal_can_reach_a_client_company(): void
    {
        $this->issue();
        $principal = $this->principal();

        $workspace = Workspace::create(['name' => 'Real work', 'slug' => 'real-work-2']);
        $company = ClientCompany::create([
            'workspace_id' => $workspace->id,
            'name' => 'Real client',
            'slug' => 'real-client',
        ]);

        DB::table('client_company_memberships')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'user_id' => $principal->id,
            'role' => 'client',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/client_company_memberships/');

        Artisan::call('svc:mcp:deploy-smoke-credentials');
    }
}
