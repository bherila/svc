<?php

namespace App\Console\Commands\Mcp;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use RuntimeException;

/**
 * Issue and revoke the short-lived credentials the post-deploy MCP smoke uses.
 *
 * ## Why this exists
 *
 * The deploy verifies `/up` and the shape of an OAuth redirect. Neither says
 * anything about whether the authenticated MCP surface works on the host it was
 * just installed on. The pre-merge smoke does exercise it, but against
 * `artisan serve` on a runner, with the runner's configuration - so it cannot
 * see a production-only difference, which is the class of failure this epic
 * keeps producing (#50).
 *
 * ## Why the principal owns nothing
 *
 * The issue asked for a synthetic *tenant*. This is deliberately less than that.
 * Every read-only assertion the smoke makes - discovery, initialization, tool
 * listing, one authorized read, one refusal for a missing scope, and session
 * isolation - is satisfied by `context.get`, which is the one tool declared
 * `requiresWorkspace: false`. So the smoke principal is a user with **no
 * workspace, company or project membership at all**.
 *
 * That is a stronger privacy guarantee than any masking rule: a credential that
 * cannot reach a business record cannot leak one, whatever the smoke prints or
 * a future edit stops masking. It also means no synthetic company, project or
 * agreement has to exist in the production database to support a CI job, and no
 * audit has to learn to exclude one.
 *
 * The guarantee is enforced rather than assumed. If the reserved principal has
 * acquired any membership, this refuses to issue anything - because at that
 * point the smoke would be reading someone's data, and a credential minted on
 * every deploy is exactly the thing that should fail closed.
 *
 * ## Lifetime
 *
 * Two controls, because they fail differently. `revoked` is checked on every
 * request and is what `--revoke` sets, so cleanup is immediate; the deploy runs
 * it with `always()` so a failed assertion still revokes. The JWT's own `exp` is
 * the backstop for the case that step never runs at all, which is why the tokens
 * are minted with a short expiry rather than Passport's one-year default.
 */
final class DeploySmokeCredentialsCommand extends Command
{
    protected $signature = 'svc:mcp:deploy-smoke-credentials
        {--revoke : Revoke every token held by the smoke principal and issue nothing}
        {--ttl=15 : Minutes the issued tokens remain valid, as a backstop to revocation}';

    protected $description = 'Issue or revoke the short-lived credentials used by the post-deploy MCP smoke';

    /**
     * Reserved identity, never a real person.
     *
     * `.invalid` is reserved by RFC 2606 and can never be registered, so this
     * address cannot collide with a real user or accidentally receive mail.
     */
    private const EMAIL = 'deploy-smoke@svc.invalid';

    private const PRINCIPAL_NAME = 'Deployment MCP smoke';

    private const CLIENT_NAME = 'SVC deployment MCP smoke';

    public function handle(): int
    {
        $principal = $this->principal();

        if ($this->option('revoke')) {
            $this->line((string) json_encode(['revoked' => $this->revokeTokens($principal)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->refuseIfThePrincipalCanReachAnything($principal);

        // Any token from a previous run is dead to us the moment we mint a new
        // pair, and leaving it live would mean a failed cleanup silently
        // accumulating valid credentials one deploy at a time.
        $this->revokeTokens($principal);

        $ttl = max(1, (int) $this->option('ttl'));
        Passport::personalAccessTokensExpireIn(now()->addMinutes($ttl));

        $client = $this->personalAccessClient();

        $authorized = $this->issue($principal, $client, 'authorized', [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IDENTITY_READ,
        ]);

        // A connection carrying a *different* operation scope, not none. It has
        // to be able to initialize before it can be refused a tool call, and a
        // connection authorized for nothing cannot: the server advertises an
        // empty capability set, which PHP serializes as a JSON array where the
        // protocol requires an object, and a conformant client rejects the
        // handshake. Found by this smoke before it shipped; tracked separately.
        //
        // Holding `projects:read` and not `identity:read` also makes the
        // assertion sharper than a scopeless token would: it shows the scope is
        // enforced per operation rather than "any scope will do".
        $wrongScope = $this->issue($principal, $client, 'wrong-scope', [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::PROJECTS_READ,
        ]);

        $this->line((string) json_encode([
            'authorized_token' => $authorized,
            'wrong_scope_token' => $wrongScope,
            'expires_at' => now()->addMinutes($ttl)->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Created through {@see User}, read back as {@see AgentPrincipal}.
     *
     * They are the same row. `AgentPrincipal` is the OAuth-only view and is
     * deliberately not mass-assignable, so the row is written through the model
     * that owns the columns and then re-resolved through the one that owns the
     * tokens - the same split the pre-merge credentials helper uses.
     */
    private function principal(): AgentPrincipal
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => self::PRINCIPAL_NAME,
                // The column is NOT NULL and this identity must never be able to
                // sign in, so it gets a hash of a random string nobody holds -
                // an unusable password rather than a weak or shared one.
                'password' => Hash::make(Str::random(64)),
            ],
        );

        return AgentPrincipal::query()->whereKey($user->getKey())->firstOrFail();
    }

    /**
     * The whole safety property, checked rather than trusted.
     *
     * Read directly off the pivot tables rather than through the relations, so
     * this keeps working if a relation is later scoped, renamed or filtered -
     * a guard that silently stops looking is the failure mode this repository
     * has found most often.
     */
    private function refuseIfThePrincipalCanReachAnything(AgentPrincipal $principal): void
    {
        $memberships = [
            'workspace_memberships' => DB::table('workspace_memberships')->where('user_id', $principal->id)->count(),
            'client_company_memberships' => DB::table('client_company_memberships')->where('user_id', $principal->id)->count(),
            'client_project_memberships' => DB::table('client_project_memberships')->where('user_id', $principal->id)->count(),
        ];

        $held = array_filter($memberships);

        if ($held !== []) {
            throw new RuntimeException(sprintf(
                'The deployment smoke principal holds %s. It is meant to reach nothing, so a credential for it '
                .'would now read real records. Remove the membership rather than widening this check.',
                implode(', ', array_map(
                    static fn (int $count, string $table): string => "{$count} in {$table}",
                    $held,
                    array_keys($held),
                )),
            ));
        }
    }

    private function personalAccessClient(): string
    {
        $existing = DB::table('oauth_clients')
            ->where('name', self::CLIENT_NAME)
            ->where('revoked', false)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        return (string) app(ClientRepository::class)
            ->createPersonalAccessGrantClient(self::CLIENT_NAME, 'agent-principals')
            ->getKey();
    }

    /** @param  list<string>  $scopes */
    private function issue(AgentPrincipal $principal, string $clientId, string $label, array $scopes): string
    {
        $issued = $principal->createToken(self::PRINCIPAL_NAME.' '.$label, $scopes);

        // Without the resource indicator the token is not addressed to this API
        // and the MCP endpoint refuses it - the same binding the pre-merge smoke
        // credentials set, for the same reason.
        Passport::token()->newQuery()
            ->whereKey($issued->accessTokenId)
            ->where('client_id', $clientId)
            ->update(['resource_uri' => OAuthResourceIndicator::resource()]);

        return $issued->accessToken;
    }

    private function revokeTokens(AgentPrincipal $principal): int
    {
        return Passport::token()->newQuery()
            ->where('user_id', $principal->id)
            ->where('revoked', false)
            ->update(['revoked' => true, 'updated_at' => Carbon::now()]);
    }
}
