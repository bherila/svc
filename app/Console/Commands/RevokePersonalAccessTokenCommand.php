<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Services\Credentials\AmbiguousRevocation;
use App\Services\Credentials\CredentialRevoker;
use Illuminate\Console\Command;

/**
 * Revoke an exact person's named Sanctum personal access token (the finance
 * integration's) through {@see CredentialRevoker}, which audits it like every
 * other credential revocation.
 */
class RevokePersonalAccessTokenCommand extends Command
{
    use FindsUserByPublicId;

    protected $signature = 'svc:auth:revoke-token
        {user_public_id : Exact user public UUID}
        {name : Exact personal access token name}
        {--all : Revoke every token with this name when more than one exists}';

    protected $description = 'Revoke an exact user\'s named Sanctum personal access token';

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

        try {
            $revoked = $revoker->revokePersonalAccessTokens($user, $name, (bool) $this->option('all'), 'console');
        } catch (AmbiguousRevocation $ambiguous) {
            $this->error("{$ambiguous->matched} tokens share that name; rerun with --all to revoke all of them.");

            return self::FAILURE;
        }
        if ($revoked === 0) {
            $this->error('No token exists with that exact name.');

            return self::FAILURE;
        }

        $this->info("Revoked {$revoked} personal access token(s).");

        return self::SUCCESS;
    }
}
