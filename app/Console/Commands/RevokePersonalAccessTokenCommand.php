<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RevokePersonalAccessTokenCommand extends Command
{
    protected $signature = 'svc:auth:revoke-token
        {user_public_id : Exact user public UUID}
        {name : Exact personal access token name}
        {--all : Revoke every token with this name when more than one exists}';

    protected $description = 'Revoke an exact user\'s named Sanctum personal access token';

    public function handle(): int
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

        $tokens = $user->tokens()->where('name', $name);
        $count = (clone $tokens)->count();
        if ($count === 0) {
            $this->error('No token exists with that exact name.');

            return self::FAILURE;
        }
        if ($count > 1 && ! $this->option('all')) {
            $this->error("{$count} tokens share that name; rerun with --all to revoke all of them.");

            return self::FAILURE;
        }

        $deleted = $tokens->delete();
        $this->info("Revoked {$deleted} personal access token(s).");

        return self::SUCCESS;
    }
}
