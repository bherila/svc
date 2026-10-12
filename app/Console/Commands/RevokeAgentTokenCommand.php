<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use Psr\Log\LoggerInterface;

/**
 * Revoke an exact person's named agent access tokens issued by
 * `svc:auth:issue-agent-token`, and only those. Revocation is checked on every request, so
 * the token stops working at once rather than at its expiry.
 */
final class RevokeAgentTokenCommand extends Command
{
    protected $signature = 'svc:auth:revoke-agent-token
        {user_public_id : Exact user public UUID}
        {name : Exact agent token name}
        {--all : Revoke every live token with this name when more than one exists}';

    protected $description = 'Revoke an exact user\'s named agent (REST or MCP) access token';

    public function handle(AccessTokenRepository $repository, LoggerInterface $log): int
    {
        $publicId = trim((string) $this->argument('user_public_id'));
        $name = trim((string) $this->argument('name'));

        if (! Str::isUuid($publicId) || $name === '') {
            $this->error('An exact user public UUID and non-empty token name are required.');

            return self::INVALID;
        }

        $user = User::query()->where('public_id', $publicId)->first();
        if ($user === null) {
            $this->error('No user exists for that public UUID.');

            return self::FAILURE;
        }

        $ids = Passport::token()->newQuery()
            ->where('user_id', $user->getKey())
            // Exact equality on the reserved prefix, never a LIKE.
            ->where('name', IssueAgentTokenCommand::NAME_PREFIX.$name)
            ->where('revoked', false)
            // An expired token authenticates nothing, so it is neither a
            // duplicate of a live one nor something to report revoking.
            ->where('expires_at', '>', now())
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($ids === []) {
            $this->error('No live agent token exists with that exact name.');

            return self::FAILURE;
        }
        if (count($ids) > 1 && ! $this->option('all')) {
            $this->error(count($ids).' live agent tokens share that name; rerun with --all to revoke all of them.');

            return self::FAILURE;
        }

        // Through the repository, so revocation listeners hear of each one.
        foreach ($ids as $id) {
            $repository->revokeAccessToken($id);
        }

        $log->info('agent_token.revoked', [
            'actor' => 'console',
            'subject_id' => $publicId,
            'name' => $name,
            'revoked' => count($ids),
            'credential_fingerprints' => array_map(static fn (string $id): string => hash('sha256', $id), $ids),
        ]);

        $this->line((string) json_encode(['revoked' => count($ids)], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
