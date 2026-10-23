<?php

namespace App\Console\Commands\Mcp;

use App\Console\Commands\MutationCommand;
use App\Models\User;
use App\Services\Credentials\AgentCredentialSelection;
use App\Services\Credentials\AgentTokenIssuer;
use App\Services\Credentials\AgentTokenKind;
use App\Services\Credentials\AgentTokenRefused;
use App\Services\Credentials\AgentTokenRequest;
use App\Services\Credentials\CredentialRevoker;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
 * isolation - is satisfied by `context.get`, which needs no workspace: it
 * lists the caller's own. So the smoke principal is a user with **no
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
final class DeploySmokeCredentialsCommand extends MutationCommand
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

    public function handle(AgentTokenIssuer $issuer, CredentialRevoker $revoker): int
    {
        if (! $this->applying()) {
            return $this->planned();
        }

        $user = $this->principal();
        $kind = AgentTokenKind::DeploymentSmoke;

        if ($this->option('revoke')) {
            $revoked = $revoker->revoke(AgentCredentialSelection::connections($user), $kind->actor());
            $this->line((string) json_encode(['revoked' => $revoked->accessTokens()], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $ttl = AgentTokenRequest::ttlFromInput((string) $this->option('ttl'));
        if ($ttl === null) {
            $this->error('--ttl must be a whole number of minutes from 1 to '.AgentTokenRequest::MAX_TTL_MINUTES.'.');

            return self::INVALID;
        }

        $this->refuseIfThePrincipalCanReachAnything($user);

        // Any token from a previous run is dead to us the moment we mint a new
        // pair, and leaving it live would mean a failed cleanup silently
        // accumulating valid credentials one deploy at a time. The durable
        // revocation ends its previous lineages; the fresh pair captures the
        // new sequence and can be issued immediately.
        $revoker->revoke(AgentCredentialSelection::connections($user), $kind->actor());

        try {
            $authorized = $issuer->issue($user, AgentTokenRequest::of($kind, 'authorized', [
                AgentApiScopes::MCP_USE,
                AgentApiScopes::IDENTITY_READ,
            ], $ttl));

            // A connection carrying a *different* operation scope, not none. It
            // has to be able to initialize before it can be refused a tool call,
            // and a connection authorized for nothing cannot: the server
            // advertises an empty capability set, which PHP serializes as a JSON
            // array where the protocol requires an object, and a conformant
            // client rejects the handshake. Found by this smoke before it
            // shipped; tracked separately.
            //
            // Holding `projects:read` and not `identity:read` also makes the
            // assertion sharper than a scopeless token would: it shows the scope
            // is enforced per operation rather than "any scope will do".
            $wrongScope = $issuer->issue($user, AgentTokenRequest::of($kind, 'wrong-scope', [
                AgentApiScopes::MCP_USE,
                AgentApiScopes::PROJECTS_READ,
            ], $ttl));
        } catch (AgentTokenRefused $refused) {
            // Nothing half-issued outlives the refusal.
            $revoker->revoke(AgentCredentialSelection::connections($user), $kind->actor());
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode([
            'authorized_token' => $authorized->token(),
            'wrong_scope_token' => $wrongScope->token(),
            'expires_at' => $authorized->expiresAt->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Created through {@see User}, the model that owns the columns:
     * `AgentPrincipal`, the OAuth-only view of the same row, is deliberately
     * not mass-assignable. {@see AgentTokenIssuer} resolves the principal.
     */
    private function principal(): User
    {
        return User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => self::PRINCIPAL_NAME,
                // The column is NOT NULL and this identity must never be able to
                // sign in, so it gets a hash of a random string nobody holds -
                // an unusable password rather than a weak or shared one.
                'password' => Hash::make(Str::random(64)),
            ],
        );
    }

    /**
     * The whole safety property, checked rather than trusted.
     *
     * Read directly off the pivot tables rather than through the relations, so
     * this keeps working if a relation is later scoped, renamed or filtered -
     * a guard that silently stops looking is the failure mode this repository
     * has found most often.
     */
    private function refuseIfThePrincipalCanReachAnything(User $principal): void
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
}
