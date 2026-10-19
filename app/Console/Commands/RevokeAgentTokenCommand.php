<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Services\Credentials\AgentCredentialSelection;
use App\Services\Credentials\AgentTokenKind;
use App\Services\Credentials\AmbiguousRevocation;
use App\Services\Credentials\CredentialRevoker;
use Illuminate\Console\Command;

/**
 * Revoke an exact person's named agent access tokens issued by
 * `svc:auth:issue-agent-token`, and only those, through {@see CredentialRevoker}.
 * Revocation is checked on every request, so the token stops working at once
 * rather than at its expiry.
 */
final class RevokeAgentTokenCommand extends Command
{
    use FindsUserByPublicId;

    protected $signature = 'svc:auth:revoke-agent-token
        {user_public_id : Exact user public UUID}
        {name : Exact agent token name}
        {--all : Revoke every live token with this name when more than one exists}';

    protected $description = 'Revoke an exact user\'s named agent (REST or MCP) access token';

    public function handle(CredentialRevoker $revoker): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('An exact user public UUID and non-empty token name are required.');

            return self::INVALID;
        }
        $user = $this->userByPublicId((string) $this->argument('user_public_id'));
        if (is_int($user)) {
            return $user;
        }

        $kind = AgentTokenKind::Operator;
        try {
            $revocation = $revoker->revoke(AgentCredentialSelection::named($user, $kind, $name, (bool) $this->option('all')), $kind->actor());
        } catch (AmbiguousRevocation $ambiguous) {
            $this->error("{$ambiguous->matched} live agent tokens share that name; rerun with --all to revoke all of them.");

            return self::FAILURE;
        }

        if ($revocation->accessTokens() === 0) {
            $this->error('No live agent token exists with that exact name.');

            return self::FAILURE;
        }

        $this->line((string) json_encode(['revoked' => $revocation->accessTokens()], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
