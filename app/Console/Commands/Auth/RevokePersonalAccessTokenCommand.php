<?php

namespace App\Console\Commands\Auth;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\MutationCommand;
use App\Services\Credentials\AmbiguousRevocation;
use App\Services\Credentials\CredentialRevoker;

/**
 * Revoke an exact person's named Sanctum personal access token (the finance
 * integration's) through {@see CredentialRevoker}, which audits it like every
 * other credential revocation.
 */
class RevokePersonalAccessTokenCommand extends MutationCommand
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

        if (! $this->applying()) {
            return $this->planned();
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

        if ($this->option('format') === 'json') {
            $this->json(['revoked' => $revoked]);
        } else {
            $this->info("Revoked {$revoked} personal access token(s).");
        }

        return self::SUCCESS;
    }
}
