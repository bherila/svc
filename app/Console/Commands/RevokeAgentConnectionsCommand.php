<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Services\Credentials\AgentCredentialSelection;
use App\Services\Credentials\CredentialRevoker;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/**
 * Emergency containment: cut off every agent connection one exact person
 * holds - or only those of one OAuth client - without SQL and without the
 * person's own connection to do it with.
 *
 * Through {@see CredentialRevoker}, the same path a connection disconnecting
 * itself takes: the person's refresh tokens (including any whose access token
 * Passport already purged) and pending authorization codes are revoked before
 * their access tokens, in one transaction, so no connection can mint a
 * replacement, and a fence revokes what a refresh already in flight persists
 * just after. It revokes rather than deletes, so the rows stay for the
 * investigation. It is idempotent: a second run reports what was still live.
 *
 * A refresh token issued before refresh tokens recorded their owner, whose
 * access token Passport has since purged, cannot be tied to anyone. Those are
 * counted on every run, and `--unattributed` revokes them for everyone.
 */
final class RevokeAgentConnectionsCommand extends Command
{
    use FindsUserByPublicId;

    protected $signature = 'svc:auth:revoke-connections
        {user_public_id : Exact user public UUID}
        {--client= : Revoke only the credentials issued to this OAuth client id}
        {--unattributed : Also revoke every live refresh token, anyone\'s, that records no owner and has no access token left}';

    protected $description = 'Revoke every agent (OAuth, MCP and personal) credential an exact user holds, optionally for one OAuth client';

    public function handle(CredentialRevoker $revoker): int
    {
        $clientId = $this->option('client');
        $clientId = is_string($clientId) ? trim($clientId) : null;
        if ($clientId !== null && ! Str::isUuid($clientId)) {
            $this->error('--client must be an exact OAuth client id.');

            return self::INVALID;
        }
        $user = $this->userByPublicId((string) $this->argument('user_public_id'));
        if (is_int($user)) {
            return $user;
        }
        if ($clientId !== null && ! Passport::client()->newQuery()->whereKey($clientId)->exists()) {
            $this->error('No OAuth client exists with that id.');

            return self::FAILURE;
        }

        $revocation = $revoker->revoke(AgentCredentialSelection::connections($user, $clientId), 'console');
        $counts = $revocation->counts();
        if ((bool) $this->option('unattributed')) {
            $counts['unattributed_refresh_tokens_revoked'] = $revoker->revokeUnattributedRefreshTokens('console');
        }
        // A refresh token that names no owner and has lost its access token
        // may be this person's; say so rather than report a clean sweep.
        $counts['unattributed_refresh_tokens'] = $revoker->unattributedRefreshTokens();

        $this->line((string) json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
