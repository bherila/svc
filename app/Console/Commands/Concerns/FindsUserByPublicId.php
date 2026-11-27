<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use App\Support\Auth\OperatorActorIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The commands' one way to name a person: the exact public UUID, never an internal id, an
 * email address or a partial match.
 *
 * @phpstan-require-extends Command
 */
trait FindsUserByPublicId
{
    /**
     * The person a command acts as (`--actor`, `--user`), or the exit code already explained
     * to the operator: INVALID for something that is not a UUID, FAILURE for no such person,
     * and FAILURE for an account the command may not act as - under provider identity
     * enforcement, a bound account whose identity has ended, or whose identity the provider
     * cannot confirm right now ({@see OperatorActorIdentity}).
     */
    private function userByPublicId(string $value): User|int
    {
        $user = $this->credentialOwnerByPublicId($value);
        if (is_int($user)) {
            return $user;
        }

        $refusal = app(OperatorActorIdentity::class)->refusal($user);
        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        return $user;
    }

    /**
     * The person whose credentials a command issues or revokes, never checked against the
     * identity provider: revoking must work for an ended identity and during an outage, and
     * issuing makes the provider check itself. Only the credential commands use this.
     */
    private function credentialOwnerByPublicId(string $value): User|int
    {
        $publicId = trim($value);
        if (! Str::isUuid($publicId)) {
            $this->error('The user_public_id must be an exact public UUID.');

            return self::INVALID;
        }

        $user = User::query()->where('public_id', $publicId)->first();
        if ($user === null) {
            $this->error('No user exists for that public UUID.');

            return self::FAILURE;
        }

        return $user;
    }
}
